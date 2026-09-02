<?php

namespace App\Domain\Numerics;

use InvalidArgumentException;
use JsonException;

final readonly class NumericIntegrityContract
{
    public const SCHEMA = 'straleon.numeric-integrity.v1';

    /** @var list<string> */
    public const REQUIRED_FIELDS = [
        'schema',
        'kind',
        'max_scale',
        'rounding_mode',
        'rounding_boundary',
    ];

    public function __construct(
        public NumericKind $kind,
        public int $maxScale,
        public ?NumericRoundingMode $roundingMode = null,
        public ?string $roundingBoundary = null,
    ) {
        if ($this->maxScale < 0 || $this->maxScale > 18) {
            throw new InvalidArgumentException(
                'Numeric max_scale must be between 0 and 18.'
            );
        }

        if ($this->kind->requiresInteger() && $this->maxScale !== 0) {
            throw new InvalidArgumentException(
                'COUNT must declare max_scale 0.'
            );
        }

        if (($this->roundingMode === null) !== ($this->roundingBoundary === null)) {
            throw new InvalidArgumentException(
                'Rounding mode and rounding boundary must be declared together.'
            );
        }

        if ($this->roundingBoundary !== null) {
            self::assertBoundary($this->roundingBoundary);
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException(
                'Numeric integrity schema mismatch fails closed.'
            );
        }

        $extra = array_values(array_diff(
            array_keys($data),
            self::REQUIRED_FIELDS,
        ));

        if ($extra !== []) {
            throw new InvalidArgumentException(
                'Numeric integrity contract contains uncontracted fields.'
            );
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    "Numeric integrity field [{$field}] is required."
                );
            }
        }

        if (! is_int($data['max_scale'])) {
            throw new InvalidArgumentException(
                'Numeric integrity max_scale must be an integer.'
            );
        }

        $kind = NumericKind::tryFrom((string) $data['kind']);
        if ($kind === null) {
            throw new InvalidArgumentException(
                'Unknown numeric kind fails closed.'
            );
        }

        $roundingMode = null;
        if ($data['rounding_mode'] !== null) {
            $roundingMode = NumericRoundingMode::tryFrom(
                (string) $data['rounding_mode']
            );

            if ($roundingMode === null) {
                throw new InvalidArgumentException(
                    'Unknown rounding mode fails closed.'
                );
            }
        }

        $roundingBoundary = $data['rounding_boundary'] === null
            ? null
            : (string) $data['rounding_boundary'];

        return new self(
            kind: $kind,
            maxScale: $data['max_scale'],
            roundingMode: $roundingMode,
            roundingBoundary: $roundingBoundary,
        );
    }

    public function assertAccepts(ExactDecimal $value): void
    {
        $value->assertMaxScale($this->maxScale);

        if ($this->kind->requiresInteger()) {
            $value->assertInteger();
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'kind' => $this->kind->value,
            'max_scale' => $this->maxScale,
            'rounding_mode' => $this->roundingMode?->value,
            'rounding_boundary' => $this->roundingBoundary,
        ];
    }

    /** @throws JsonException */
    public function canonicalJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @throws JsonException */
    public function fingerprint(): string
    {
        return hash('sha256', $this->canonicalJson());
    }

    private static function assertBoundary(string $boundary): void
    {
        if (
            $boundary === ''
            || strlen($boundary) > 128
            || trim($boundary) !== $boundary
            || preg_match('/^[a-z][a-z0-9_.:-]*$/D', $boundary) !== 1
        ) {
            throw new InvalidArgumentException(
                'Rounding boundary must be an explicit canonical identifier.'
            );
        }
    }
}
