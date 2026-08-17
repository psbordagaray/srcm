<?php

namespace App\Enums;

enum CustomerCollectionStatus: string
{
    case Building = 'building';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Building => 'En preparación',
            self::Confirmed => 'Confirmada',
        };
    }
}
