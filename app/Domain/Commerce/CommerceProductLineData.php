<?php

namespace App\Domain\Commerce;

use App\Enums\InventoryCondition;

final readonly class CommerceProductLineData
{
    public function __construct(
        public int $catalogProductId,
        public int $sourceLocationId,
        public InventoryCondition $condition,
        public string $quantity,
        public int $unitPriceMinor,
        public ?string $description = null
    ) {
    }
}
