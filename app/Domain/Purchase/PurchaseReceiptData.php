<?php

namespace App\Domain\Purchase;

use DateTimeInterface;

final readonly class PurchaseReceiptData
{
    /**
     * @param list<PurchaseReceiptLineData> $lines
     */
    public function __construct(
        public int $purchaseOrderId,
        public DateTimeInterface $receivedAt,
        public string $idempotencyKey,
        public array $lines,
        public int $logisticsCostMinor = 0,
        public ?string $documentReference = null,
        public ?string $notes = null
    ) {
    }
}
