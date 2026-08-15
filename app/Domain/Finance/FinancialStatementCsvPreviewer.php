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
use DateTimeZone;
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
        User $actor,
        ?FinancialStatementCsvMapping $mapping = null
    ): FinancialStatementImportPreview {
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
            strtolower(
                pathinfo(
                    $originalName,
                    PATHINFO_EXTENSION
                )
            ) !== 'csv'
        ) {
            throw new DomainException(
                'P7.3 sólo admite archivos CSV; XLSX se incorporará en un corte posterior.'
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

        $mapping ??=
            FinancialStatementCsvMapping::canonical();

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
                $mapping->delimiter,
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

            if (
                count($header)
                !== count(array_unique($header))
            ) {
                throw new DomainException(
                    'La cabecera CSV contiene nombres de columna duplicados.'
                );
            }

            if (
                $mapping->canonical
                && $header !== self::HEADER
            ) {
                throw new DomainException(
                    'La cabecera CSV no coincide con el contrato canónico P7.1.'
                );
            }

            if (! $mapping->canonical) {
                foreach (
                    $mapping->sourceHeaders()
                    as $requiredHeader
                ) {
                    if (
                        ! in_array(
                            $requiredHeader,
                            $header,
                            true
                        )
                    ) {
                        throw new DomainException(
                            'La columna configurada "'
                            .$requiredHeader
                            .'" no existe en el CSV.'
                        );
                    }
                }
            }

            $rows = [];
            $lineNumber = 1;
            $seenExternalIds = [];

            while (
                ($raw = fgetcsv(
                    $handle,
                    0,
                    $mapping->delimiter,
                    '"',
                    ''
                )) !== false
            ) {
                $lineNumber++;

                if ($this->blankRow($raw)) {
                    continue;
                }

                if (count($raw) !== count($header)) {
                    throw new DomainException(
                        "La línea {$lineNumber} no coincide con la cantidad de columnas de la cabecera."
                    );
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw new DomainException(
                        'El CSV supera el máximo de 1000 movimientos por vista previa.'
                    );
                }

                $sourceRow = array_combine(
                    $header,
                    array_map(
                        static fn ($value): string =>
                            trim((string) $value),
                        $raw
                    )
                );

                if (! is_array($sourceRow)) {
                    throw new DomainException(
                        "No se pudo normalizar la línea {$lineNumber}."
                    );
                }

                $occurredAt = $this->parseOccurredAt(
                    $this->requiredMappedValue(
                        $sourceRow,
                        $mapping->occurredAtHeader,
                        $lineNumber
                    ),
                    $lineNumber,
                    $mapping
                );

                $directionRaw =
                    $this->requiredMappedValue(
                        $sourceRow,
                        $mapping->directionHeader,
                        $lineNumber
                    );

                $direction = match ($directionRaw) {
                    $mapping->creditValue =>
                        FinancialMovementDirection::Credit,
                    $mapping->debitValue =>
                        FinancialMovementDirection::Debit,
                    default => throw new DomainException(
                        "La línea {$lineNumber} contiene una dirección no reconocida por el mapeo."
                    ),
                };

                $gross = $this->parseAmountMinor(
                    $this->requiredMappedValue(
                        $sourceRow,
                        $mapping->grossAmountHeader,
                        $lineNumber
                    ),
                    'gross_amount',
                    $lineNumber,
                    $mapping->decimalSeparator
                );

                $fee = $this->parseAmountMinor(
                    $this->optionalMappedValue(
                        $sourceRow,
                        $mapping->feeAmountHeader,
                        '0'
                    ),
                    'fee_amount',
                    $lineNumber,
                    $mapping->decimalSeparator
                );

                $withholding =
                    $this->parseAmountMinor(
                        $this->optionalMappedValue(
                            $sourceRow,
                            $mapping
                                ->withholdingAmountHeader,
                            '0'
                        ),
                        'withholding_amount',
                        $lineNumber,
                        $mapping->decimalSeparator
                    );

                $net = $this->parseAmountMinor(
                    $this->requiredMappedValue(
                        $sourceRow,
                        $mapping->netAmountHeader,
                        $lineNumber
                    ),
                    'net_amount',
                    $lineNumber,
                    $mapping->decimalSeparator
                );

                if ($gross <= 0) {
                    throw new DomainException(
                        "La línea {$lineNumber} requiere gross_amount mayor que cero."
                    );
                }

                if (
                    $net + $fee + $withholding
                    !== $gross
                ) {
                    throw new DomainException(
                        "La línea {$lineNumber} no cumple gross = net + fee + withholding."
                    );
                }

                $externalId = $this->nullableText(
                    $this->optionalMappedValue(
                        $sourceRow,
                        $mapping
                            ->externalOperationIdHeader,
                        ''
                    ),
                    191,
                    'external_operation_id',
                    $lineNumber
                );

                $reference = $this->nullableText(
                    $this->optionalMappedValue(
                        $sourceRow,
                        $mapping->referenceHeader,
                        ''
                    ),
                    500,
                    'reference',
                    $lineNumber
                );

                if ($externalId !== null) {
                    if (
                        isset(
                            $seenExternalIds[
                                $externalId
                            ]
                        )
                    ) {
                        throw new DomainException(
                            "La operación externa {$externalId} está duplicada dentro del CSV."
                        );
                    }

                    $seenExternalIds[
                        $externalId
                    ] = true;
                }

                $canonical = [
                    'occurred_at' =>
                        $occurredAt
                            ->toIso8601String(),
                    'direction' =>
                        $direction->value,
                    'currency_code' =>
                        $scopedAccount->currency_code,
                    'gross_amount_minor' => $gross,
                    'fee_amount_minor' => $fee,
                    'withholding_amount_minor' =>
                        $withholding,
                    'net_amount_minor' => $net,
                    'external_operation_id' =>
                        $externalId,
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

                $rows[] =
                    new FinancialStatementImportPreviewRow(
                        lineNumber: $lineNumber,
                        sourceKey:
                            'csv:'.$fileSha
                            .':'.$lineNumber,
                        fingerprint: $fingerprint,
                        occurredAt: $occurredAt,
                        direction: $direction,
                        currencyCode:
                            $scopedAccount
                                ->currency_code,
                        grossAmountMinor: $gross,
                        feeAmountMinor: $fee,
                        withholdingAmountMinor:
                            $withholding,
                        netAmountMinor: $net,
                        externalOperationId:
                            $externalId,
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
            accountName:
                (string) $scopedAccount->name,
            currencyCode:
                (string) $scopedAccount
                    ->currency_code,
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

    /**
     * @param array<string, string> $row
     */
    private function requiredMappedValue(
        array $row,
        string $header,
        int $lineNumber
    ): string {
        if (! array_key_exists($header, $row)) {
            throw new DomainException(
                "La línea {$lineNumber} no contiene la columna {$header}."
            );
        }

        $value = trim((string) $row[$header]);

        if ($value === '') {
            throw new DomainException(
                "La línea {$lineNumber} requiere valor en la columna {$header}."
            );
        }

        return $value;
    }

    /**
     * @param array<string, string> $row
     */
    private function optionalMappedValue(
        array $row,
        ?string $header,
        string $default
    ): string {
        if ($header === null) {
            return $default;
        }

        return trim(
            (string) ($row[$header] ?? $default)
        );
    }

    private function parseOccurredAt(
        string $value,
        int $lineNumber,
        FinancialStatementCsvMapping $mapping
    ): CarbonImmutable {
        if ($mapping->dateFormat === 'iso8601') {
            $date =
                DateTimeImmutable::createFromFormat(
                    DateTimeInterface::ATOM,
                    $value
                );
        } else {
            $date =
                DateTimeImmutable::createFromFormat(
                    '!'.$mapping->dateFormat,
                    $value,
                    new DateTimeZone(
                        $mapping->timezone
                    )
                );
        }

        $errors =
            DateTimeImmutable::getLastErrors();

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
                "La línea {$lineNumber} contiene una fecha incompatible con el formato configurado."
            );
        }

        if (
            $mapping->dateFormat !== 'iso8601'
            && $date->format(
                $mapping->dateFormat
            ) !== $value
        ) {
            throw new DomainException(
                "La línea {$lineNumber} contiene una fecha ambigua o no canónica para el formato configurado."
            );
        }

        return CarbonImmutable::instance(
            $date
        )->utc();
    }

    private function parseAmountMinor(
        string $value,
        string $field,
        int $lineNumber,
        string $decimalSeparator
    ): int {
        $separator = preg_quote(
            $decimalSeparator,
            '/'
        );

        if (
            preg_match(
                '/^(0|[1-9]\d*)(?:'
                    .$separator
                    .'(\d{1,2}))?$/D',
                $value,
                $matches
            ) !== 1
        ) {
            throw new DomainException(
                "La línea {$lineNumber} contiene {$field} inválido; no use separadores de miles y respete el separador decimal configurado."
            );
        }

        $whole = (int) $matches[1];
        $fraction = $matches[2] ?? '';
        $fraction = str_pad(
            $fraction,
            2,
            '0'
        );

        if (
            $whole
            > intdiv(PHP_INT_MAX, 100)
        ) {
            throw new DomainException(
                "La línea {$lineNumber} contiene {$field} fuera de rango."
            );
        }

        return ($whole * 100)
            + (int) $fraction;
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
