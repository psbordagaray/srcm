<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryCondition;

final readonly class InventoryAvailabilityPosition
{
    public function __construct(
        public int $organizationId,
        public int $catalogProductId,
        public string $productSku,
        public string $productName,
        public bool $productActive,
        public int $inventoryLocationId,
        public string $locationName,
        public bool $locationActive,
        public InventoryCondition $condition,
        public string $physicalQuantity,
        public string $availableQuantity,
        public string $deficitQuantity,
        public string $baseUnitCode,
        public int $quantityScale,
        public int $balanceVersion,
    ) {
    }

    public function hasDeficit(): bool
    {
        return ! InventoryQuantity::equal($this->deficitQuantity, '0');
    }

}
