<?php

namespace App\Enums;

enum ServiceCustodyEventType: string
{
    case Received = 'received';
    case Transferred = 'transferred';
    case Returned = 'returned';
    case Delivered = 'delivered';
    case WarrantyReturned = 'warranty_returned';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recibido',
            self::Transferred => 'Transferido',
            self::Returned => 'Retornado',
            self::Delivered => 'Entregado',
            self::WarrantyReturned => 'Devuelto por garantía',
        };
    }
}
