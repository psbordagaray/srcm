<?php

namespace App\Enums;

enum CashCountDifferenceReason: string
{
    case CountingConfirmed = 'counting_confirmed';
    case CashHandlingIncident = 'cash_handling_incident';
    case Unexplained = 'unexplained';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CountingConfirmed => 'Reconteo confirmado',
            self::CashHandlingIncident => 'Incidente de manejo de efectivo',
            self::Unexplained => 'Diferencia sin explicación al cierre',
            self::Other => 'Otro motivo',
        };
    }
}
