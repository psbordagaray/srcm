<?php

namespace App\Domain\Purchase;

final readonly class SupplierInvoiceLineData
{
    public function __construct(
        public ?int $purchaseOrderLineId,
        public string $description,
        public string $quantity,
        public int $unitCostMinor,
        public ?string $supplierCode = null
    ) {
    }
}
