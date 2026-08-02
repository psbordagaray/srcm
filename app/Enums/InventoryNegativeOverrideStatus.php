<?php

namespace App\Enums;

enum InventoryNegativeOverrideStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Revoked = 'revoked';
    case Invalidated = 'invalidated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Consumed => 'Consumido',
            self::Revoked => 'Revocado',
            self::Invalidated => 'Invalidado',
        };
    }
}
