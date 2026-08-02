<?php

namespace App\Enums;

enum InventoryNegativeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Invalidated = 'invalidated';
    case Fulfilled = 'fulfilled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Approved => 'Aprobada',
            self::Rejected => 'Rechazada',
            self::Invalidated => 'Invalidada',
            self::Fulfilled => 'Consumida',
        };
    }
}
