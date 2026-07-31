<?php

namespace App\Enums;

enum InventoryLocationType: string
{
    case Branch = 'branch';
    case Warehouse = 'warehouse';
    case Sector = 'sector';
    case Shelf = 'shelf';
    case Position = 'position';
    case Receiving = 'receiving';

    public function label(): string
    {
        return match ($this) {
            self::Branch => 'Sucursal',
            self::Warehouse => 'Depósito',
            self::Sector => 'Sector',
            self::Shelf => 'Estantería',
            self::Position => 'Posición',
            self::Receiving => 'Recepción',
        };
    }
}
