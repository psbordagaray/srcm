<?php

namespace App\Enums;

enum ServiceWarrantyTemporalStatus: string
{
    case Active = 'active';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Vigente',
            self::Expired => 'Vencida',
        };
    }
}
