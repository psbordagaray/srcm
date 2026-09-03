<?php

namespace App\Domain\Numerics;

final class DigitSubstitutionClassifier implements NumericalDiscrepancyClassifier
{
    public const IDENTIFIER = 'numeric.digit.substitution.v1';

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function classify(
        string $referenceValue,
        string $observedValue,
    ): ?NumericalDiscrepancySignal {
        if (
            $referenceValue === $observedValue
            || strlen($referenceValue) !== strlen($observedValue)
            || strlen($referenceValue) < 1
            || preg_match('/^[0-9]+$/D', $referenceValue) !== 1
            || preg_match('/^[0-9]+$/D', $observedValue) !== 1
        ) {
            return null;
        }

        $differenceIndex = null;
        $differenceCount = 0;

        for ($index = 0, $length = strlen($referenceValue); $index < $length; $index++) {
            if ($referenceValue[$index] === $observedValue[$index]) {
                continue;
            }

            $differenceCount++;
            $differenceIndex = $index;

            if ($differenceCount > 1) {
                return null;
            }
        }

        if ($differenceCount !== 1 || $differenceIndex === null) {
            return null;
        }

        return new NumericalDiscrepancySignal(
            kind: NumericalDiscrepancyKind::DigitSubstitution,
            confidence: NumericalDiscrepancyConfidence::High,
            ruleId: self::IDENTIFIER,
            referenceValue: $referenceValue,
            observedValue: $observedValue,
            explanation: 'Observed value structurally differs by exactly one digit substitution; this does not prove human cause or intent.',
            evidence: [
                'difference_index' => $differenceIndex,
                'index_base' => 'zero',
                'reference_digit' => $referenceValue[$differenceIndex],
                'observed_digit' => $observedValue[$differenceIndex],
                'structural_match' => true,
                'human_cause_proven' => false,
            ],
        );
    }
}