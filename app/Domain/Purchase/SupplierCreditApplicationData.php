<?php

namespace App\Domain\Purchase;

final readonly class SupplierCreditApplicationData
{
    public function __construct(
        public int $supplierCreditNoteId,
        public int $purchaseObligationId,
        public int $amountMinor,
        public string $idempotencyKey,
        public ?string $applicationNote = null
    ) {
    }
}
