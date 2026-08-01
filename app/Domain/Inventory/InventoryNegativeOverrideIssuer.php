<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryNegativeOverrideStatus;
use App\Enums\InventoryNegativeRequestStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryNegativeOverride;
use App\Models\InventoryNegativeRequest;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InventoryNegativeOverrideIssuer
{
    public function __construct(
        private readonly InventoryNegativeSnapshotBuilder $snapshots
    ) {
    }

    public function issue(
        InventoryNegativeRequest|int $request,
        User $administrator
    ): InventoryNegativeOverrideIssuance {
        $requestId = $request instanceof InventoryNegativeRequest
            ? (int) $request->getKey()
            : $request;

        return DB::transaction(function () use (
            $requestId,
            $administrator
        ): InventoryNegativeOverrideIssuance {
            $organizationId = (int) $administrator
                ->current_organization_id;
            $this->lockActiveOrganization($organizationId);
            $this->guardAdministrator($organizationId, $administrator);

            $lockedRequest = InventoryNegativeRequest::query()
                ->whereKey($requestId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $lockedRequest) {
                throw new DomainException(
                    'La solicitud no existe en la organización activa.'
                );
            }

            if (
                $lockedRequest->status
                    === InventoryNegativeRequestStatus::Approved
            ) {
                $existing = InventoryNegativeOverride::query()
                    ->where('organization_id', $organizationId)
                    ->where(
                        'inventory_negative_request_id',
                        $lockedRequest->id
                    )
                    ->lockForUpdate()
                    ->first();

                if (! $existing) {
                    throw new DomainException(
                        'La solicitud aprobada no posee su Override.'
                    );
                }

                return new InventoryNegativeOverrideIssuance(
                    request: $lockedRequest->load('lines'),
                    override: $existing,
                    invalidated: false
                );
            }

            if (
                $lockedRequest->status
                    !== InventoryNegativeRequestStatus::Pending
            ) {
                throw new DomainException(
                    'Solo una solicitud pendiente puede autorizarse.'
                );
            }

            $movement = InventoryMovement::query()
                ->whereKey($lockedRequest->inventory_movement_id)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (
                ! $movement
                || $movement->status !== InventoryMovementStatus::Draft
            ) {
                return $this->invalidate(
                    $lockedRequest,
                    'El movimiento dejó de estar disponible como borrador.'
                );
            }

            $requesterMembership = OrganizationMembership::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $lockedRequest->requested_by_user_id)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (
                ! $requesterMembership
                || ! $requesterMembership->role
                    ->canRequestInventoryNegative()
                || ! $requesterMembership->role
                    ->canConfirmInventoryMovement($movement->type)
                || (int) $movement->created_by_user_id
                    !== (int) $lockedRequest->requested_by_user_id
            ) {
                return $this->invalidate(
                    $lockedRequest,
                    'El solicitante ya no puede ejecutar el movimiento.'
                );
            }

            $snapshot = $this->snapshots->build($movement);

            if (
                ! $snapshot->requiresOverride()
                || ! hash_equals(
                    $lockedRequest->movement_fingerprint,
                    $snapshot->movementFingerprint
                )
                || ! hash_equals(
                    $lockedRequest->snapshot_fingerprint,
                    $snapshot->snapshotFingerprint
                )
            ) {
                return $this->invalidate(
                    $lockedRequest,
                    'El movimiento o los saldos relevantes cambiaron.'
                );
            }

            $override = InventoryNegativeOverride::query()->create([
                'organization_id' => $organizationId,
                'inventory_negative_request_id' => $lockedRequest->id,
                'inventory_movement_id' => $movement->id,
                'authorized_user_id' =>
                    $lockedRequest->requested_by_user_id,
                'granted_by_user_id' => $administrator->id,
                'status' => InventoryNegativeOverrideStatus::Active,
                'movement_fingerprint' =>
                    $snapshot->movementFingerprint,
                'snapshot_fingerprint' =>
                    $snapshot->snapshotFingerprint,
                'issued_at' => now(),
            ]);

            $lockedRequest->forceFill([
                'status' => InventoryNegativeRequestStatus::Approved,
                'approved_by_user_id' => $administrator->id,
                'approved_at' => now(),
            ])->save();

            return new InventoryNegativeOverrideIssuance(
                request: $lockedRequest->refresh()->load('lines'),
                override: $override,
                invalidated: false
            );
        }, 3);
    }

    public function reject(
        InventoryNegativeRequest|int $request,
        string $reason,
        User $administrator
    ): InventoryNegativeRequest {
        return $this->changePendingRequest(
            $request,
            $administrator,
            function (InventoryNegativeRequest $locked) use ($reason, $administrator): void {
                $locked->forceFill([
                    'status' => InventoryNegativeRequestStatus::Rejected,
                    'rejected_by_user_id' => $administrator->id,
                    'rejected_at' => now(),
                    'rejection_reason' => $this->reason($reason),
                ])->save();
            }
        );
    }

    public function revoke(
        InventoryNegativeOverride|int $override,
        string $reason,
        User $administrator
    ): InventoryNegativeOverride {
        $overrideId = $override instanceof InventoryNegativeOverride
            ? (int) $override->getKey()
            : $override;

        return DB::transaction(function () use (
            $overrideId,
            $reason,
            $administrator
        ): InventoryNegativeOverride {
            $organizationId = (int) $administrator
                ->current_organization_id;
            $this->lockActiveOrganization($organizationId);
            $this->guardAdministrator($organizationId, $administrator);

            $locked = InventoryNegativeOverride::query()
                ->whereKey($overrideId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'El Override no existe en la organización activa.'
                );
            }

            if (
                $locked->status
                    === InventoryNegativeOverrideStatus::Revoked
            ) {
                return $locked;
            }

            if (
                $locked->status
                    !== InventoryNegativeOverrideStatus::Active
            ) {
                throw new DomainException(
                    'Solo un Override activo puede revocarse.'
                );
            }

            $locked->forceFill([
                'status' => InventoryNegativeOverrideStatus::Revoked,
                'revoked_by_user_id' => $administrator->id,
                'revoked_at' => now(),
                'revocation_reason' => $this->reason($reason),
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    private function changePendingRequest(
        InventoryNegativeRequest|int $request,
        User $administrator,
        callable $change
    ): InventoryNegativeRequest {
        $requestId = $request instanceof InventoryNegativeRequest
            ? (int) $request->getKey()
            : $request;

        return DB::transaction(function () use (
            $requestId,
            $administrator,
            $change
        ): InventoryNegativeRequest {
            $organizationId = (int) $administrator
                ->current_organization_id;
            $this->lockActiveOrganization($organizationId);
            $this->guardAdministrator($organizationId, $administrator);

            $locked = InventoryNegativeRequest::query()
                ->whereKey($requestId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La solicitud no existe en la organización activa.'
                );
            }

            if ($locked->status !== InventoryNegativeRequestStatus::Pending) {
                throw new DomainException(
                    'Solo una solicitud pendiente puede rechazarse.'
                );
            }

            $change($locked);

            return $locked->refresh()->load('lines');
        }, 3);
    }

    private function invalidate(
        InventoryNegativeRequest $request,
        string $reason
    ): InventoryNegativeOverrideIssuance {
        $request->forceFill([
            'status' => InventoryNegativeRequestStatus::Invalidated,
            'invalidated_at' => now(),
            'invalidation_reason' => $reason,
        ])->save();

        return new InventoryNegativeOverrideIssuance(
            request: $request->refresh()->load('lines'),
            override: null,
            invalidated: true
        );
    }

    private function guardAdministrator(
        int $organizationId,
        User $administrator
    ): void {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $administrator->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $membership
            || ! $membership->role->canOverrideInventoryNegative()
        ) {
            throw new DomainException(
                'Solo un Administrador activo puede emitir el Override.'
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
            throw new DomainException('La organización no está activa.');
        }
    }

    private function reason(string $reason): string
    {
        $reason = Str::of($reason)->squish()->toString();

        if ($reason === '' || Str::length($reason) > 2000) {
            throw new DomainException('El motivo administrativo es inválido.');
        }

        return $reason;
    }
}
