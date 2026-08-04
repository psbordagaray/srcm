<?php

namespace App\Enums;

enum ServiceCancellationFinancialOutcome: string
{
    case NoCharge = 'no_charge';
    case CustomerCharge = 'customer_charge';
    case BusinessAbsorbsCosts = 'business_absorbs_costs';

    public function label(): string
    {
        return match ($this) {
            self::NoCharge => 'Sin cargo',
            self::CustomerCharge => 'Cargo acordado con el cliente',
            self::BusinessAbsorbsCosts => 'El comercio absorbe los costos',
        };
    }
}
