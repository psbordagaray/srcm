<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryNegativeRequestStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryNegativeRequest;
use App\Models\InventoryNegativeRequestLine;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class InventoryNegativeRequestManager
{
    public function __construct(
        private readonly InventoryNegativeSnapshotBuilder $snapshots
    ) {
    }

    public function request(
        InventoryMovement|int $movement,
        string $reason,
        User $actor
    ): InventoryNegativeRequest {
        $movementId = $movement instanceof InventoryMovement
            ? (int) $movement->getKey()
            : $movement;
        $normalizedReason = $this->reason($reason);

        return DB::transaction(function () use (
            $movementId,
            $normalizedReason,
            $actor
        ): InventoryNegativeRequest {
            $organizationId = (int) $actor->current_organization_id;
            $this->lockActiveOrganization($organizationId);

            $lockedMovement = InventoryMovement::query()
                ->whereKey($movementId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $lockedMovement) {
                throw new DomainException(
                    'El movimiento no existe en la organización activa.'
                );
            }

            $this->guardActor($lockedMovement, $actor);
            $snapshot = $this->snapshots->build($lockedMovement);

            if (! $snapshot->requiresOverride()) {
                throw new DomainException(
                    'El movimiento no requiere una autorización de stock negativo.'
                );
            }

            $fingerprint = $this->fingerprint([
                'organization_id' => $organizationId,
                'inventory_movement_id' => $lockedMovement->id,
                'requested_by_user_id' => $actor->id,
                'reason' => $normalizedReason,
                'movement_fingerprint' =>
                    $snapshot->movementFingerprint,
                'snapshot_fingerprint' =>
                    $snapshot->snapshotFingerprint,
            ]);

            $existing = InventoryNegativeRequest::query()
                ->where('organization_id', $organizationId)
                ->where('request_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->load(['lines', 'override']);
            }

            $request = InventoryNegativeRequest::query()->create([
                'organization_id' => $organizationId,
                'inventory_movement_id' => $lockedMovement->id,
                'requested_by_user_id' => $actor->id,
                'status' => InventoryNegativeRequestStatus::Pending,
                'reason' => $normalizedReason,
                'movement_fingerprint' =>
                    $snapshot->movementFingerprint,
                'snapshot_fingerprint' =>
                    $snapshot->snapshotFingerprint,
                'request_fingerprint' => $fingerprint,
                'requested_at' => now(),
            ]);

            foreach ($snapshot->positions as $index => $position) {
                InventoryNegativeRequestLine::query()->create([
                    'organization_id' => $organizationId,
                    'inventory_negative_request_id' => $request->id,
                    'sequence' => $index + 1,
                    'catalog_product_id' =>
                        $position->catalogProductId,
                    'inventory_location_id' =>
                        $position->inventoryLocationId,
                    'condition' => $position->condition,
                    'current_quantity' => $position->currentQuantity,
                    'requested_quantity' =>
                        $position->requestedQuantity,
                    'incoming_quantity' =>
                        $position->incomingQuantity,
                    'projected_quantity' =>
                        $position->projectedQuantity,
                    'current_deficit' => $position->currentDeficit,
                    'projected_deficit' =>
                        $position->projectedDeficit,
                    'incremental_deficit' =>
                        $position->incrementalDeficit,
                    'base_unit_code' => $position->baseUnitCode,
                    'balance_version' => $position->balanceVersion,
                    'creates_negative' => $position->createsNegative,
                ]);
            }

            return $request->load('lines');
        }, 3);
    }

    private function guardActor(
        InventoryMovement $movement,
        User $actor
    ): void {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $movement->organization_id)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $membership
            || ! $membership->role->canRequestInventoryNegative()
            || ! $membership->role->canConfirmInventoryMovement(
                $movement->type
            )
        ) {
            throw new DomainException(
                'El rol del usuario no puede solicitar esta autorización.'
            );
        }

        if ((int) $movement->created_by_user_id !== (int) $actor->id) {
            throw new DomainException(
                'La autorización debe solicitarla quien creó el movimiento.'
            );
        }
    }

    private function lockActiveOrganization(int $organizationId): void
    {
        if ($organizationId <= 0) {
            throw new DomainException(
                'El usuario no posee una organización activa.'
            );
        }

        $organization = DB::table('organizations')
            ->where('id', $organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->first(['id']);

        if (! $organization) {
            throw new DomainException(
                'La organización no está activa.'
            );
        }
    }

    private function reason(string $reason): string
    {
        $reason = Str::of($reason)->squish()->toString();

        if ($reason === '' || Str::length($reason) > 2000) {
            throw new DomainException(
                'La solicitud requiere un motivo válido.'
            );
        }

        return $reason;
    }

    /**
     * @param array<string, int|string> $data
     */
    private function fingerprint(array $data): string
    {
        try {
            return hash('sha256', json_encode(
                $data,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            ));
        } catch (JsonException $exception) {
            throw new DomainException(
                'No pudo construirse la huella de la solicitud.',
                previous: $exception
            );
        }
    }
}
