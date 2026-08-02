<?php

namespace App\Enums;

enum ServiceWorkStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case WithProvider = 'with_provider';
    case Completed = 'completed';
    case Unresolved = 'unresolved';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planificado',
            self::InProgress => 'En ejecución',
            self::WithProvider => 'Con especialista externo',
            self::Completed => 'Completado',
            self::Unresolved => 'Sin solución',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Unresolved], true);
    }
}
