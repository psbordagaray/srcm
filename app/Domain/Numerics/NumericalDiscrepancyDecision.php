<?php

namespace App\Domain\Numerics;

enum NumericalDiscrepancyDecision: string
{
    case KeepReference = 'KEEP_REFERENCE';
    case AcceptObserved = 'ACCEPT_OBSERVED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}