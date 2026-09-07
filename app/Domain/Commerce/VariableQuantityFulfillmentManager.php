<?php

namespace App\Domain\Commerce;

use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryCondition;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\VariableQuantityFulfillment;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VariableQuantityFulfillmentManager
{
    public function materialize(
        int $catalogProductId,
        int $inventoryLocationId,
        InventoryCondition $condition,
        string $requestedQuantity,
        string $measuredQuantity,
        string $acceptedQuantity,
        ?int $inventoryReservationId,
        string $idempotencyKey,
        User $actor
    ): VariableQuantityFulfillment {
        $requestedQuantity = InventoryQuantity::positive(
            $requestedQuantity,
            InventoryQuantity::SCALE,
            'La cantidad solicitada'
        );

        $measuredQuantity = InventoryQuantity::positive(
            $measuredQuantity,
            InventoryQuantity::SCALE,
            'La cantidad medida'
        );

        $acceptedQuantity = InventoryQuantity::positive(
            $acceptedQuantity,
            InventoryQuantity::SCALE,
            'La cantidad aceptada'
        );

        if (
            ! InventoryQuantity::equal(
                $measuredQuantity,
                $acceptedQuantity
            )
        ) {
            throw new DomainException(
                'La cantidad aceptada debe coincidir exactamente con la medición física registrada.'
            );
        }

        $idempotencyKey = trim($idempotencyKey);

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 90
        ) {
            throw new DomainException(
                'La clave de idempotencia del fulfillment no es válida.'
            );
        }

        $organizationId = $this->organizationId($actor);

        $fingerprint = $this->fingerprint(
            $catalogProductId,
            $inventoryLocationId,
            $condition,
            $requestedQuantity,
            $measuredQuantity,
            $acceptedQuantity,
            $inventoryReservationId
        );

        return DB::transaction(function () use (
            $organizationId,
            $catalogProductId,
            $inventoryLocationId,
            $condition,
            $requestedQuantity,
            $measuredQuantity,
            $acceptedQuantity,
            $inventoryReservationId,
            $idempotencyKey,
            $fingerprint,
            $actor
        ): VariableQuantityFulfillment {
            $this->lockOrganization($organizationId);
            $this->guardActor($organizationId, $actor);

            $existing = VariableQuantityFulfillment::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    ! hash_equals(
                        $existing->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La clave de idempotencia del fulfillment ya fue usada con otros datos.'
                    );
                }

                return $existing;
            }

            $product = CatalogProduct::query()
                ->whereKey($catalogProductId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (
                ! $product
                || ! $product->allowsFractionalQuantity()
            ) {
                throw new DomainException(
                    'El fulfillment de cantidad variable requiere un producto fraccionable activo.'
                );
            }

            foreach ([
                'La cantidad solicitada' =>
                    $requestedQuantity,
                'La cantidad medida' =>
                    $measuredQuantity,
                'La cantidad aceptada' =>
                    $acceptedQuantity,
            ] as $label => $quantity) {
                InventoryQuantity::assertFitsScale(
                    $quantity,
                    (int) $product->quantity_scale,
                    $label
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
                    'La ubicación del fulfillment no pertenece a la organización o está inactiva.'
                );
            }

            if ($inventoryReservationId !== null) {
                $reservation = InventoryReservation::query()
                    ->whereKey($inventoryReservationId)
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $reservation
                    || ! $reservation->isEffective()
                ) {
                    throw new DomainException(
                        'La reserva vinculada debe existir y permanecer efectiva.'
                    );
                }

                if (
                    (int) $reservation->catalog_product_id
                        !== $catalogProductId
                    || (int) $reservation
                        ->inventory_location_id
                        !== $inventoryLocationId
                    || $reservation->condition
                        !== $condition
                    || (string) $reservation
                        ->base_unit_code
                        !== (string) $product->base_unit_code
                ) {
                    throw new DomainException(
                        'La reserva vinculada no coincide con la posición física del fulfillment.'
                    );
                }

                if (
                    ! InventoryQuantity::equal(
                        $reservation->quantity,
                        $requestedQuantity
                    )
                ) {
                    throw new DomainException(
                        'La cantidad solicitada debe coincidir con la intención reservada.'
                    );
                }

                if (
                    $reservation->hasFulfillmentTolerance()
                    && ! $reservation
                        ->allowsFulfillmentQuantity(
                            $acceptedQuantity
                        )
                ) {
                    throw new DomainException(
                        'La cantidad medida esta fuera de la tolerancia autorizada por la reserva.'
                    );
                }
            }

            return VariableQuantityFulfillment::query()
                ->create([
                    'organization_id' => $organizationId,
                    'public_id' => (string) Str::uuid(),
                    'inventory_reservation_id' =>
                        $inventoryReservationId,
                    'catalog_product_id' =>
                        $catalogProductId,
                    'inventory_location_id' =>
                        $inventoryLocationId,
                    'condition' => $condition,
                    'requested_quantity' =>
                        $requestedQuantity,
                    'measured_quantity' =>
                        $measuredQuantity,
                    'accepted_quantity' =>
                        $acceptedQuantity,
                    'base_unit_code' =>
                        $product->base_unit_code,
                    'created_by_user_id' => $actor->id,
                    'idempotency_key' =>
                        $idempotencyKey,
                    'fingerprint' => $fingerprint,
                ])
                ->refresh();
        }, 3);
    }

    private function organizationId(User $actor): int
    {
        $organizationId =
            (int) $actor->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(
                'El usuario no posee una organización activa.'
            );
        }

        return $organizationId;
    }

    private function lockOrganization(
        int $organizationId
    ): void {
        if (
            ! DB::table('organizations')
                ->where('id', $organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->exists()
        ) {
            throw new DomainException(
                'La organización no está activa.'
            );
        }
    }

    private function guardActor(
        int $organizationId,
        User $actor
    ): void {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $membership?->role->canRecordCommerceSale()
        ) {
            throw new DomainException(
                'El usuario no puede registrar fulfillment comercial.'
            );
        }
    }

    private function fingerprint(
        int $catalogProductId,
        int $inventoryLocationId,
        InventoryCondition $condition,
        string $requestedQuantity,
        string $measuredQuantity,
        string $acceptedQuantity,
        ?int $inventoryReservationId
    ): string {
        return hash(
            'sha256',
            implode('|', [
                $catalogProductId,
                $inventoryLocationId,
                $condition->value,
                $requestedQuantity,
                $measuredQuantity,
                $acceptedQuantity,
                $inventoryReservationId === null
                    ? ''
                    : (string) $inventoryReservationId,
            ])
        );
    }
}
