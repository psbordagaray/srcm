<?php

namespace App\Enums;

enum FiscalEnvironment: string
{
    case Homologation = 'homologation';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Homologation => 'Homologación',
            self::Production => 'Producción',
        };
    }
}

