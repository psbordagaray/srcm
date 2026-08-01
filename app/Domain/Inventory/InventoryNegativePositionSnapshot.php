<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryCondition;

final readonly class InventoryNegativePositionSnapshot
{
    public function __construct(
        public int $catalogProductId,
        public int $inventoryLocationId,
        public InventoryCondition $condition,
        public string $currentQuantity,
        public string $requestedQuantity,
        public string $projectedQuantity,
        public string $currentDeficit,
        public string $projectedDeficit,
        public string $incrementalDeficit,
        public string $baseUnitCode,
        public int $balanceVersion,
        public bool $createsNegative,
    ) {
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function canonical(): array
    {
        return [
            'catalog_product_id' => $this->catalogProductId,
            'inventory_location_id' => $this->inventoryLocationId,
            'condition' => $this->condition->value,
            'current_quantity' => $this->currentQuantity,
            'requested_quantity' => $this->requestedQuantity,
            'projected_quantity' => $this->projectedQuantity,
            'current_deficit' => $this->currentDeficit,
            'projected_deficit' => $this->projectedDeficit,
            'incremental_deficit' => $this->incrementalDeficit,
            'base_unit_code' => $this->baseUnitCode,
            'balance_version' => $this->balanceVersion,
            'creates_negative' => $this->createsNegative,
        ];
    }
}
