<?php

namespace App\Enums;

enum FractionalContainerState: string
{
    case Sealed = 'sealed';
    case Open = 'open';
    case Exhausted = 'exhausted';

    public function label(): string
    {
        return match ($this) {
            self::Sealed => 'Sellado',
            self::Open => 'Abierto',
            self::Exhausted => 'Agotado',
        };
    }
}
