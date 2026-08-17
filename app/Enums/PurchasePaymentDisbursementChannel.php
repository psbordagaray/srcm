<?php

namespace App\Enums;

enum PurchasePaymentDisbursementChannel: string
{
    case Cash = 'cash';
    case NonCash = 'noncash';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::NonCash => 'No efectivo',
        };
    }
}
