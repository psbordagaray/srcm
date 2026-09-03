<?php

namespace App\Domain\Numerics;

use InvalidArgumentException;

final readonly class NumericalDiscrepancyDecisionEvidence
{
    public const SCHEMA = 'straleon.numeric-discrepancy-decision-evidence.v1';

    public const WARNING_CODE = 'NUMERICAL_DISCREPANCY_REVIEW_REQUIRED';

    /** @var list<NumericalDiscrepancySignal> */
    public array $signals;

    /**
     * @param list<NumericalDiscrepancySignal> $signals
     */
    private function __construct(
        public string $referenceValue,
        public string $observedValue,
        array $signals,
        public NumericalDiscrepancyDecision $decision,
        public string $finalValue,
        public string $reason,
    ) {
        if ($this->referenceValue === $this->observedValue) {
            throw new InvalidArgumentException(
                'Numeric discrepancy decision evidence requires different reference and observed values.'
            );
        }

        if ($signals === []) {
            throw new InvalidArgumentException(
                'Numeric discrepancy decision evidence requires at least one deterministic signal.'
            );
        }

        if (
            trim($this->reason) === ''
            || trim($this->reason) !== $this->reason
            || strlen($this->reason) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $this->reason) === 1
        ) {
            throw new InvalidArgumentException(
                'Numeric discrepancy decision reason must be explicit, bounded and free of control characters.'
            );
        }

        $ruleIds = [];
        $normalizedSignals = [];

        foreach ($signals as $signal) {
            if (! $signal instanceof NumericalDiscrepancySignal) {
                throw new InvalidArgumentException(
                    'Numeric discrepancy decision evidence accepts deterministic discrepancy signals only.'
                );
            }

            if (
                $signal->referenceValue !== $this->referenceValue
                || $signal->observedValue !== $this->observedValue
            ) {
                throw new InvalidArgumentException(
                    'Every numeric discrepancy signal must describe the same reference and observed values.'
                );
            }

            if (array_key_exists($signal->ruleId, $ruleIds)) {
                throw new InvalidArgumentException(
                    'Numeric discrepancy decision evidence rejects duplicate signal rule ids.'
                );
            }

            $ruleIds[$signal->ruleId] = true;
            $normalizedSignals[] = $signal;
        }

        usort(
            $normalizedSignals,
            static fn (
                NumericalDiscrepancySignal $left,
                NumericalDiscrepancySignal $right,
            ): int => $left->ruleId <=> $right->ruleId,
        );

        $expectedFinalValue = match ($this->decision) {
            NumericalDiscrepancyDecision::KeepReference =>
                $this->referenceValue,
            NumericalDiscrepancyDecision::AcceptObserved =>
                $this->observedValue,
        };

        if ($this->finalValue !== $expectedFinalValue) {
            throw new InvalidArgumentException(
                'Numeric discrepancy final value must match the explicit decision exactly.'
            );
        }

        $this->signals = array_values($normalizedSignals);
    }

    /**
     * @param list<NumericalDiscrepancySignal> $signals
     */
    public static function keepReference(
        string $referenceValue,
        string $observedValue,
        array $signals,
        string $reason,
    ): self {
        return new self(
            referenceValue: $referenceValue,
            observedValue: $observedValue,
            signals: $signals,
            decision: NumericalDiscrepancyDecision::KeepReference,
            finalValue: $referenceValue,
            reason: $reason,
        );
    }

    /**
     * @param list<NumericalDiscrepancySignal> $signals
     */
    public static function acceptObserved(
        string $referenceValue,
        string $observedValue,
        array $signals,
        string $reason,
    ): self {
        return new self(
            referenceValue: $referenceValue,
            observedValue: $observedValue,
            signals: $signals,
            decision: NumericalDiscrepancyDecision::AcceptObserved,
            finalValue: $observedValue,
            reason: $reason,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'warning_code' => self::WARNING_CODE,
            'reference_value' => $this->referenceValue,
            'original_value' => $this->referenceValue,
            'observed_value' => $this->observedValue,
            'signals' => array_map(
                static fn (NumericalDiscrepancySignal $signal): array =>
                    $signal->toArray(),
                $this->signals,
            ),
            'decision' => $this->decision->value,
            'final_value' => $this->finalValue,
            'reason' => $this->reason,
            'explicit_decision' => true,
            'automatic_correction' => false,
        ];
    }
}