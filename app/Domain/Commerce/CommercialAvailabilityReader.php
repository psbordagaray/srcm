<?php

namespace App\Domain\Commerce;

use App\Domain\Inventory\InventoryAvailabilityPosition;
use App\Domain\Inventory\InventoryAvailabilityReader;
use App\Domain\Inventory\InventoryQuantity;
use App\Models\User;
use Illuminate\Support\Collection;

final class CommercialAvailabilityReader
{
    public function __construct(
        private readonly InventoryAvailabilityReader $physicalAvailability
    ) {
    }

    /**
     * Foundation V1 deliberately derives commercial availability only from
     * already-authoritative physical inventory plus existing active-state
     * restrictions. Reservations, holds, channels, publishability, protected
     * minimums and backorder/preorder do not exist yet and are not simulated.
     *
     * @return Collection<int, CommercialAvailabilityPosition>
     */
    public function positions(?User $actor = null): Collection
    {
        return $this->physicalAvailability
            ->positions($actor)
            ->map(
                function (
                    InventoryAvailabilityPosition $position
                ): CommercialAvailabilityPosition {
                    $restrictions = [];

                    if (! $position->productActive) {
                        $restrictions[] = 'product_inactive';
                    }

                    if (! $position->locationActive) {
                        $restrictions[] = 'location_inactive';
                    }

                    $commercialAvailable = $restrictions === []
                        ? $position->availableQuantity
                        : InventoryQuantity::signed('0');

                    return new CommercialAvailabilityPosition(
                        organizationId: $position->organizationId,
                        catalogProductId: $position->catalogProductId,
                        productSku: $position->productSku,
                        productName: $position->productName,
                        productActive: $position->productActive,
                        inventoryLocationId:
                            $position->inventoryLocationId,
                        locationName: $position->locationName,
                        locationActive: $position->locationActive,
                        condition: $position->condition,
                        physicalQuantity: $position->physicalQuantity,
                        physicalAvailableQuantity:
                            $position->availableQuantity,
                        commercialAvailableQuantity:
                            $commercialAvailable,
                        baseUnitCode: $position->baseUnitCode,
                        quantityScale: $position->quantityScale,
                        balanceVersion: $position->balanceVersion,
                        restrictionReasons: $restrictions
                    );
                }
            )
            ->values();
    }
}