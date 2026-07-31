<?php

namespace App\Enums;

enum InventoryCondition: string
{
    case New = 'new';
    case Used = 'used';
    case Refurbished = 'refurbished';
    case Damaged = 'damaged';
    case Display = 'display';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nuevo',
            self::Used => 'Usado',
            self::Refurbished => 'Reacondicionado',
            self::Damaged => 'Dañado o para reparar',
            self::Display => 'Exhibición o demostración',
        };
    }
}
