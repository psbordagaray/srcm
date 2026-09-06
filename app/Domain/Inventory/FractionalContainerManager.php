<?php

namespace App\Domain\Inventory;

use App\Enums\FractionalContainerState;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Models\CatalogProduct;
use App\Models\FractionalContainer;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
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

    public function registerFromReceiptLine(
        int $receiptLineId,
        string $containerCode,
        mixed $originalBaseQuantity,
        ?int $productPresentationId = null
    ): FractionalContainer {
        $normalizedCode =
            FractionalContainer::normalizeContainerCode(
                $containerCode
            );

        if ($normalizedCode === '') {
            throw new DomainException(
                'El código físico del contenedor no es válido.'
            );
        }

        return DB::transaction(function () use (
            $receiptLineId,
            $containerCode,
            $normalizedCode,
            $originalBaseQuantity,
            $productPresentationId
        ): FractionalContainer {
            $identity = InventoryMovementLine::query()
                ->whereKey($receiptLineId)
                ->first([
                    'organization_id',
                    'inventory_movement_id',
                ]);

            if (! $identity) {
                throw new DomainException(
                    'La línea de recepción no existe.'
                );
            }

            $organizationId =
                (int) $identity->organization_id;
            $movementId =
                (int) $identity->inventory_movement_id;

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

            $movement = InventoryMovement::query()
                ->whereKey($movementId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (
                ! $movement
                || $movement->type
                    !== InventoryMovementType::Receipt
                || $movement->status
                    !== InventoryMovementStatus::Confirmed
            ) {
                throw new DomainException(
                    'La procedencia física requiere una recepción '
                    .'confirmada del ledger.'
                );
            }

            $line = InventoryMovementLine::query()
                ->whereKey($receiptLineId)
                ->where('organization_id', $organizationId)
                ->where(
                    'inventory_movement_id',
                    $movement->id
                )
                ->lockForUpdate()
                ->first();

            if (
                ! $line
                || $line->source_location_id !== null
                || $line->destination_location_id === null
            ) {
                throw new DomainException(
                    'La línea no representa una recepción '
                    .'a una ubicación física de destino.'
                );
            }

            $product = CatalogProduct::query()
                ->whereKey($line->catalog_product_id)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (
                ! $product
                || ! $product->allowsFractionalQuantity()
            ) {
                throw new DomainException(
                    'La recepción requiere un producto '
                    .'activo fraccionable.'
                );
            }

            if (
                (string) $line->base_unit_code
                    !== (string) $product->base_unit_code
            ) {
                throw new DomainException(
                    'La unidad base de la recepción no coincide '
                    .'con el producto.'
                );
            }

            $location = InventoryLocation::query()
                ->whereKey($line->destination_location_id)
                ->where('organization_id', $organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $location) {
                throw new DomainException(
                    'La ubicación de recepción no pertenece '
                    .'a la organización activa.'
                );
            }

            $lineQuantity = InventoryQuantity::positive(
                $line->base_quantity,
                InventoryQuantity::SCALE,
                'La cantidad base recibida'
            );

            InventoryQuantity::assertFitsScale(
                $lineQuantity,
                (int) $product->quantity_scale,
                'La cantidad base recibida'
            );

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

            if (
                ! InventoryQuantity::lessThanOrEqual(
                    $quantity,
                    $lineQuantity
                )
            ) {
                throw new DomainException(
                    'El contenedor no puede exceder la cantidad '
                    .'base recibida por la línea.'
                );
            }

            $presentation = null;

            if ($productPresentationId !== null) {
                $presentation = ProductPresentation::query()
                    ->whereKey($productPresentationId)
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'catalog_product_id',
                        $product->id
                    )
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $presentation) {
                    throw new DomainException(
                        'La presentación física no pertenece '
                        .'a la recepción y producto.'
                    );
                }

                if (
                    ! InventoryQuantity::equal(
                        $presentation->toBaseQuantity('1'),
                        $quantity
                    )
                ) {
                    throw new DomainException(
                        'La presentación física no representa '
                        .'la capacidad original del contenedor.'
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
                        === (int) $product->id
                    && (int) $existing->inventory_location_id
                        === (int) $location->id
                    && (
                        $existing->product_presentation_id === null
                            ? $productPresentationId === null
                            : (int) $existing
                                ->product_presentation_id
                                === $productPresentationId
                    )
                    && (int) $existing
                        ->received_inventory_movement_line_id
                        === (int) $line->id
                    && $existing->condition === $line->condition
                    && InventoryQuantity::equal(
                        $existing->original_base_quantity,
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
                    'El código físico ya existe con una '
                    .'procedencia o contrato diferente.'
                );
            }

            $boundContainers = FractionalContainer::query()
                ->forOrganization($organizationId)
                ->where(
                    'received_inventory_movement_line_id',
                    $line->id
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['original_base_quantity']);

            $boundQuantity = InventoryQuantity::signed('0');

            foreach ($boundContainers as $boundContainer) {
                $boundQuantity = InventoryQuantity::add(
                    $boundQuantity,
                    $boundContainer->original_base_quantity
                );
            }

            $projectedBoundQuantity = InventoryQuantity::add(
                $boundQuantity,
                $quantity
            );

            if (
                ! InventoryQuantity::lessThanOrEqual(
                    $projectedBoundQuantity,
                    $lineQuantity
                )
            ) {
                throw new DomainException(
                    'La suma de contenedores físicos no puede '
                    .'exceder la cantidad confirmada de la recepción.'
                );
            }

            return FractionalContainer::query()->create([
                'organization_id' => $organizationId,
                'catalog_product_id' => $product->id,
                'product_presentation_id' =>
                    $presentation?->id,
                'inventory_location_id' => $location->id,
                'received_inventory_movement_line_id' =>
                    $line->id,
                'container_code' => $containerCode,
                'condition' => $line->condition,
                'state' => FractionalContainerState::Sealed,
                'original_base_quantity' => $quantity,
                'remaining_base_quantity' => $quantity,
                'base_unit_code' => $product->base_unit_code,
                'base_quantity_scale' =>
                    (int) $product->quantity_scale,
            ])->refresh();
        }, 3);
    }
}
