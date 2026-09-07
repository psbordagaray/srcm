<?php

namespace App\Domain\Commerce;

use App\Enums\FulfillmentUnavailableFallback;
use App\Models\FulfillmentPreference;
use App\Models\InventoryReservation;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FulfillmentPreferenceManager
{
    public function record(
        int $inventoryReservationId,
        bool $allowAnotherBrand,
        bool $allowEquivalentProduct,
        bool $requireExactProduct,
        bool $consultationRequired,
        ?string $consultationChannel,
        FulfillmentUnavailableFallback $unavailableLineFallback,
        string $idempotencyKey,
        User $actor
    ): FulfillmentPreference {
        $organizationId = $this->organizationId($actor);
        $idempotencyKey = trim($idempotencyKey);
        $consultationChannel = $this->normalizeChannel($consultationChannel);

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 90) {
            throw new DomainException(
                'La clave de idempotencia de las preferencias de fulfillment no es válida.'
            );
        }

        if (
            $requireExactProduct
            && ($allowAnotherBrand || $allowEquivalentProduct)
        ) {
            throw new DomainException(
                'Una preferencia de producto exacto no puede autorizar sustituciones.'
            );
        }

        $consultationNeeded = $consultationRequired
            || $unavailableLineFallback === FulfillmentUnavailableFallback::ConsultCustomer;

        if ($consultationNeeded && $consultationChannel === null) {
            throw new DomainException(
                'La consulta al cliente requiere un canal de consulta.'
            );
        }

        $fingerprint = $this->fingerprint(
            $inventoryReservationId,
            $allowAnotherBrand,
            $allowEquivalentProduct,
            $requireExactProduct,
            $consultationRequired,
            $consultationChannel,
            $unavailableLineFallback
        );

        return DB::transaction(function () use (
            $organizationId,
            $inventoryReservationId,
            $allowAnotherBrand,
            $allowEquivalentProduct,
            $requireExactProduct,
            $consultationRequired,
            $consultationChannel,
            $unavailableLineFallback,
            $idempotencyKey,
            $fingerprint,
            $actor
        ): FulfillmentPreference {
            $this->lockOrganization($organizationId);
            $this->guardActor($organizationId, $actor);

            $existing = FulfillmentPreference::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals((string) $existing->fingerprint, $fingerprint)) {
                    throw new DomainException(
                        'La clave de idempotencia ya fue usada con otras preferencias de fulfillment.'
                    );
                }

                return $existing;
            }

            $reservation = InventoryReservation::query()
                ->whereKey($inventoryReservationId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $reservation || ! $reservation->isEffective()) {
                throw new DomainException(
                    'La reserva vinculada a las preferencias debe existir y permanecer efectiva.'
                );
            }

            return FulfillmentPreference::query()->create([
                'organization_id' => $organizationId,
                'public_id' => (string) Str::uuid(),
                'inventory_reservation_id' => $reservation->id,
                'allow_another_brand' => $allowAnotherBrand,
                'allow_equivalent_product' => $allowEquivalentProduct,
                'require_exact_product' => $requireExactProduct,
                'consultation_required' => $consultationRequired,
                'consultation_channel' => $consultationChannel,
                'unavailable_line_fallback' => $unavailableLineFallback,
                'created_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
            ])->refresh();
        }, 3);
    }

    private function organizationId(User $actor): int
    {
        $organizationId = (int) $actor->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(
                'El usuario no posee una organización activa.'
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
                'La organización no está activa.'
            );
        }
    }

    private function guardActor(int $organizationId, User $actor): void
    {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $membership?->role->canRecordCommerceSale()) {
            throw new DomainException(
                'El usuario no puede registrar preferencias de fulfillment.'
            );
        }
    }

    private function normalizeChannel(?string $channel): ?string
    {
        if ($channel === null) {
            return null;
        }

        $channel = strtolower(trim($channel));

        if ($channel === '') {
            return null;
        }

        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $channel) !== 1) {
            throw new DomainException(
                'El canal de consulta de fulfillment no es válido.'
            );
        }

        return $channel;
    }

    private function fingerprint(
        int $inventoryReservationId,
        bool $allowAnotherBrand,
        bool $allowEquivalentProduct,
        bool $requireExactProduct,
        bool $consultationRequired,
        ?string $consultationChannel,
        FulfillmentUnavailableFallback $unavailableLineFallback
    ): string {
        return hash('sha256', implode('|', [
            $inventoryReservationId,
            $allowAnotherBrand ? '1' : '0',
            $allowEquivalentProduct ? '1' : '0',
            $requireExactProduct ? '1' : '0',
            $consultationRequired ? '1' : '0',
            $consultationChannel ?? '',
            $unavailableLineFallback->value,
        ]));
    }
}
