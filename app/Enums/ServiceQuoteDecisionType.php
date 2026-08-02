<?php

namespace App\Enums;

enum ServiceQuoteDecisionType: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Aprobado',
            self::Rejected => 'Rechazado',
        };
    }
}
