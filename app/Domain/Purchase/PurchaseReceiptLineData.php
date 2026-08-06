<?php

namespace App\Domain\Purchase;

use App\Enums\InventoryCondition;

final readonly class PurchaseReceiptLineData
{
    public function __construct(
        public int $purchaseOrderLineId,
        public string $quantity,
        public int $inventoryLocationId,
        public InventoryCondition $condition,
        public int $actualUnitCostMinor
    ) {
    }
}
