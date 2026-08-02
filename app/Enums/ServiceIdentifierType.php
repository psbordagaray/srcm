<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum ServiceIdentifierType: string
{
    case Imei = 'imei';
    case SerialNumber = 'serial_number';
    case AssetTag = 'asset_tag';
    case Vin = 'vin';
    case LicensePlate = 'license_plate';
    case EngineNumber = 'engine_number';
    case ChassisNumber = 'chassis_number';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Imei => 'IMEI',
            self::SerialNumber => 'Número de serie',
            self::AssetTag => 'Código interno',
            self::Vin => 'VIN',
            self::LicensePlate => 'Patente',
            self::EngineNumber => 'Número de motor',
            self::ChassisNumber => 'Número de chasis',
            self::Other => 'Otro identificador',
        };
    }

    public function normalize(string $value): string
    {
        $ascii = Str::upper(Str::ascii(trim($value)));

        return preg_replace('/[^A-Z0-9]+/', '', $ascii) ?? '';
    }
}
