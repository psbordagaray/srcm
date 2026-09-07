<?php

namespace App\Domain\Commerce;

use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryCondition;
use App\Enums\InventoryReservationStatus;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InventoryReservationManager
{
    public function __construct(
        private readonly CommercialAvailabilityReader $availability
    ) {}

    public function reserve(
        int $catalogProductId,
        int $inventoryLocationId,
        InventoryCondition $condition,
        string $quantity,
        ?CarbonImmutable $expiresAt,
        string $idempotencyKey,
        User $actor,
        ?string $minimumFulfillmentQuantity = null,
        ?string $maximumFulfillmentQuantity = null
    ): InventoryReservation {
        $quantity = InventoryQuantity::positive($quantity);

        $minimumFulfillmentQuantity = $this->normalizeToleranceBound(
            $minimumFulfillmentQuantity,
            'El minimo de fulfillment'
        );

        $maximumFulfillmentQuantity = $this->normalizeToleranceBound(
            $maximumFulfillmentQuantity,
            'El maximo de fulfillment'
        );

        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 90) {
            throw new DomainException(
                'La clave de idempotencia de reserva es invalida.'
            );
        }

        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw new DomainException(
                'La reserva debe vencer en el futuro.'
            );
        }

        $organizationId = $this->organizationId($actor);

        $fingerprint = $this->fingerprint(
            $catalogProductId,
            $inventoryLocationId,
            $condition,
            $quantity,
            $expiresAt,
            $minimumFulfillmentQuantity,
            $maximumFulfillmentQuantity
        );

        return DB::transaction(function () use (
            $organizationId,
            $catalogProductId,
            $inventoryLocationId,
            $condition,
            $quantity,
            $expiresAt,
            $idempotencyKey,
            $fingerprint,
            $actor,
            $minimumFulfillmentQuantity,
            $maximumFulfillmentQuantity
        ): InventoryReservation {
            $this->lockOrganization($organizationId);
            $this->guardActor($organizationId, $actor);

            $existing = InventoryReservation::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new DomainException(
                        'La clave de idempotencia de reserva ya fue usada con otros datos.'
                    );
                }

                return $existing;
            }

            $product = CatalogProduct::query()
                ->whereKey($catalogProductId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $product) {
                throw new DomainException(
                    'El producto de la reserva no existe o esta inactivo.'
                );
            }

            $this->guardQuantityScale(
                $quantity,
                (int) $product->quantity_scale
            );

            $this->guardToleranceEnvelope(
                $product,
                $quantity,
                $minimumFulfillmentQuantity,
                $maximumFulfillmentQuantity
            );

            $location = InventoryLocation::query()
                ->where('organization_id', $organizationId)
                ->whereKey($inventoryLocationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $location) {
                throw new DomainException(
                    'La ubicacion de la reserva no pertenece a la organizacion o esta inactiva.'
                );
            }

            $balance = InventoryBalance::query()
                ->where('organization_id', $organizationId)
                ->where('catalog_product_id', $catalogProductId)
                ->where('inventory_location_id', $inventoryLocationId)
                ->where('condition', $condition->value)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                throw new DomainException(
                    'No existe saldo fisico para la posicion solicitada.'
                );
            }

            $position = $this->availability->positions($actor)->first(
                fn (CommercialAvailabilityPosition $position): bool =>
                    $position->catalogProductId === $catalogProductId
                    && $position->inventoryLocationId === $inventoryLocationId
                    && $position->condition === $condition
            );

            $available = $position?->commercialAvailableQuantity
                ?? InventoryQuantity::signed('0');

            $holdQuantity = $maximumFulfillmentQuantity
                ?? $quantity;

            if (
                InventoryQuantity::isNegative(
                    InventoryQuantity::subtract(
                        $available,
                        $holdQuantity
                    )
                )
            ) {
                throw new DomainException(
                    'La cantidad solicitada y su tolerancia superan la disponibilidad comercial.'
                );
            }

            return InventoryReservation::query()->create([
                'organization_id' => $organizationId,
                'public_id' => (string) Str::uuid(),
                'catalog_product_id' => $catalogProductId,
                'inventory_location_id' => $inventoryLocationId,
                'condition' => $condition,
                'quantity' => $quantity,
                'minimum_fulfillment_quantity' =>
                    $minimumFulfillmentQuantity,
                'maximum_fulfillment_quantity' =>
                    $maximumFulfillmentQuantity,
                'base_unit_code' => $product->base_unit_code,
                'status' => InventoryReservationStatus::Active,
                'expires_at' => $expiresAt,
                'released_at' => null,
                'release_reason' => null,
                'created_by_user_id' => $actor->id,
                'released_by_user_id' => null,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
            ])->refresh();
        }, 3);
    }

    public function release(
        InventoryReservation $reservation,
        ?string $reason,
        User $actor
    ): InventoryReservation {
        $organizationId = $this->organizationId($actor);
        $reason = $reason === null ? null : trim($reason);

        if ($reason !== null && mb_strlen($reason) > 2000) {
            throw new DomainException(
                'El motivo de liberacion supera la longitud admitida.'
            );
        }

        return DB::transaction(function () use (
            $reservation,
            $organizationId,
            $reason,
            $actor
        ): InventoryReservation {
            $this->lockOrganization($organizationId);
            $this->guardActor($organizationId, $actor);

            $locked = InventoryReservation::query()
                ->where('organization_id', $organizationId)
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La reserva no pertenece a la organizacion activa.'
                );
            }

            if ($locked->status === InventoryReservationStatus::Released) {
                return $locked;
            }

            $locked->status = InventoryReservationStatus::Released;
            $locked->released_at = CarbonImmutable::now();
            $locked->release_reason = $reason === '' ? null : $reason;
            $locked->released_by_user_id = $actor->id;
            $locked->save();

            return $locked->refresh();
        }, 3);
    }

    private function normalizeToleranceBound(
        ?string $value,
        string $label
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            throw new DomainException(
                $label.' no puede ser vacio cuando se informa.'
            );
        }

        return InventoryQuantity::positive(
            $value,
            InventoryQuantity::SCALE,
            $label
        );
    }

    private function guardToleranceEnvelope(
        CatalogProduct $product,
        string $requestedQuantity,
        ?string $minimumFulfillmentQuantity,
        ?string $maximumFulfillmentQuantity
    ): void {
        if (
            $minimumFulfillmentQuantity === null
            && $maximumFulfillmentQuantity === null
        ) {
            return;
        }

        if (! $product->allowsFractionalQuantity()) {
            throw new DomainException(
                'La tolerancia de fulfillment requiere un producto fraccionable.'
            );
        }

        if (
            $minimumFulfillmentQuantity !== null
            && $maximumFulfillmentQuantity === null
        ) {
            throw new DomainException(
                'El minimo de fulfillment requiere un maximo explicito.'
            );
        }

        if ($minimumFulfillmentQuantity !== null) {
            InventoryQuantity::assertFitsScale(
                $minimumFulfillmentQuantity,
                (int) $product->quantity_scale,
                'El minimo de fulfillment'
            );

            if (
                ! InventoryQuantity::lessThanOrEqual(
                    $minimumFulfillmentQuantity,
                    $requestedQuantity
                )
            ) {
                throw new DomainException(
                    'El minimo de fulfillment no puede superar la cantidad solicitada.'
                );
            }
        }

        if ($maximumFulfillmentQuantity !== null) {
            InventoryQuantity::assertFitsScale(
                $maximumFulfillmentQuantity,
                (int) $product->quantity_scale,
                'El maximo de fulfillment'
            );

            if (
                ! InventoryQuantity::lessThanOrEqual(
                    $requestedQuantity,
                    $maximumFulfillmentQuantity
                )
            ) {
                throw new DomainException(
                    'El maximo de fulfillment no puede ser menor que la cantidad solicitada.'
                );
            }
        }
    }

    private function guardQuantityScale(
        string $quantity,
        int $scale
    ): void {
        $scale = max(
            0,
            min(InventoryQuantity::SCALE, $scale)
        );

        [, $fraction] = array_pad(
            explode('.', $quantity, 2),
            2,
            ''
        );

        $fraction = str_pad(
            $fraction,
            InventoryQuantity::SCALE,
            '0'
        );

        if (trim(substr($fraction, $scale), '0') !== '') {
            throw new DomainException(
                'La cantidad reservada supera la precision admitida para el producto.'
            );
        }
    }

    private function organizationId(User $actor): int
    {
        $organizationId = (int) $actor->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(
                'El usuario no posee una organizacion activa.'
            );
        }

        return $organizationId;
    }

    private function lockOrganization(int $organizationId): void
    {
        if (
            ! DB::table('organizations')
                ->where('id', $organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->exists()
        ) {
            throw new DomainException(
                'La organizacion no esta activa.'
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

        if (! $membership?->role->canRecordCommerceSale()) {
            throw new DomainException(
                'El usuario no puede gestionar reservas comerciales.'
            );
        }
    }

    private function fingerprint(
        int $catalogProductId,
        int $inventoryLocationId,
        InventoryCondition $condition,
        string $quantity,
        ?CarbonImmutable $expiresAt,
        ?string $minimumFulfillmentQuantity,
        ?string $maximumFulfillmentQuantity
    ): string {
        $parts = [
            $catalogProductId,
            $inventoryLocationId,
            $condition->value,
            $quantity,
            $expiresAt?->toIso8601String() ?? '',
        ];

        if (
            $minimumFulfillmentQuantity !== null
            || $maximumFulfillmentQuantity !== null
        ) {
            $parts[] = $minimumFulfillmentQuantity ?? '';
            $parts[] = $maximumFulfillmentQuantity ?? '';
        }

        return hash(
            'sha256',
            implode('|', $parts)
        );
    }
}
