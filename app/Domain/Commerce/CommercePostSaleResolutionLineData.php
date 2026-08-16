<?php

namespace App\Domain\Commerce;

final readonly class CommercePostSaleResolutionLineData
{
    public function __construct(
        public int $commercePostSaleReceiptLineId,
        public string $quantity,
        public int $recognizedAmountMinor,
        public ?string $adjustmentReason = null
    ) {
    }
}
