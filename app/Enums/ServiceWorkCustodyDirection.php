<?php

namespace App\Enums;

enum ServiceWorkCustodyDirection: string
{
    case Dispatch = 'dispatch';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Dispatch => 'Entrega al especialista',
            self::Return => 'Retorno del especialista',
        };
    }
}
