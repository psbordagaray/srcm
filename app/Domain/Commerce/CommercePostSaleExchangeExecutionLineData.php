<?php

namespace App\Domain\Commerce;

use App\Enums\InventoryCondition;

final readonly class CommercePostSaleExchangeExecutionLineData
{
    public function __construct(
        public int $commercePostSaleExchangeSelectionLineId,
        public int $sourceLocationId,
        public InventoryCondition $condition
    ) {
    }
}
