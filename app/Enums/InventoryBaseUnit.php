<?php

namespace App\Enums;

enum InventoryBaseUnit: string
{
    case Unit = 'unit';
    case Liter = 'l';
    case Meter = 'm';
    case Kilogram = 'kg';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unidad',
            self::Liter => 'Litro',
            self::Meter => 'Metro',
            self::Kilogram => 'Kilogramo',
        };
    }

    public function allowsFractionalQuantity(): bool
    {
        return $this !== self::Unit;
    }
}
