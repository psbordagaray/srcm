<?php

namespace App\Domain\Purchase;

final readonly class PurchasePaymentGroupRequestData
{
    /**
     * @param array<int,PurchasePaymentGroupItemData> $items
     */
    public function __construct(
        public int $originFinancialAccountId,
        public array $items,
        public string $idempotencyKey,
        public ?string $requestNote = null
    ) {
    }
}
