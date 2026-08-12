<?php

namespace App\Enums;

enum CashMovementType: string
{
    case SalePayment = 'sale_payment';
    case SecurityDrop = 'security_drop';

    public function label(): string
    {
        return match ($this) {
            self::SalePayment => 'Cobro de venta',
            self::SecurityDrop => 'Retiro de seguridad',
        };
    }
}
