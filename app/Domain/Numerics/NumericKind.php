<?php

namespace App\Domain\Numerics;

enum NumericKind: string
{
    case Money = 'MONEY';
    case Quantity = 'QUANTITY';
    case Rate = 'RATE';
    case Percentage = 'PERCENTAGE';
    case Count = 'COUNT';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $kind): string => $kind->value,
            self::cases(),
        );
    }

    public function requiresInteger(): bool
    {
        return $this === self::Count;
    }
}
