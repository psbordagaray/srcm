<?php

namespace App\Domain\Numerics;

final class ModuloNineTranspositionSignalClassifier implements NumericalDiscrepancyClassifier
{
    public const IDENTIFIER = 'numeric.transposition.mod9.v1';

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

        if (
            strlen($referenceValue) !== strlen($observedValue)
            || strlen($referenceValue) < 2
        ) {
            return null;
        }

        if (
            preg_match('/^[0-9]+$/D', $referenceValue) !== 1
            || preg_match('/^[0-9]+$/D', $observedValue) !== 1
        ) {
            return null;
        }

        $referenceModulo = self::digitSumModuloNine($referenceValue);
        $observedModulo = self::digitSumModuloNine($observedValue);

        if ($referenceModulo !== $observedModulo) {
            return null;
        }

        return new NumericalDiscrepancySignal(
            kind: NumericalDiscrepancyKind::TranspositionModuloNineSignal,
            confidence: NumericalDiscrepancyConfidence::Low,
            ruleId: self::IDENTIFIER,
            referenceValue: $referenceValue,
            observedValue: $observedValue,
            explanation: 'Modulo-9 compatibility is only a transposition signal; it is not proof of a transposition.',
            evidence: [
                'same_length' => true,
                'reference_digit_sum_mod_9' => $referenceModulo,
                'observed_digit_sum_mod_9' => $observedModulo,
                'signal_only' => true,
            ],
        );
    }

    private static function digitSumModuloNine(string $digits): int
    {
        $modulo = 0;

        for ($index = 0, $length = strlen($digits); $index < $length; $index++) {
            $modulo = ($modulo + (ord($digits[$index]) - 48)) % 9;
        }

        return $modulo;
    }
}