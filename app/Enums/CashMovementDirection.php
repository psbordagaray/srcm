<?php

namespace App\Enums;

enum CashMovementDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Entrada',
            self::Out => 'Salida',
        };
    }
}
