<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryCondition;

final readonly class InventoryMovementLineData
{
    public function __construct(
        public int $catalogProductId,
        public InventoryCondition $condition,
        public string $enteredQuantity,
        public string $enteredUnitCode,
        public string $conversionFactor = '1',
        public ?int $sourceLocationId = null,
        public ?int $destinationLocationId = null,
        public ?string $notes = null
    ) {
    }
}
