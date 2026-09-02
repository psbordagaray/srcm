<?php

namespace App\Domain\Numerics;

use InvalidArgumentException;

final readonly class HumanNumericInput
{
    public const SEPARATOR_NONE = 'NONE';
    public const SEPARATOR_DOT = 'DOT';
    public const SEPARATOR_COMMA = 'COMMA';

    /** @var list<string> */
    public const SEPARATOR_VALUES = [
        self::SEPARATOR_NONE,
        self::SEPARATOR_DOT,
        self::SEPARATOR_COMMA,
    ];

    private function __construct(
        public string $raw,
        public string $decimalSeparator,
        public ExactDecimal $canonical,
        public NumericKind $kind,
    ) {
    }

    public static function parse(
        string $raw,
        NumericKind $kind,
        string $decimalSeparator,
        int $maxScale,
    ): self {
        if (
            $raw === ''
            || strlen($raw) > 256
            || trim($raw) !== $raw
            || preg_match('/[\x00-\x20\x7F]/', $raw) === 1
        ) {
            throw new InvalidArgumentException(
                'Human numeric input must be non-empty and free of whitespace/control characters.'
            );
        }

        if (! in_array($decimalSeparator, self::SEPARATOR_VALUES, true)) {
            throw new InvalidArgumentException(
                'Human numeric input requires an explicit supported decimal separator declaration.'
            );
        }

        if (preg_match('/[eE]/', $raw) === 1) {
            throw new InvalidArgumentException(
                'Scientific notation is forbidden for human numeric input.'
            );
        }

        $dotCount = substr_count($raw, '.');
        $commaCount = substr_count($raw, ',');

        if ($dotCount > 0 && $commaCount > 0) {
            throw new InvalidArgumentException(
                'Mixed dot/comma numeric input is ambiguous and fails closed.'
            );
        }

        if ($dotCount > 1 || $commaCount > 1) {
            throw new InvalidArgumentException(
                'Grouping or repeated decimal separators are forbidden.'
            );
        }

        $canonicalText = match ($decimalSeparator) {
            self::SEPARATOR_NONE => self::parseNoSeparator(
                $raw,
                $dotCount,
                $commaCount,
            ),
            self::SEPARATOR_DOT => self::parseDot(
                $raw,
                $commaCount,
            ),
            self::SEPARATOR_COMMA => self::parseComma(
                $raw,
                $dotCount,
            ),
        };

        $canonical = ExactDecimal::fromCanonical($canonicalText);
        $contract = new NumericIntegrityContract(
            kind: $kind,
            maxScale: $maxScale,
        );
        $contract->assertAccepts($canonical);

        return new self(
            raw: $raw,
            decimalSeparator: $decimalSeparator,
            canonical: $canonical,
            kind: $kind,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'decimal_separator' => $this->decimalSeparator,
            'canonical' => $this->canonical->value,
            'kind' => $this->kind->value,
        ];
    }

    private static function parseNoSeparator(
        string $raw,
        int $dotCount,
        int $commaCount,
    ): string {
        if ($dotCount !== 0 || $commaCount !== 0) {
            throw new InvalidArgumentException(
                'NONE decimal separator forbids dot and comma.'
            );
        }

        return $raw;
    }

    private static function parseDot(
        string $raw,
        int $commaCount,
    ): string {
        if ($commaCount !== 0) {
            throw new InvalidArgumentException(
                'DOT decimal separator declaration rejects comma input.'
            );
        }

        return $raw;
    }

    private static function parseComma(
        string $raw,
        int $dotCount,
    ): string {
        if ($dotCount !== 0) {
            throw new InvalidArgumentException(
                'COMMA decimal separator declaration rejects dot input.'
            );
        }

        return str_replace(',', '.', $raw);
    }
}
