<?php

namespace App\Domain\Numerics;

enum NumericRoundingMode: string
{
    case Unnecessary = 'UNNECESSARY';
    case Down = 'DOWN';
    case Up = 'UP';
    case Floor = 'FLOOR';
    case Ceiling = 'CEILING';
    case HalfUp = 'HALF_UP';
    case HalfDown = 'HALF_DOWN';
    case HalfEven = 'HALF_EVEN';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $mode): string => $mode->value,
            self::cases(),
        );
    }
}
