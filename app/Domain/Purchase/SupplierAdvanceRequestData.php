<?php

namespace App\Domain\Purchase;

final readonly class SupplierAdvanceRequestData
{
    public function __construct(
        public int $supplierId,
        public int $originFinancialAccountId,
        public int $amountMinor,
        public string $idempotencyKey,
        public ?string $requestNote = null
    ) {
    }
}
