<?php

namespace App\Enums;

enum ServiceFindingSeverity: string
{
    case Informational = 'informational';
    case Attention = 'attention';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Informational => 'Informativo',
            self::Attention => 'Requiere atención',
            self::Critical => 'Crítico',
        };
    }
}
