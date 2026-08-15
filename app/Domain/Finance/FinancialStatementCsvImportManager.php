<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use App\Models\FinancialAccount;
use App\Models\FinancialExternalMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class FinancialStatementCsvImportManager
{
    private const DRAFT_DIRECTORY =
        'import-previews/financial-statements';

    private const DRAFT_TTL_MINUTES = 60;

    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly FinancialStatementCsvPreviewer $previewer,
        private readonly FinancialStatementXlsxPreviewer $xlsxPreviewer,
        private readonly ExternalFinancialMovementRecorder $recorder,
        private readonly AuditRecorder $audit
    ) {
    }

    /**
     * @return array{
     *     preview: FinancialStatementImportPreview,
     *     token: string
     * }
     */
    public function stage(
        FinancialAccount $account,
        string $path,
        string $originalName,
        User $actor,
        ?FinancialStatementCsvMapping $mapping = null
    ): array {
        $mapping ??=
            FinancialStatementCsvMapping::canonical();

        $preview = $this->previewer->preview(
            $account,
            $path,
            $originalName,
            $actor,
            $mapping
        );

        return $this->stagePreview(
            $account,
            $preview,
            $actor,
            FinancialMovementSource::Csv,
            $mapping
        );
    }

    /**
     * @return array{
     *     preview: FinancialStatementImportPreview,
     *     token: string
     * }
     */
    public function stageXlsx(
        FinancialAccount $account,
        string $path,
        string $originalName,
        User $actor,
        ?FinancialStatementCsvMapping $mapping = null
    ): array {
        $mapping ??=
            FinancialStatementCsvMapping::canonical();

        $preview = $this->xlsxPreviewer->preview(
            $account,
            $path,
            $originalName,
            $actor,
            $mapping
        );

        return $this->stagePreview(
            $account,
            $preview,
            $actor,
            FinancialMovementSource::Xlsx,
            $mapping
        );
    }

    /**
     * @return array{
     *     preview: FinancialStatementImportPreview,
     *     token: string
     * }
     */
    private function stagePreview(
        FinancialAccount $account,
        FinancialStatementImportPreview $preview,
        User $actor,
        FinancialMovementSource $source,
        FinancialStatementCsvMapping $mapping
    ): array {
        $this->cleanupExpiredDrafts();

        if (
            ! in_array(
                $source,
                [
                    FinancialMovementSource::Csv,
                    FinancialMovementSource::Xlsx,
                ],
                true
            )
        ) {
            throw new DomainException(
                'La fuente de importación tabular no está admitida.'
            );
        }

        $organizationId =
            $this->organizationId($actor);

        $token = (string) Str::uuid();

        $rows = array_map(
            static fn (
                FinancialStatementImportPreviewRow $row
            ): array => [
                'line_number' => $row->lineNumber,
                'source_key' => $row->sourceKey,
                'fingerprint' => $row->fingerprint,
                'occurred_at' =>
                    $row->occurredAt->toIso8601String(),
                'direction' => $row->direction->value,
                'currency_code' => $row->currencyCode,
                'gross_amount_minor' =>
                    $row->grossAmountMinor,
                'fee_amount_minor' =>
                    $row->feeAmountMinor,
                'withholding_amount_minor' =>
                    $row->withholdingAmountMinor,
                'net_amount_minor' =>
                    $row->netAmountMinor,
                'external_operation_id' =>
                    $row->externalOperationId,
                'reference' => $row->reference,
            ],
            $preview->rows
        );

        $this->writeDraft($token, [
            'version' => 3,
            'source' => $source->value,
            'organization_id' => $organizationId,
            'user_id' => (int) $actor->getKey(),
            'created_at' =>
                CarbonImmutable::now()->toIso8601String(),
            'account_id' =>
                (int) $account->getKey(),
            'account_public_id' =>
                $preview->accountPublicId,
            'currency_code' =>
                $preview->currencyCode,
            'file_name' =>
                $preview->fileName,
            'file_sha256' =>
                $preview->fileSha256,
            'mapping_fingerprint' =>
                $mapping->fingerprint(),
            'row_count' =>
                $preview->rowCount(),
            'rows' => $rows,
        ]);

        return [
            'preview' => $preview,
            'token' => $token,
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     created: int,
     *     deduplicated: int
     * }
     */
    public function commit(
        string $token,
        User $actor
    ): array {
        $this->cleanupExpiredDrafts();

        $organizationId = $this->organizationId($actor);
        $draft = $this->readDraft($token);

        $version =
            (int) ($draft['version'] ?? 0);

        if (
            ! in_array($version, [1, 2, 3], true)
            || (int) ($draft['organization_id'] ?? 0)
                !== $organizationId
            || (int) ($draft['user_id'] ?? 0)
                !== (int) $actor->getKey()
        ) {
            throw new DomainException(
                'La previsualización no está disponible para este usuario y organización.'
            );
        }

        $createdAtRaw = $draft['created_at'] ?? null;

        if (! is_string($createdAtRaw)) {
            $this->deleteDraft($token);

            throw new DomainException(
                'La previsualización tiene una fecha inválida.'
            );
        }

        try {
            $createdAt = CarbonImmutable::parse(
                $createdAtRaw
            );
        } catch (Throwable $exception) {
            $this->deleteDraft($token);

            throw new DomainException(
                'La previsualización tiene una fecha inválida.',
                previous: $exception
            );
        }

        $now = CarbonImmutable::now();

        if (
            $createdAt->gt($now->addMinute())
            || $createdAt->lt(
                $now->subMinutes(
                    self::DRAFT_TTL_MINUTES
                )
            )
        ) {
            $this->deleteDraft($token);

            throw new DomainException(
                'La previsualización venció. Volvé a cargar el extracto.'
            );
        }

        $account = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->whereKey((int) ($draft['account_id'] ?? 0))
            ->where('active', true)
            ->first();

        if (! $account) {
            throw new DomainException(
                'La cuenta financiera ya no está disponible.'
            );
        }

        if (
            in_array(
                $account->type,
                [
                    FinancialAccountType::CashBox,
                    FinancialAccountType::CashReserve,
                ],
                true
            )
        ) {
            throw new DomainException(
                'Una cuenta de efectivo no admite importación de extractos externos.'
            );
        }

        if (
            (string) ($draft['account_public_id'] ?? '')
                !== (string) $account->public_id
            || (string) ($draft['currency_code'] ?? '')
                !== (string) $account->currency_code
        ) {
            throw new DomainException(
                'La cuenta financiera cambió desde la previsualización.'
            );
        }

        $source =
            $version < 3
                ? FinancialMovementSource::Csv
                : FinancialMovementSource::tryFrom(
                    (string) ($draft['source'] ?? '')
                );

        if (
            ! in_array(
                $source,
                [
                    FinancialMovementSource::Csv,
                    FinancialMovementSource::Xlsx,
                ],
                true
            )
        ) {
            throw new DomainException(
                'La fuente de la previsualización privada no es válida.'
            );
        }

        $rows = $this->validateDraftRows(
            $draft,
            $account,
            $source
        );

        $fileSha = (string) $draft['file_sha256'];

        $mappingFingerprint =
            $version === 1
                ? FinancialStatementCsvMapping::canonical()
                    ->fingerprint()
                : (string) (
                    $draft['mapping_fingerprint']
                    ?? ''
                );

        if (
            preg_match(
                '/^[a-f0-9]{64}$/D',
                $mappingFingerprint
            ) !== 1
        ) {
            throw new DomainException(
                'La identidad del mapeo de la previsualización es inválida.'
            );
        }

        $result = DB::transaction(function () use (
            $organizationId,
            $account,
            $rows,
            $fileSha,
            $mappingFingerprint,
            $source,
            $actor
        ): array {
            $lockedAccount = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey($account->getKey())
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $lockedAccount) {
                throw new DomainException(
                    'La cuenta financiera dejó de estar disponible antes del commit.'
                );
            }

            if (
                $lockedAccount->currency_code
                    !== $account->currency_code
                || in_array(
                    $lockedAccount->type,
                    [
                        FinancialAccountType::CashBox,
                        FinancialAccountType::CashReserve,
                    ],
                    true
                )
            ) {
                throw new DomainException(
                    'La cuenta financiera cambió antes del commit.'
                );
            }

            $created = 0;
            $deduplicated = 0;

            foreach ($rows as $row) {
                $externalOperationId =
                    $row['external_operation_id'];

                if ($externalOperationId !== null) {
                    $existingByOperation =
                        FinancialExternalMovement::query()
                            ->forOrganization(
                                $organizationId
                            )
                            ->where(
                                'financial_account_id',
                                $lockedAccount->getKey()
                            )
                            ->where(
                                'external_operation_id',
                                $externalOperationId
                            )
                            ->where(
                                'status',
                                FinancialMovementStatus::Posted->value
                            )
                            ->lockForUpdate()
                            ->get();

                    if ($existingByOperation->count() > 1) {
                        throw new DomainException(
                            'La operación externa '
                            .$externalOperationId
                            .' posee más de un hecho posted y requiere revisión manual.'
                        );
                    }

                    $existing = $existingByOperation
                        ->first();

                    if ($existing) {
                        if (
                            ! $this->sameFinancialObservation(
                                $existing,
                                $row
                            )
                        ) {
                            throw new DomainException(
                                'La operación externa '
                                .$externalOperationId
                                .' ya existe con contenido financiero diferente.'
                            );
                        }

                        $deduplicated++;

                        continue;
                    }
                }

                $existingDelivery =
                    FinancialExternalMovement::query()
                        ->forOrganization($organizationId)
                        ->where(
                            'financial_account_id',
                            $lockedAccount->getKey()
                        )
                        ->where(
                            'source',
                            $source->value
                        )
                        ->where(
                            'source_key',
                            $row['source_key']
                        )
                        ->lockForUpdate()
                        ->first();

                $movement = $this->recorder->record(
                    $lockedAccount,
                    new ExternalFinancialMovementData(
                        source:
                            $source,
                        sourceKey: $row['source_key'],
                        direction: $row['direction'],
                        status:
                            FinancialMovementStatus::Posted,
                        currencyCode:
                            $row['currency_code'],
                        grossAmountMinor:
                            $row['gross_amount_minor'],
                        netAmountMinor:
                            $row['net_amount_minor'],
                        feeAmountMinor:
                            $row['fee_amount_minor'],
                        withholdingAmountMinor:
                            $row[
                                'withholding_amount_minor'
                            ],
                        externalOperationId:
                            $externalOperationId,
                        rawReference: $row['reference'],
                        occurredAt: $row['occurred_at']
                    ),
                    $actor
                );

                if ($existingDelivery) {
                    $deduplicated++;
                } else {
                    $created++;
                }

                if (
                    $movement->status
                        !== FinancialMovementStatus::Posted
                    || $movement->source
                        !== $source
                ) {
                    throw new DomainException(
                        'El recorder devolvió un movimiento incompatible con el commit del extracto.'
                    );
                }
            }

            $this->audit->record(
                $lockedAccount,
                'financial_statement_'.$source->value.'_import_committed',
                null,
                [
                    'source' =>
                        $source->value,
                    'file_sha256' => $fileSha,
                    'mapping_fingerprint' =>
                        $mappingFingerprint,
                    'row_count' => count($rows),
                    'created_count' => $created,
                    'deduplicated_count' =>
                        $deduplicated,
                ]
            );

            return [
                'total' => count($rows),
                'created' => $created,
                'deduplicated' => $deduplicated,
            ];
        }, 3);

        $this->deleteDraft($token);

        return $result;
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<array{
     *     line_number: int,
     *     source_key: string,
     *     fingerprint: string,
     *     occurred_at: CarbonImmutable,
     *     direction: FinancialMovementDirection,
     *     currency_code: string,
     *     gross_amount_minor: int,
     *     fee_amount_minor: int,
     *     withholding_amount_minor: int,
     *     net_amount_minor: int,
     *     external_operation_id: ?string,
     *     reference: ?string
     * }>
     */
    private function validateDraftRows(
        array $draft,
        FinancialAccount $account,
        FinancialMovementSource $source
    ): array {
        $fileSha = $draft['file_sha256'] ?? null;
        $rawRows = $draft['rows'] ?? null;
        $rowCount = $draft['row_count'] ?? null;

        if (
            ! is_string($fileSha)
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                $fileSha
            ) !== 1
            || ! is_array($rawRows)
            || $rawRows === []
            || count($rawRows) > 1000
            || ! is_int($rowCount)
            || $rowCount !== count($rawRows)
        ) {
            throw new DomainException(
                'La previsualización privada está incompleta o dañada.'
            );
        }

        $rows = [];
        $seenExternalIds = [];

        foreach ($rawRows as $rawRow) {
            if (! is_array($rawRow)) {
                throw new DomainException(
                    'La previsualización contiene una fila inválida.'
                );
            }

            $lineNumber =
                $rawRow['line_number'] ?? null;
            $sourceKey = $rawRow['source_key'] ?? null;
            $fingerprint =
                $rawRow['fingerprint'] ?? null;
            $occurredAtRaw =
                $rawRow['occurred_at'] ?? null;
            $directionRaw =
                $rawRow['direction'] ?? null;
            $currency =
                $rawRow['currency_code'] ?? null;

            if (
                ! is_int($lineNumber)
                || $lineNumber < 2
                || ! is_string($sourceKey)
                || $sourceKey !==
                    $source->value
                    .':'.$fileSha
                    .':'.$lineNumber
                || ! is_string($fingerprint)
                || preg_match(
                    '/^[a-f0-9]{64}$/D',
                    $fingerprint
                ) !== 1
                || ! is_string($occurredAtRaw)
                || ! is_string($directionRaw)
                || ! is_string($currency)
                || $currency
                    !== $account->currency_code
            ) {
                throw new DomainException(
                    'La identidad de una fila cambió desde la previsualización.'
                );
            }

            $direction =
                FinancialMovementDirection::tryFrom(
                    $directionRaw
                );

            if (! $direction) {
                throw new DomainException(
                    'La dirección de una fila ya no es válida.'
                );
            }

            try {
                $occurredAt = CarbonImmutable::parse(
                    $occurredAtRaw
                )->utc();
            } catch (Throwable $exception) {
                throw new DomainException(
                    'La fecha de una fila ya no es válida.',
                    previous: $exception
                );
            }

            $gross = $this->draftInt(
                $rawRow,
                'gross_amount_minor'
            );
            $fee = $this->draftInt(
                $rawRow,
                'fee_amount_minor'
            );
            $withholding = $this->draftInt(
                $rawRow,
                'withholding_amount_minor'
            );
            $net = $this->draftInt(
                $rawRow,
                'net_amount_minor'
            );

            if (
                $gross <= 0
                || $fee < 0
                || $withholding < 0
                || $net < 0
                || $net + $fee + $withholding
                    !== $gross
            ) {
                throw new DomainException(
                    'La matemática financiera de una fila cambió desde la previsualización.'
                );
            }

            $externalOperationId =
                $this->draftNullableText(
                    $rawRow,
                    'external_operation_id',
                    191
                );

            $reference = $this->draftNullableText(
                $rawRow,
                'reference',
                500
            );

            if ($externalOperationId !== null) {
                if (
                    isset(
                        $seenExternalIds[
                            $externalOperationId
                        ]
                    )
                ) {
                    throw new DomainException(
                        'La previsualización contiene una operación externa duplicada.'
                    );
                }

                $seenExternalIds[
                    $externalOperationId
                ] = true;
            }

            $canonical = [
                'occurred_at' =>
                    $occurredAt->toIso8601String(),
                'direction' => $direction->value,
                'currency_code' => $currency,
                'gross_amount_minor' => $gross,
                'fee_amount_minor' => $fee,
                'withholding_amount_minor' =>
                    $withholding,
                'net_amount_minor' => $net,
                'external_operation_id' =>
                    $externalOperationId,
                'reference' => $reference,
            ];

            $expectedFingerprint = hash(
                'sha256',
                json_encode(
                    $canonical,
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                )
            );

            if (
                ! hash_equals(
                    $fingerprint,
                    $expectedFingerprint
                )
            ) {
                throw new DomainException(
                    'La huella financiera de una fila cambió desde la previsualización.'
                );
            }

            $rows[] = [
                'line_number' => $lineNumber,
                'source_key' => $sourceKey,
                'fingerprint' => $fingerprint,
                'occurred_at' => $occurredAt,
                'direction' => $direction,
                'currency_code' => $currency,
                'gross_amount_minor' => $gross,
                'fee_amount_minor' => $fee,
                'withholding_amount_minor' =>
                    $withholding,
                'net_amount_minor' => $net,
                'external_operation_id' =>
                    $externalOperationId,
                'reference' => $reference,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sameFinancialObservation(
        FinancialExternalMovement $existing,
        array $row
    ): bool {
        return $existing->direction
                === $row['direction']
            && $existing->status
                === FinancialMovementStatus::Posted
            && $existing->currency_code
                === $row['currency_code']
            && (int) $existing->gross_amount_minor
                === $row['gross_amount_minor']
            && (int) $existing->fee_amount_minor
                === $row['fee_amount_minor']
            && (int) $existing->withholding_amount_minor
                === $row['withholding_amount_minor']
            && (int) $existing->net_amount_minor
                === $row['net_amount_minor'];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function draftInt(
        array $row,
        string $key
    ): int {
        $value = $row[$key] ?? null;

        if (! is_int($value)) {
            throw new DomainException(
                'La previsualización contiene un importe inválido.'
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function draftNullableText(
        array $row,
        string $key,
        int $maxLength
    ): ?string {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (
            ! is_string($value)
            || mb_strlen($value) > $maxLength
        ) {
            throw new DomainException(
                'La previsualización contiene texto inválido.'
            );
        }

        return $value;
    }

    private function organizationId(User $actor): int
    {
        $organizationId =
            $this->currentOrganization->id($actor);

        $role =
            $this->currentOrganization->roleFor($actor);

        if (
            ! (
                $role
                    ?->canReviewFinancialReconciliation()
                ?? false
            )
        ) {
            throw new DomainException(
                'No posee permiso para importar extractos financieros.'
            );
        }

        return $organizationId;
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function writeDraft(
        string $token,
        array $draft
    ): void {
        try {
            $json = json_encode(
                $draft,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
            );

            $encrypted = Crypt::encryptString(
                $json
            );
        } catch (Throwable $exception) {
            throw new DomainException(
                'No se pudo proteger la previsualización privada.',
                previous: $exception
            );
        }

        if (
            ! Storage::disk('local')->put(
                $this->draftPath($token),
                $encrypted
            )
        ) {
            throw new DomainException(
                'No se pudo guardar la previsualización privada.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readDraft(
        string $token
    ): array {
        $path = $this->draftPath($token);
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            throw new DomainException(
                'La previsualización no existe o ya fue utilizada.'
            );
        }

        try {
            $json = Crypt::decryptString(
                $disk->get($path)
            );

            $decoded = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            $this->deleteDraft($token);

            throw new DomainException(
                'La previsualización privada está dañada o no es auténtica.',
                previous: $exception
            );
        }

        if (! is_array($decoded)) {
            $this->deleteDraft($token);

            throw new DomainException(
                'La previsualización privada tiene un formato inválido.'
            );
        }

        return $decoded;
    }

    private function cleanupExpiredDrafts(): void
    {
        $disk = Storage::disk('local');
        $cutoff = CarbonImmutable::now()
            ->subHours(2)
            ->getTimestamp();

        foreach (
            $disk->files(self::DRAFT_DIRECTORY)
            as $file
        ) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }

    private function deleteDraft(
        string $token
    ): void {
        Storage::disk('local')->delete(
            $this->draftPath($token)
        );
    }

    private function draftPath(
        string $token
    ): string {
        if (! Str::isUuid($token)) {
            throw new DomainException(
                'El token de importación no es válido.'
            );
        }

        return self::DRAFT_DIRECTORY
            .'/'.$token.'.json';
    }
}
