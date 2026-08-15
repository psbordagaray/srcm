<?php

namespace App\Domain\Finance;

use DateTimeZone;
use DomainException;
use Throwable;

final readonly class FinancialStatementCsvMapping
{
    public function __construct(
        public bool $canonical,
        public string $delimiter,
        public string $decimalSeparator,
        public string $dateFormat,
        public string $timezone,
        public string $occurredAtHeader,
        public string $directionHeader,
        public string $grossAmountHeader,
        public ?string $feeAmountHeader,
        public ?string $withholdingAmountHeader,
        public string $netAmountHeader,
        public ?string $externalOperationIdHeader,
        public ?string $referenceHeader,
        public string $creditValue,
        public string $debitValue
    ) {
    }

    public static function canonical(): self
    {
        return new self(
            canonical: true,
            delimiter: ',',
            decimalSeparator: '.',
            dateFormat: 'iso8601',
            timezone: 'UTC',
            occurredAtHeader: 'occurred_at',
            directionHeader: 'direction',
            grossAmountHeader: 'gross_amount',
            feeAmountHeader: 'fee_amount',
            withholdingAmountHeader:
                'withholding_amount',
            netAmountHeader: 'net_amount',
            externalOperationIdHeader:
                'external_operation_id',
            referenceHeader: 'reference',
            creditValue: 'credit',
            debitValue: 'debit'
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromInput(
        array $input
    ): self {
        $delimiter = match (
            (string) ($input['mapping_delimiter'] ?? '')
        ) {
            'comma' => ',',
            'semicolon' => ';',
            'tab' => "\t",
            default => throw new DomainException(
                'El separador CSV configurado no es válido.'
            ),
        };

        $decimalSeparator = match (
            (string) (
                $input['mapping_decimal_separator']
                ?? ''
            )
        ) {
            'dot' => '.',
            'comma' => ',',
            default => throw new DomainException(
                'El separador decimal configurado no es válido.'
            ),
        };

        $dateFormat = match (
            (string) (
                $input['mapping_date_format']
                ?? ''
            )
        ) {
            'iso8601' => 'iso8601',
            'ymd_his' => 'Y-m-d H:i:s',
            'dmy_his' => 'd/m/Y H:i:s',
            'dmy' => 'd/m/Y',
            default => throw new DomainException(
                'El formato de fecha configurado no es válido.'
            ),
        };

        $timezone = self::requiredText(
            $input,
            'mapping_timezone',
            64,
            'zona horaria'
        );

        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new DomainException(
                'La zona horaria configurada no es válida.',
                previous: $exception
            );
        }

        $mapping = new self(
            canonical: false,
            delimiter: $delimiter,
            decimalSeparator: $decimalSeparator,
            dateFormat: $dateFormat,
            timezone: $timezone,
            occurredAtHeader: self::requiredText(
                $input,
                'mapping_occurred_at_header',
                191,
                'columna fecha'
            ),
            directionHeader: self::requiredText(
                $input,
                'mapping_direction_header',
                191,
                'columna dirección'
            ),
            grossAmountHeader: self::requiredText(
                $input,
                'mapping_gross_amount_header',
                191,
                'columna bruto'
            ),
            feeAmountHeader: self::nullableText(
                $input,
                'mapping_fee_amount_header',
                191
            ),
            withholdingAmountHeader:
                self::nullableText(
                    $input,
                    'mapping_withholding_amount_header',
                    191
                ),
            netAmountHeader: self::requiredText(
                $input,
                'mapping_net_amount_header',
                191,
                'columna neto'
            ),
            externalOperationIdHeader:
                self::nullableText(
                    $input,
                    'mapping_external_operation_id_header',
                    191
                ),
            referenceHeader: self::nullableText(
                $input,
                'mapping_reference_header',
                191
            ),
            creditValue: self::requiredText(
                $input,
                'mapping_credit_value',
                100,
                'valor de crédito'
            ),
            debitValue: self::requiredText(
                $input,
                'mapping_debit_value',
                100,
                'valor de débito'
            )
        );

        $mapping->assertUnambiguous();

        return $mapping;
    }

    /**
     * @return list<string>
     */
    public function sourceHeaders(): array
    {
        return array_values(
            array_filter(
                [
                    $this->occurredAtHeader,
                    $this->directionHeader,
                    $this->grossAmountHeader,
                    $this->feeAmountHeader,
                    $this->withholdingAmountHeader,
                    $this->netAmountHeader,
                    $this->externalOperationIdHeader,
                    $this->referenceHeader,
                ],
                static fn (?string $value): bool =>
                    $value !== null
            )
        );
    }

    public function fingerprint(): string
    {
        return hash(
            'sha256',
            json_encode(
                [
                    'canonical' => $this->canonical,
                    'delimiter' => $this->delimiter,
                    'decimal_separator' =>
                        $this->decimalSeparator,
                    'date_format' => $this->dateFormat,
                    'timezone' => $this->timezone,
                    'occurred_at_header' =>
                        $this->occurredAtHeader,
                    'direction_header' =>
                        $this->directionHeader,
                    'gross_amount_header' =>
                        $this->grossAmountHeader,
                    'fee_amount_header' =>
                        $this->feeAmountHeader,
                    'withholding_amount_header' =>
                        $this->withholdingAmountHeader,
                    'net_amount_header' =>
                        $this->netAmountHeader,
                    'external_operation_id_header' =>
                        $this->externalOperationIdHeader,
                    'reference_header' =>
                        $this->referenceHeader,
                    'credit_value' => $this->creditValue,
                    'debit_value' => $this->debitValue,
                ],
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            )
        );
    }

    private function assertUnambiguous(): void
    {
        $headers = $this->sourceHeaders();

        if (
            count($headers)
            !== count(array_unique($headers))
        ) {
            throw new DomainException(
                'Una misma columna origen no puede alimentar dos campos canónicos.'
            );
        }

        if ($this->creditValue === $this->debitValue) {
            throw new DomainException(
                'Los valores configurados para crédito y débito deben ser distintos.'
            );
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function requiredText(
        array $input,
        string $key,
        int $maxLength,
        string $label
    ): string {
        $value = trim(
            (string) ($input[$key] ?? '')
        );

        if (
            $value === ''
            || mb_strlen($value) > $maxLength
        ) {
            throw new DomainException(
                'Debe indicar '.$label
                .' con una longitud válida.'
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function nullableText(
        array $input,
        string $key,
        int $maxLength
    ): ?string {
        $value = trim(
            (string) ($input[$key] ?? '')
        );

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new DomainException(
                'Una columna configurada supera la longitud admitida.'
            );
        }

        return $value;
    }
}
