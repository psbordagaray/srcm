<?php

namespace App\Domain\Purchase;

final readonly class PurchaseOrderLineData
{
    public function __construct(
        public int $catalogProductId,
        public string $quantity,
        public int $unitCostMinor,
        public ?int $supplierOfferId = null,
        public ?string $supplierCode = null,
        public ?string $description = null
    ) {
    }
}
