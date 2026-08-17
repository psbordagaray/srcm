<?php

namespace App\Enums;

enum CashMovementType: string
{
    case SalePayment = 'sale_payment';
    case SecurityDrop = 'security_drop';
    case PurchasePayment = 'purchase_payment';
    case PostSaleRefund = 'post_sale_refund';
    case PostSaleExchangeDifference = 'post_sale_exchange_difference';
    case CustomerCollection = 'customer_collection';
    case CustomerAdvance = 'customer_advance';
    case SupplierAdvance = 'supplier_advance';

    public function label(): string
    {
        return match ($this) {
            self::SalePayment => 'Cobro de venta',
            self::SecurityDrop => 'Retiro de seguridad',
            self::PurchasePayment => 'Pago a proveedor',
            self::PostSaleRefund => 'Reembolso posventa',
            self::PostSaleExchangeDifference => 'Cobro diferencia de cambio posventa',
            self::CustomerCollection => 'Cobranza de cuenta corriente',
            self::CustomerAdvance => 'Anticipo de cliente',
            self::SupplierAdvance => 'Anticipo a proveedor',
        };
    }
}