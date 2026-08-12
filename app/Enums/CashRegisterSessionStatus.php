<?php

namespace App\Enums;

enum CashRegisterSessionStatus: string
{
    case Open = 'open';
    case ClosingRequested = 'closing_requested';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::ClosingRequested => 'Cierre solicitado',
            self::Closed => 'Cerrado',
        };
    }
}
