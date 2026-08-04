<?php

namespace App\Enums;

enum ServiceCancellationReason: string
{
    case CustomerChangedMind = 'customer_changed_mind';
    case ReplacementDevice = 'replacement_device';
    case RevisedPromiseRejected = 'revised_promise_rejected';
    case PartUnavailable = 'part_unavailable';
    case TechnicalImpossibility = 'technical_impossibility';
    case BusinessDecision = 'business_decision';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomerChangedMind => 'El cliente desistió',
            self::ReplacementDevice => 'Recibió otro equipo',
            self::RevisedPromiseRejected => 'Rechazó la nueva fecha',
            self::PartUnavailable => 'Repuesto no disponible',
            self::TechnicalImpossibility => 'Imposibilidad técnica',
            self::BusinessDecision => 'Decisión del comercio',
            self::Other => 'Otro motivo',
        };
    }
}
