<?php

namespace App\Domain\Inventory;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\InventoryCondition;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final class InventoryAvailabilityReader
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    /**
     * @return Collection<int, InventoryAvailabilityPosition>
     */
    public function positions(?User $actor = null): Collection
    {
        $actor ??= auth()->user();

        if (! $actor instanceof User) {
            throw new DomainException(
                'La consulta de disponibilidad requiere autenticación.'
            );
        }

        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canViewInventoryAvailability() ?? false)) {
            throw new DomainException(
                'El usuario no puede consultar disponibilidad en la organización activa.'
            );
        }

        $organizationId = $this->currentOrganization->id($actor);

        return DB::table('inventory_balances as balances')
            ->join(
                'catalog_products as products',
                'products.id',
                '=',
                'balances.catalog_product_id'
            )
            ->join(
                'inventory_locations as locations',
                function ($join): void {
                    $join->on(
                        'locations.id',
                        '=',
                        'balances.inventory_location_id'
                    )->on(
                        'locations.organization_id',
                        '=',
                        'balances.organization_id'
                    );
                }
            )
            ->where('balances.organization_id', $organizationId)
            ->orderBy('products.name')
            ->orderBy('locations.name')
            ->orderBy('balances.condition')
            ->get([
                'balances.organization_id',
                'balances.catalog_product_id',
                'balances.inventory_location_id',
                'balances.condition',
                'balances.quantity',
                'balances.base_unit_code',
                'products.sku as product_sku',
                'products.name as product_name',
                'products.active as product_active',
                'products.quantity_scale',
                'locations.name as location_name',
                'locations.active as location_active',
            ])
            ->map(function (stdClass $balance): InventoryAvailabilityPosition {
                $physical = InventoryQuantity::signed($balance->quantity);

                return new InventoryAvailabilityPosition(
                    organizationId: (int) $balance->organization_id,
                    catalogProductId: (int) $balance->catalog_product_id,
                    productSku: (string) $balance->product_sku,
                    productName: (string) $balance->product_name,
                    productActive: (bool) $balance->product_active,
                    inventoryLocationId: (int) $balance->inventory_location_id,
                    locationName: (string) $balance->location_name,
                    locationActive: (bool) $balance->location_active,
                    condition: InventoryCondition::from(
                        (string) $balance->condition
                    ),
                    physicalQuantity: $physical,
                    availableQuantity:
                        InventoryQuantity::nonNegative($physical),
                    deficitQuantity: InventoryQuantity::deficit($physical),
                    baseUnitCode: (string) $balance->base_unit_code,
                    quantityScale: (int) $balance->quantity_scale,
                );
            })
            ->values();
    }
}
