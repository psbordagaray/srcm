<?php

namespace App\Enums;

enum FinancialMovementDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Ingreso',
            self::Debit => 'Egreso',
        };
    }
}
