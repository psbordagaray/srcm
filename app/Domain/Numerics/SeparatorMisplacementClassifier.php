<?php

namespace App\Domain\Numerics;

final class SeparatorMisplacementClassifier implements NumericalDiscrepancyClassifier
{
    public const IDENTIFIER = 'numeric.separator.misplacement.v1';

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function classify(
        string $referenceValue,
        string $observedValue,
    ): ?NumericalDiscrepancySignal {
        if ($referenceValue === $observedValue) {
            return null;
        }

        $reference = self::parseSeparatedDigits($referenceValue);
        $observed = self::parseSeparatedDigits($observedValue);

        if (
            $reference === null
            || $observed === null
            || $reference['separator'] !== $observed['separator']
            || $reference['digits'] !== $observed['digits']
            || $reference['separator_index'] === $observed['separator_index']
        ) {
            return null;
        }

        return new NumericalDiscrepancySignal(
            kind: NumericalDiscrepancyKind::SeparatorMisplacement,
            confidence: NumericalDiscrepancyConfidence::High,
            ruleId: self::IDENTIFIER,
            referenceValue: $referenceValue,
            observedValue: $observedValue,
            explanation: 'Reference and observed values contain the same digits and separator symbol, but the separator is at a different position; this is a structural match only.',
            evidence: [
                'separator' => $reference['separator'],
                'reference_separator_index' => $reference['separator_index'],
                'observed_separator_index' => $observed['separator_index'],
                'index_base' => 'zero',
                'same_digit_sequence' => true,
                'human_cause_proven' => false,
            ],
        );
    }

    /**
     * @return array{digits: string, separator: string, separator_index: int}|null
     */
    private static function parseSeparatedDigits(string $value): ?array
    {
        if (preg_match('/^([0-9]+)([.,])([0-9]+)$/D', $value, $match) !== 1) {
            return null;
        }

        return [
            'digits' => $match[1] . $match[3],
            'separator' => $match[2],
            'separator_index' => strlen($match[1]),
        ];
    }
}