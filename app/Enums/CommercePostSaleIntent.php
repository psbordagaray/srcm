<?php

namespace App\Enums;

enum CommercePostSaleIntent: string
{
    case Return = 'return';
    case Exchange = 'exchange';

    public function label(): string
    {
        return match ($this) {
            self::Return => 'Devolución',
            self::Exchange => 'Cambio',
        };
    }
}
