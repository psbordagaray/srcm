<?php

namespace App\Enums;

enum CashMovementType: string
{
    case SalePayment = 'sale_payment';

    public function label(): string
    {
        return match ($this) {
            self::SalePayment => 'Cobro de venta',
        };
    }
}
