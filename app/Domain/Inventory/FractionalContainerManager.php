<?php

namespace App\Domain\Inventory;

use App\Enums\FractionalContainerState;
use App\Enums\InventoryCondition;
use App\Models\CatalogProduct;
use App\Models\FractionalContainer;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\ProductPresentation;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FractionalContainerManager
{
    public function register(
        int $organizationId,
        int $catalogProductId,
        int $inventoryLocationId,
        string $containerCode,
        mixed $originalBaseQuantity,
        InventoryCondition $condition = InventoryCondition::New,
        ?int $productPresentationId = null
    ): FractionalContainer {
        $normalizedCode =
            FractionalContainer::normalizeContainerCode($containerCode);

        if ($normalizedCode === '') {
            throw new DomainException(
                'El código físico del contenedor no es válido.'
            );
        }

        return DB::transaction(function () use (
            $organizationId,
            $catalogProductId,
            $inventoryLocationId,
            $containerCode,
            $normalizedCode,
            $originalBaseQuantity,
            $condition,
            $productPresentationId
        ): FractionalContainer {
            $organization = Organization::query()
                ->whereKey($organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $organization) {
                throw new DomainException(
                    'La organización del contenedor no está activa.'
                );
            }

            $product = CatalogProduct::query()
                ->whereKey($catalogProductId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $product || ! $product->allowsFractionalQuantity()) {
                throw new DomainException(
                    'El contenedor requiere un producto activo fraccionable.'
                );
            }

            $location = InventoryLocation::query()
                ->whereKey($inventoryLocationId)
                ->where('organization_id', $organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $location) {
                throw new DomainException(
                    'La ubicación activa del contenedor no pertenece '
                    .'a la organización.'
                );
            }

            $quantity = InventoryQuantity::positive(
                $originalBaseQuantity,
                InventoryQuantity::SCALE,
                'La cantidad original del contenedor'
            );

            InventoryQuantity::assertFitsScale(
                $quantity,
                (int) $product->quantity_scale,
                'La cantidad original del contenedor'
            );

            $presentation = null;

            if ($productPresentationId !== null) {
                $presentation = ProductPresentation::query()
                    ->whereKey($productPresentationId)
                    ->where('organization_id', $organizationId)
                    ->where('catalog_product_id', $catalogProductId)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $presentation) {
                    throw new DomainException(
                        'La presentación física no pertenece al mismo '
                        .'producto y organización.'
                    );
                }

                if (
                    ! InventoryQuantity::equal(
                        $presentation->toBaseQuantity('1'),
                        $quantity
                    )
                ) {
                    throw new DomainException(
                        'La presentación física no representa la capacidad '
                        .'original del contenedor.'
                    );
                }
            }

            $existing = FractionalContainer::query()
                ->forOrganization($organizationId)
                ->where(
                    'normalized_container_code',
                    $normalizedCode
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    (int) $existing->catalog_product_id
                        === $catalogProductId
                    && (int) $existing->inventory_location_id
                        === $inventoryLocationId
                    && (
                        $existing->product_presentation_id === null
                            ? $productPresentationId === null
                            : (int) $existing->product_presentation_id
                                === $productPresentationId
                    )
                    && $existing->condition === $condition
                    && $existing->state
                        === FractionalContainerState::Sealed
                    && InventoryQuantity::equal(
                        $existing->original_base_quantity,
                        $quantity
                    )
                    && InventoryQuantity::equal(
                        $existing->remaining_base_quantity,
                        $quantity
                    )
                    && (string) $existing->base_unit_code
                        === (string) $product->base_unit_code
                    && (int) $existing->base_quantity_scale
                        === (int) $product->quantity_scale
                ) {
                    return $existing;
                }

                throw new DomainException(
                    'El código físico ya existe con un contrato '
                    .'de contenedor diferente.'
                );
            }

            return FractionalContainer::query()->create([
                'organization_id' => $organizationId,
                'catalog_product_id' => $catalogProductId,
                'product_presentation_id' => $presentation?->id,
                'inventory_location_id' => $inventoryLocationId,
                'container_code' => $containerCode,
                'condition' => $condition,
                'state' => FractionalContainerState::Sealed,
                'original_base_quantity' => $quantity,
                'remaining_base_quantity' => $quantity,
                'base_unit_code' => $product->base_unit_code,
                'base_quantity_scale' => (int) $product->quantity_scale,
            ])->refresh();
        }, 3);
    }
}
