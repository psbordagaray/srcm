<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Issued => 'Emitida',
            self::PartiallyReceived => 'Recibida parcialmente',
            self::Received => 'Recibida',
            self::Cancelled => 'Cancelada',
        };
    }

    public function acceptsReceipts(): bool
    {
        return in_array(
            $this,
            [self::Issued, self::PartiallyReceived],
            true
        );
    }

    public function isClosed(): bool
    {
        return in_array(
            $this,
            [self::Received, self::Cancelled],
            true
        );
    }
}
