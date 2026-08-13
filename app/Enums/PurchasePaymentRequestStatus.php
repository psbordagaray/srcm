<?php

namespace App\Enums;

enum PurchasePaymentRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Executed = 'executed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de autorización',
            self::Approved => 'Autorizada · sin ejecución',
            self::Rejected => 'Rechazada',
            self::Cancelled => 'Cancelada',
            self::Expired => 'Vencida',
            self::Executed => 'Ejecutada · pago registrado',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rejected,
            self::Cancelled,
            self::Expired,
            self::Executed,
        ], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Approved,
        ], true);
    }
}
