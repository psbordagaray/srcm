<?php

namespace App\Domain\Numerics;

enum NumericalDiscrepancyConfidence: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $confidence): string => $confidence->value,
            self::cases(),
        );
    }
}