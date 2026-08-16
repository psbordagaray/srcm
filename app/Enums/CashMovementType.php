<?php

namespace App\Enums;

enum CashMovementType: string
{
    case SalePayment = 'sale_payment';
    case SecurityDrop = 'security_drop';
    case PurchasePayment = 'purchase_payment';
    case PostSaleRefund = 'post_sale_refund';

    public function label(): string
    {
        return match ($this) {
            self::SalePayment => 'Cobro de venta',
            self::SecurityDrop => 'Retiro de seguridad',
            self::PurchasePayment => 'Pago a proveedor',
            self::PostSaleRefund => 'Reembolso posventa',
        };
    }
}
