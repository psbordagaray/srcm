<?php

namespace App\Enums;

enum CashSecurityDropRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Executed = 'executed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de autorización',
            self::Approved => 'Autorizado · pendiente de ejecución',
            self::Executed => 'Ejecutado',
            self::Rejected => 'Rechazado',
            self::Cancelled => 'Cancelado',
            self::Expired => 'Vencido',
        };
    }

    public function blocksClosing(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Approved,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Executed,
            self::Rejected,
            self::Cancelled,
            self::Expired,
        ], true);
    }
}
