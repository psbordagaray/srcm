<?php

namespace App\Domain\Purchase;

final readonly class SupplierCreditNoteData
{
    public function __construct(
        public int $supplierInvoiceId,
        public string $documentNumber,
        public string $issuedOn,
        public int $amountMinor,
        public string $reason,
        public string $idempotencyKey,
        public ?string $notes = null
    ) {
    }
}
