<?php

namespace App\Enums;

enum CommerceSaleLineType: string
{
    case Service = 'service';
    case Product = 'product';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Servicio técnico',
            self::Product => 'Producto',
        };
    }
}
