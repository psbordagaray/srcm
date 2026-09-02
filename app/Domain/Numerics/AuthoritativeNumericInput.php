<?php

namespace App\Domain\Numerics;

final readonly class AuthoritativeNumericInput
{
    public const SOURCE_MACHINE_CANONICAL = 'MACHINE_CANONICAL';
    public const SOURCE_LEGACY_MINOR_UNIT = 'LEGACY_MINOR_UNIT';
    public const SOURCE_HUMAN_PARSED = 'HUMAN_PARSED';

    /** @var list<string> */
    public const SOURCE_VALUES = [
        self::SOURCE_MACHINE_CANONICAL,
        self::SOURCE_LEGACY_MINOR_UNIT,
        self::SOURCE_HUMAN_PARSED,
    ];

    private function __construct(
        public NumericKind $kind,
        public string $source,
        public ExactDecimal $canonical,
        public ?string $rawHumanInput,
    ) {
    }

    public static function machineCanonical(
        mixed $value,
        NumericKind $kind,
        int $maxScale,
    ): self {
        $canonical = ExactDecimalLegacyAdapter::fromCanonicalMachine(
            $value,
            $maxScale,
        );

        (new NumericIntegrityContract(
            kind: $kind,
            maxScale: $maxScale,
        ))->assertAccepts($canonical);

        return new self(
            kind: $kind,
            source: self::SOURCE_MACHINE_CANONICAL,
            canonical: $canonical,
            rawHumanInput: null,
        );
    }

    public static function legacyMoneyMinorUnit(
        int $minorUnit,
        int $scale,
    ): self {
        $canonical = ExactDecimalLegacyAdapter::fromMinorUnit(
            $minorUnit,
            $scale,
        );

        (new NumericIntegrityContract(
            kind: NumericKind::Money,
            maxScale: $scale,
        ))->assertAccepts($canonical);

        return new self(
            kind: NumericKind::Money,
            source: self::SOURCE_LEGACY_MINOR_UNIT,
            canonical: $canonical,
            rawHumanInput: null,
        );
    }

    public static function humanParsed(
        HumanNumericInput $input,
        int $maxScale,
    ): self {
        (new NumericIntegrityContract(
            kind: $input->kind,
            maxScale: $maxScale,
        ))->assertAccepts($input->canonical);

        return new self(
            kind: $input->kind,
            source: self::SOURCE_HUMAN_PARSED,
            canonical: $input->canonical,
            rawHumanInput: $input->raw,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'source' => $this->source,
            'canonical' => $this->canonical->value,
            'raw_human_input' => $this->rawHumanInput,
        ];
    }
}
