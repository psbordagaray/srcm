<?php

namespace App\Enums;

enum ServiceWorkOutcome: string
{
    case Completed = 'completed';
    case Unresolved = 'unresolved';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Reparado',
            self::Unresolved => 'Sin solución',
        };
    }
}
