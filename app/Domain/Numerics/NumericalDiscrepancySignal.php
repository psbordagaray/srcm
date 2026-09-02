<?php

namespace App\Domain\Numerics;

use InvalidArgumentException;

final readonly class NumericalDiscrepancySignal
{
    public const SCHEMA = 'straleon.numeric-discrepancy-signal.v1';

    /**
     * @param array<string, bool|int|string|null> $evidence
     */
    public function __construct(
        public NumericalDiscrepancyKind $kind,
        public NumericalDiscrepancyConfidence $confidence,
        public string $ruleId,
        public string $referenceValue,
        public string $observedValue,
        public string $explanation,
        public array $evidence = [],
    ) {
        if (
            preg_match(
                '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/D',
                $this->ruleId,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Numeric discrepancy rule id must be explicit and stable.'
            );
        }

        if (trim($this->explanation) === '') {
            throw new InvalidArgumentException(
                'Numeric discrepancy explanation must not be empty.'
            );
        }

        foreach ($this->evidence as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException(
                    'Numeric discrepancy evidence keys must be non-empty strings.'
                );
            }

            if (
                $value !== null
                && ! is_bool($value)
                && ! is_int($value)
                && ! is_string($value)
            ) {
                throw new InvalidArgumentException(
                    'Numeric discrepancy evidence values must be scalar audit data.'
                );
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'kind' => $this->kind->value,
            'confidence' => $this->confidence->value,
            'rule_id' => $this->ruleId,
            'reference_value' => $this->referenceValue,
            'observed_value' => $this->observedValue,
            'explanation' => $this->explanation,
            'evidence' => $this->evidence,
        ];
    }
}