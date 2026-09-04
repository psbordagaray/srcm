<?php

namespace App\Domain\Commerce;

use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryCondition;

final readonly class CommercialAvailabilityPosition
{
    /**
     * @param list<string> $restrictionReasons
     */
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
        public string $physicalAvailableQuantity,
        public string $commercialAvailableQuantity,
        public string $baseUnitCode,
        public int $quantityScale,
        public int $balanceVersion,
        public array $restrictionReasons
    ) {
    }

    public function isPromiseable(): bool
    {
        return $this->restrictionReasons === []
            && InventoryQuantity::isPositive(
                $this->commercialAvailableQuantity
            );
    }

    public function hasCommercialRestriction(): bool
    {
        return $this->restrictionReasons !== [];
    }
}