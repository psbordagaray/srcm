<?php

namespace App\Enums;

enum PurchaseObligationKind: string
{
    case Merchandise = 'merchandise';
    case Logistics = 'logistics';

    public function label(): string
    {
        return match ($this) {
            self::Merchandise => 'Mercadería',
            self::Logistics => 'Logística / flete',
        };
    }
}
