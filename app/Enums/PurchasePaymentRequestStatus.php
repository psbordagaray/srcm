<?php

namespace App\Enums;

enum PurchasePaymentRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de autorización',
            self::Approved => 'Autorizada · sin ejecución',
            self::Rejected => 'Rechazada',
            self::Cancelled => 'Cancelada',
            self::Expired => 'Vencida',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rejected,
            self::Cancelled,
            self::Expired,
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
