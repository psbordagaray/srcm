<?php

namespace App\Domain\Numerics;

final class DigitOmissionClassifier implements NumericalDiscrepancyClassifier
{
    public const IDENTIFIER = 'numeric.digit.omission.v1';

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function classify(
        string $referenceValue,
        string $observedValue,
    ): ?NumericalDiscrepancySignal {
        if (
            strlen($referenceValue) !== strlen($observedValue) + 1
            || strlen($observedValue) < 1
            || preg_match('/^[0-9]+$/D', $referenceValue) !== 1
            || preg_match('/^[0-9]+$/D', $observedValue) !== 1
        ) {
            return null;
        }

        $candidateCount = 0;
        $firstCandidateIndex = null;

        for ($index = 0, $length = strlen($referenceValue); $index < $length; $index++) {
            $candidate = substr($referenceValue, 0, $index)
                . substr($referenceValue, $index + 1);

            if ($candidate !== $observedValue) {
                continue;
            }

            $candidateCount++;
            $firstCandidateIndex ??= $index;
        }

        if ($candidateCount === 0 || $firstCandidateIndex === null) {
            return null;
        }

        $confidence = $candidateCount === 1
            ? NumericalDiscrepancyConfidence::High
            : NumericalDiscrepancyConfidence::Medium;

        return new NumericalDiscrepancySignal(
            kind: NumericalDiscrepancyKind::DigitOmission,
            confidence: $confidence,
            ruleId: self::IDENTIFIER,
            referenceValue: $referenceValue,
            observedValue: $observedValue,
            explanation: $candidateCount === 1
                ? 'Observed value structurally matches removal of one digit at a unique position; this does not prove human cause or intent.'
                : 'Observed value structurally matches removal of one digit at multiple equivalent positions; the omission location is ambiguous.',
            evidence: [
                'candidate_count' => $candidateCount,
                'first_candidate_index' => $firstCandidateIndex,
                'index_base' => 'zero',
                'omitted_digit' => $referenceValue[$firstCandidateIndex],
                'structural_match' => true,
                'human_cause_proven' => false,
                'special_case_inferred' => false,
            ],
        );
    }
}