<?php

namespace App\Domain\Purchase;

final readonly class PurchasePaymentGroupItemData
{
    public function __construct(
        public int $purchaseObligationId,
        public int $amountMinor
    ) {
    }
}
