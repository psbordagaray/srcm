<?php

namespace App\Domain\Numerics;

final class DigitDuplicationClassifier implements NumericalDiscrepancyClassifier
{
    public const IDENTIFIER = 'numeric.digit.duplication.v1';

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function classify(
        string $referenceValue,
        string $observedValue,
    ): ?NumericalDiscrepancySignal {
        if (
            strlen($observedValue) !== strlen($referenceValue) + 1
            || strlen($referenceValue) < 1
            || preg_match('/^[0-9]+$/D', $referenceValue) !== 1
            || preg_match('/^[0-9]+$/D', $observedValue) !== 1
        ) {
            return null;
        }

        $candidateCount = 0;
        $firstSourceIndex = null;

        for ($index = 0, $length = strlen($referenceValue); $index < $length; $index++) {
            $candidate = substr($referenceValue, 0, $index + 1)
                . $referenceValue[$index]
                . substr($referenceValue, $index + 1);

            if ($candidate !== $observedValue) {
                continue;
            }

            $candidateCount++;
            $firstSourceIndex ??= $index;
        }

        if ($candidateCount === 0 || $firstSourceIndex === null) {
            return null;
        }

        $confidence = $candidateCount === 1
            ? NumericalDiscrepancyConfidence::High
            : NumericalDiscrepancyConfidence::Medium;

        return new NumericalDiscrepancySignal(
            kind: NumericalDiscrepancyKind::DigitDuplication,
            confidence: $confidence,
            ruleId: self::IDENTIFIER,
            referenceValue: $referenceValue,
            observedValue: $observedValue,
            explanation: $candidateCount === 1
                ? 'Observed value structurally matches duplication of one reference digit at a unique source position; this does not prove human cause or intent.'
                : 'Observed value structurally matches duplication of one reference digit at multiple equivalent source positions; the source position is ambiguous.',
            evidence: [
                'candidate_count' => $candidateCount,
                'first_source_index' => $firstSourceIndex,
                'index_base' => 'zero',
                'duplicated_digit' => $referenceValue[$firstSourceIndex],
                'structural_match' => true,
                'human_cause_proven' => false,
            ],
        );
    }
}