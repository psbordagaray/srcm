<?php

namespace App\Enums;

enum ServicePartSource: string
{
    case Stock = 'stock';
    case DirectPurchase = 'direct_purchase';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Stock propio',
            self::DirectPurchase => 'Compra para la orden',
        };
    }
}
