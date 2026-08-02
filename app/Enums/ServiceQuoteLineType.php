<?php

namespace App\Enums;

enum ServiceQuoteLineType: string
{
    case Labor = 'labor';
    case Part = 'part';
    case Logistics = 'logistics';
    case DataService = 'data_service';
    case ExternalService = 'external_service';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Labor => 'Mano de obra',
            self::Part => 'Repuesto',
            self::Logistics => 'Logística',
            self::DataService => 'Servicio sobre datos',
            self::ExternalService => 'Servicio tercerizado',
            self::Other => 'Otro',
        };
    }
}
