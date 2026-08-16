<?php

namespace App\Domain\Commerce;

use App\Enums\InventoryCondition;

final readonly class CommercePostSaleReceiptLineData
{
    public function __construct(
        public int $commercePostSaleRequestLineId,
        public string $quantity,
        public InventoryCondition $condition,
        public int $destinationLocationId,
        public ?string $notes = null
    ) {
    }
}
