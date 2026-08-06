<?php

namespace App\Domain\Purchase;

final readonly class PurchaseOrderDraftData
{
    /**
     * @param list<PurchaseOrderLineData> $lines
     */
    public function __construct(
        public int $supplierId,
        public string $currencyCode,
        public string $idempotencyKey,
        public array $lines,
        public int $expectedLogisticsCostMinor = 0,
        public ?string $notes = null
    ) {
    }
}
