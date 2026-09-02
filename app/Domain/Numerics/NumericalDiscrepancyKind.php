<?php

namespace App\Domain\Numerics;

enum NumericalDiscrepancyKind: string
{
    case TranspositionModuloNineSignal = 'TRANSPOSITION_MODULO_NINE_SIGNAL';
    case AdjacentTransposition = 'ADJACENT_TRANSPOSITION';
    case DigitOmission = 'DIGIT_OMISSION';
    case DigitDuplication = 'DIGIT_DUPLICATION';
    case SeparatorMisplacement = 'SEPARATOR_MISPLACEMENT';
    case DigitSubstitution = 'DIGIT_SUBSTITUTION';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $kind): string => $kind->value,
            self::cases(),
        );
    }
}