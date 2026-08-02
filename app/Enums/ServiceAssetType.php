<?php

namespace App\Enums;

enum ServiceAssetType: string
{
    case MobilePhone = 'mobile_phone';
    case Tablet = 'tablet';
    case Notebook = 'notebook';
    case DesktopComputer = 'desktop_computer';
    case Television = 'television';
    case Monitor = 'monitor';
    case Printer = 'printer';
    case Vehicle = 'vehicle';
    case Appliance = 'appliance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MobilePhone => 'Celular',
            self::Tablet => 'Tablet',
            self::Notebook => 'Notebook',
            self::DesktopComputer => 'Computadora de escritorio',
            self::Television => 'Televisor',
            self::Monitor => 'Monitor',
            self::Printer => 'Impresora',
            self::Vehicle => 'Vehículo',
            self::Appliance => 'Electrodoméstico',
            self::Other => 'Otro activo',
        };
    }
}
