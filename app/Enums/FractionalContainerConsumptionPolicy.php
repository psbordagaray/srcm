<?php

namespace App\Enums;

enum FractionalContainerConsumptionPolicy: string
{
    case ExhaustOpenContainer = 'agotar_contenedor_abierto';
    case ManualSelection = 'seleccion_manual';

    public function label(): string
    {
        return match ($this) {
            self::ExhaustOpenContainer => 'Agotar contenedor abierto',
            self::ManualSelection => 'Selección manual',
        };
    }
}
