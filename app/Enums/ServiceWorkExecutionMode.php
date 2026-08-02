<?php

namespace App\Enums;

enum ServiceWorkExecutionMode: string
{
    case Internal = 'internal';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Trabajo propio',
            self::External => 'Trabajo tercerizado',
        };
    }
}
