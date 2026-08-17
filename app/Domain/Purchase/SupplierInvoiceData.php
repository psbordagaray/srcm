<?php

namespace App\Domain\Purchase;

final readonly class SupplierInvoiceData
{
    /**
     * @param list<SupplierInvoiceLineData> $lines
     */
    public function __construct(
        public int $purchaseOrderId,
        public string $documentNumber,
        public string $issuedOn,
        public ?string $dueOn,
        public int $logisticsAmountMinor,
        public array $lines,
        public string $idempotencyKey,
        public ?string $notes = null
    ) {
    }
}
