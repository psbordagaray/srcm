<?php

namespace App\Domain\Finance;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementDirection;
use App\Models\FinancialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;

final class FinancialStatementCsvPreviewer
{
    private const MAX_BYTES = 2097152;
    private const MAX_ROWS = 1000;

    /** @var list<string> */
    private const HEADER = [
        'occurred_at',
        'direction',
        'gross_amount',
        'fee_amount',
        'withholding_amount',
        'net_amount',
        'external_operation_id',
        'reference',
    ];

    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    public function preview(
        FinancialAccount $account,
        string $path,
        string $originalName,
        User $actor
    ): FinancialStatementImportPreview {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canReviewFinancialReconciliation() ?? false)) {
            throw new DomainException(
                'No posee permiso para previsualizar extractos financieros.'
            );
        }

        $scopedAccount = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->whereKey($account->getKey())
            ->first();

        if (! $scopedAccount) {
            throw new DomainException(
                'La cuenta financiera no pertenece a la organización activa.'
            );
        }

        if (! $scopedAccount->active) {
            throw new DomainException(
                'La cuenta financiera debe estar activa para previsualizar un extracto.'
            );
        }

        if (
            in_array(
                $scopedAccount->type,
                [
                    FinancialAccountType::CashBox,
                    FinancialAccountType::CashReserve,
                ],
                true
            )
        ) {
            throw new DomainException(
                'Las cuentas de efectivo no admiten extractos financieros externos.'
            );
        }

        if (
            strtolower(pathinfo($originalName, PATHINFO_EXTENSION))
                !== 'csv'
        ) {
            throw new DomainException(
                'P7.1 sólo admite archivos CSV en su contrato canónico.'
            );
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new DomainException(
                'El archivo CSV no está disponible para lectura.'
            );
        }

        $size = filesize($path);

        if (
            $size === false
            || $size <= 0
            || $size > self::MAX_BYTES
        ) {
            throw new DomainException(
                'El archivo CSV debe tener contenido y no superar 2 MiB.'
            );
        }

        $fileSha = hash_file('sha256', $path);

        if (! is_string($fileSha) || $fileSha === '') {
            throw new DomainException(
                'No se pudo calcular la identidad del archivo CSV.'
            );
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new DomainException(
                'No se pudo abrir el archivo CSV.'
            );
        }

        try {
            $header = fgetcsv(
                $handle,
                0,
                ',',
                '"',
                ''
            );

            if (! is_array($header)) {
                throw new DomainException(
                    'El CSV no contiene una cabecera válida.'
                );
            }

            $header = array_map(
                static fn ($value): string =>
                    trim((string) $value),
                $header
            );

            if (isset($header[0])) {
                $header[0] = preg_replace(
                    '/^\xEF\xBB\xBF/',
                    '',
                    $header[0]
                ) ?? $header[0];
            }

            if ($header !== self::HEADER) {
                throw new DomainException(
                    'La cabecera CSV no coincide con el contrato canónico P7.1.'
                );
            }

            $rows = [];
            $lineNumber = 1;
            $seenExternalIds = [];

            while (
                ($raw = fgetcsv(
                    $handle,
                    0,
                    ',',
                    '"',
                    ''
                )) !== false
            ) {
                $lineNumber++;

                if ($this->blankRow($raw)) {
                    continue;
                }

                if (count($raw) !== count(self::HEADER)) {
                    throw new DomainException(
                        "La línea {$lineNumber} no posee 8 columnas."
                    );
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw new DomainException(
                        'El CSV supera el máximo de 1000 movimientos por vista previa.'
                    );
                }

                $row = array_combine(
                    self::HEADER,
                    array_map(
                        static fn ($value): string =>
                            trim((string) $value),
                        $raw
                    )
                );

                if (! is_array($row)) {
                    throw new DomainException(
                        "No se pudo normalizar la línea {$lineNumber}."
                    );
                }

                $occurredAt = $this->parseOccurredAt(
                    $row['occurred_at'],
                    $lineNumber
                );

                $direction = match ($row['direction']) {
                    FinancialMovementDirection::Credit->value =>
                        FinancialMovementDirection::Credit,
                    FinancialMovementDirection::Debit->value =>
                        FinancialMovementDirection::Debit,
                    default => throw new DomainException(
                        "La línea {$lineNumber} debe usar direction=credit o debit."
                    ),
                };

                $gross = $this->parseAmountMinor(
                    $row['gross_amount'],
                    'gross_amount',
                    $lineNumber
                );

                $fee = $this->parseAmountMinor(
                    $row['fee_amount'],
                    'fee_amount',
                    $lineNumber
                );

                $withholding = $this->parseAmountMinor(
                    $row['withholding_amount'],
                    'withholding_amount',
                    $lineNumber
                );

                $net = $this->parseAmountMinor(
                    $row['net_amount'],
                    'net_amount',
                    $lineNumber
                );

                if ($gross <= 0) {
                    throw new DomainException(
                        "La línea {$lineNumber} requiere gross_amount mayor que cero."
                    );
                }

                if ($net + $fee + $withholding !== $gross) {
                    throw new DomainException(
                        "La línea {$lineNumber} no cumple gross = net + fee + withholding."
                    );
                }

                $externalId = $this->nullableText(
                    $row['external_operation_id'],
                    191,
                    'external_operation_id',
                    $lineNumber
                );

                $reference = $this->nullableText(
                    $row['reference'],
                    500,
                    'reference',
                    $lineNumber
                );

                if ($externalId !== null) {
                    if (isset($seenExternalIds[$externalId])) {
                        throw new DomainException(
                            "La operación externa {$externalId} está duplicada dentro del CSV."
                        );
                    }

                    $seenExternalIds[$externalId] = true;
                }

                $canonical = [
                    'occurred_at' =>
                        $occurredAt->toIso8601String(),
                    'direction' => $direction->value,
                    'currency_code' =>
                        $scopedAccount->currency_code,
                    'gross_amount_minor' => $gross,
                    'fee_amount_minor' => $fee,
                    'withholding_amount_minor' =>
                        $withholding,
                    'net_amount_minor' => $net,
                    'external_operation_id' => $externalId,
                    'reference' => $reference,
                ];

                $fingerprint = hash(
                    'sha256',
                    json_encode(
                        $canonical,
                        JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                            | JSON_THROW_ON_ERROR
                    )
                );

                $rows[] = new FinancialStatementImportPreviewRow(
                    lineNumber: $lineNumber,
                    sourceKey:
                        'csv:'.$fileSha.':'.$lineNumber,
                    fingerprint: $fingerprint,
                    occurredAt: $occurredAt,
                    direction: $direction,
                    currencyCode:
                        $scopedAccount->currency_code,
                    grossAmountMinor: $gross,
                    feeAmountMinor: $fee,
                    withholdingAmountMinor: $withholding,
                    netAmountMinor: $net,
                    externalOperationId: $externalId,
                    reference: $reference
                );
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new DomainException(
                'El CSV no contiene movimientos para previsualizar.'
            );
        }

        return new FinancialStatementImportPreview(
            accountPublicId:
                (string) $scopedAccount->public_id,
            accountName: (string) $scopedAccount->name,
            currencyCode:
                (string) $scopedAccount->currency_code,
            fileName: basename($originalName),
            fileSha256: $fileSha,
            rows: $rows
        );
    }

    /** @param array<int, string|null> $row */
    private function blankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseOccurredAt(
        string $value,
        int $lineNumber
    ): CarbonImmutable {
        $date = DateTimeImmutable::createFromFormat(
            DateTimeInterface::ATOM,
            $value
        );

        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || (
                is_array($errors)
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
        ) {
            throw new DomainException(
                "La línea {$lineNumber} requiere occurred_at ISO 8601, por ejemplo 2026-08-15T10:30:00-03:00."
            );
        }

        return CarbonImmutable::instance($date)->utc();
    }

    private function parseAmountMinor(
        string $value,
        string $field,
        int $lineNumber
    ): int {
        if (
            preg_match(
                '/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/D',
                $value,
                $matches
            ) !== 1
        ) {
            throw new DomainException(
                "La línea {$lineNumber} contiene {$field} inválido; use decimal con punto y hasta 2 decimales."
            );
        }

        $whole = (int) $matches[1];
        $fraction = $matches[2] ?? '';
        $fraction = str_pad($fraction, 2, '0');

        if ($whole > intdiv(PHP_INT_MAX, 100)) {
            throw new DomainException(
                "La línea {$lineNumber} contiene {$field} fuera de rango."
            );
        }

        return ($whole * 100) + (int) $fraction;
    }

    private function nullableText(
        string $value,
        int $maxLength,
        string $field,
        int $lineNumber
    ): ?string {
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new DomainException(
                "La línea {$lineNumber} supera la longitud admitida para {$field}."
            );
        }

        return $value;
    }
}
