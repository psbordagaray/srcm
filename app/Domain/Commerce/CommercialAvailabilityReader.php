<?php
namespace App\Domain\Commerce;
use App\Domain\Inventory\InventoryAvailabilityPosition;
use App\Domain\Inventory\InventoryAvailabilityReader;
use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Models\User;
use Illuminate\Support\Collection;
final class CommercialAvailabilityReader
{
    public function __construct(private readonly InventoryAvailabilityReader $physicalAvailability) {}
    /**
     * Foundation V2 derives promiseable quantity from authoritative physical
     * availability minus effective inventory reservations. Holds, channel
     * policy, publishability, protected minimums and backorder/preorder remain unopened.
     * @return Collection<int, CommercialAvailabilityPosition>
     */
    public function positions(?User $actor = null): Collection
    {
        $positions = $this->physicalAvailability->positions($actor)->values();
        if ($positions->isEmpty()) {
            return collect();
        }
        $organizationIds = $positions->pluck('organizationId')->unique()->values()->all();
        $now = now();
        $reserved = [];
        InventoryReservation::query()->whereIn('organization_id', $organizationIds)
            ->where('status', InventoryReservationStatus::Active->value)
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })->orderBy('id')->get()
            ->each(function (InventoryReservation $reservation) use (&$reserved): void {
                $key = $this->key((int) $reservation->organization_id,(int) $reservation->catalog_product_id,
                    (int) $reservation->inventory_location_id,$reservation->condition->value);
                $reserved[$key] = InventoryQuantity::add(
                    $reserved[$key] ?? InventoryQuantity::signed('0'),
                    (string) $reservation->quantity
                );
            });
        return $positions->map(function (InventoryAvailabilityPosition $position) use ($reserved): CommercialAvailabilityPosition {
            $restrictions = [];
            if (! $position->productActive) { $restrictions[] = 'product_inactive'; }
            if (! $position->locationActive) { $restrictions[] = 'location_inactive'; }
            $reservedQuantity = $reserved[$this->key($position->organizationId,$position->catalogProductId,
                $position->inventoryLocationId,$position->condition->value)] ?? InventoryQuantity::signed('0');
            $afterReservations = InventoryQuantity::subtract($position->availableQuantity, $reservedQuantity);
            if (InventoryQuantity::isNegative($afterReservations)) {
                $afterReservations = InventoryQuantity::signed('0');
            }
            $commercialAvailable = $restrictions === [] ? $afterReservations : InventoryQuantity::signed('0');
            return new CommercialAvailabilityPosition(
                organizationId: $position->organizationId,
                catalogProductId: $position->catalogProductId,
                productSku: $position->productSku,
                productName: $position->productName,
                productActive: $position->productActive,
                inventoryLocationId: $position->inventoryLocationId,
                locationName: $position->locationName,
                locationActive: $position->locationActive,
                condition: $position->condition,
                physicalQuantity: $position->physicalQuantity,
                physicalAvailableQuantity: $position->availableQuantity,
                reservedQuantity: $reservedQuantity,
                commercialAvailableQuantity: $commercialAvailable,
                baseUnitCode: $position->baseUnitCode,
                quantityScale: $position->quantityScale,
                balanceVersion: $position->balanceVersion,
                restrictionReasons: $restrictions
            );
        })->values();
    }
    private function key(int $organizationId, int $catalogProductId, int $inventoryLocationId, string $condition): string
    {
        return implode(':', [$organizationId,$catalogProductId,$inventoryLocationId,$condition]);
    }
}