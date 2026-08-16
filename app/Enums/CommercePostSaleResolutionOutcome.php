<?php

namespace App\Enums;

enum CommercePostSaleResolutionOutcome: string
{
    case Refund = 'refund';
    case CustomerCredit = 'customer_credit';
    case Exchange = 'exchange';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Reembolso',
            self::CustomerCredit => 'Saldo a favor',
            self::Exchange => 'Cambio',
        };
    }
}
