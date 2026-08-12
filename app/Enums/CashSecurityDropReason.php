<?php

namespace App\Enums;

enum CashSecurityDropReason: string
{
    case ExcessCash = 'excess_cash';
    case ScheduledDrop = 'scheduled_drop';
    case SupervisorRequest = 'supervisor_request';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ExcessCash => 'Exceso de efectivo / seguridad',
            self::ScheduledDrop => 'Retiro programado',
            self::SupervisorRequest => 'Solicitud de supervisión',
            self::Other => 'Otro motivo operativo',
        };
    }
}
