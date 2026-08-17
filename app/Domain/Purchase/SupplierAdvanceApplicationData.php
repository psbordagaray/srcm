<?php

namespace App\Domain\Purchase;

final readonly class SupplierAdvanceApplicationData
{
    public function __construct(
        public int $supplierAdvanceId,
        public int $purchaseObligationId,
        public int $amountMinor,
        public string $idempotencyKey,
        public ?string $applicationNote = null
    ) {
    }
}
