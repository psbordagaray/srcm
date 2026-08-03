<?php

namespace App\Enums;

enum ServiceQualityOutcome: string
{
    case Approved = 'approved';
    case ReworkRequired = 'rework_required';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Aprobado',
            self::ReworkRequired => 'Requiere retrabajo',
        };
    }
}
