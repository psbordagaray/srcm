<?php

namespace App\Domain\Numerics;

final class AdjacentTranspositionClassifier implements NumericalDiscrepancyClassifier
{
    public const IDENTIFIER = 'numeric.transposition.adjacent.v1';

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
            || strlen($referenceValue) < 2
            || preg_match('/^[0-9]+$/D', $referenceValue) !== 1
            || preg_match('/^[0-9]+$/D', $observedValue) !== 1
        ) {
            return null;
        }

        $differences = [];

        for ($index = 0, $length = strlen($referenceValue); $index < $length; $index++) {
            if ($referenceValue[$index] !== $observedValue[$index]) {
                $differences[] = $index;

                if (count($differences) > 2) {
                    return null;
                }
            }
        }

        if (count($differences) !== 2) {
            return null;
        }

        [$first, $second] = $differences;

        if (
            $second !== $first + 1
            || $referenceValue[$first] !== $observedValue[$second]
            || $referenceValue[$second] !== $observedValue[$first]
            || $referenceValue[$first] === $referenceValue[$second]
        ) {
            return null;
        }

        return new NumericalDiscrepancySignal(
            kind: NumericalDiscrepancyKind::AdjacentTransposition,
            confidence: NumericalDiscrepancyConfidence::High,
            ruleId: self::IDENTIFIER,
            referenceValue: $referenceValue,
            observedValue: $observedValue,
            explanation: 'Observed value structurally matches one adjacent digit swap; this does not prove human cause or intent.',
            evidence: [
                'first_index' => $first,
                'second_index' => $second,
                'index_base' => 'zero',
                'structural_match' => true,
                'human_cause_proven' => false,
            ],
        );
    }
}