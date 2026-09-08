<?php

namespace App\Enums;

enum AttributeValueScope: string
{
    case Product = 'product';
    case Variant = 'variant';
    case InventoryUnit = 'inventory_unit';
    case LotOrBatch = 'lot_or_batch';
    case SupplierOffer = 'supplier_offer';
}
