<?php

namespace App\Domain\Purchase;

final readonly class PurchasePaymentRequestData
{
    public function __construct(
        public int $purchaseObligationId,
        public int $originFinancialAccountId,
        public int $amountMinor,
        public ?string $requestNote,
        public string $idempotencyKey
    ) {
    }
}
