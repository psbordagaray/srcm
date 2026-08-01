<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryNegativeIncidentStatus;
use App\Models\InventoryNegativeIncident;
use App\Models\InventoryNegativeIncidentLine;
use App\Models\InventoryNegativeIncidentStatusHistory;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InventoryNegativeIncidentLifecycle
{
    public function markUnderReview(
        InventoryNegativeIncident|int $incident,
        string $reason,
        User $actor
    ): InventoryNegativeIncident {
        return $this->transition(
            $incident,
            InventoryNegativeIncidentStatus::UnderReview,
            $reason,
            $actor
        );
    }

    public function resolve(
        InventoryNegativeIncident|int $incident,
        string $reason,
        User $actor
    ): InventoryNegativeIncident {
        return $this->transition(
            $incident,
            InventoryNegativeIncidentStatus::Resolved,
            $reason,
            $actor
        );
    }

    private function transition(
        InventoryNegativeIncident|int $incident,
        InventoryNegativeIncidentStatus $target,
        string $reason,
        User $actor
    ): InventoryNegativeIncident {
        $incidentId = $incident instanceof InventoryNegativeIncident
            ? (int) $incident->id
            : $incident;
        $reason = Str::of($reason)->squish()->toString();

        if ($reason === '') {
            throw new DomainException(
                'La transición administrativa requiere un motivo.'
            );
        }

        return DB::transaction(function () use (
            $incidentId,
            $target,
            $reason,
            $actor
        ): InventoryNegativeIncident {
            $organizationId = (int) $actor->current_organization_id;

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
            $membership = OrganizationMembership::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $actor->id)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (
                ! $organization
                || ! $membership
                || ! $membership->role
                    ->canReviewInventoryNegativeIncidents()
            ) {
                throw new DomainException(
                    'Sólo un Admin activo puede gestionar incidencias negativas.'
                );
            }

            $locked = InventoryNegativeIncident::query()
                ->whereKey($incidentId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La incidencia no existe en la organización activa.'
                );
            }

            if ($locked->status === $target) {
                return $locked->load(['lines', 'statusHistory']);
            }

            $from = $locked->status;

            if (
                $target === InventoryNegativeIncidentStatus::UnderReview
                && $from !== InventoryNegativeIncidentStatus::Open
            ) {
                throw new DomainException(
                    'Sólo una incidencia abierta puede pasar a revisión.'
                );
            }

            if ($target === InventoryNegativeIncidentStatus::Resolved) {
                if (
                    ! in_array($from, [
                        InventoryNegativeIncidentStatus::Open,
                        InventoryNegativeIncidentStatus::UnderReview,
                    ], true)
                ) {
                    throw new DomainException(
                        'La incidencia no admite resolución.'
                    );
                }

                $pending = InventoryNegativeIncidentLine::query()
                    ->where('organization_id', $organizationId)
                    ->where(
                        'inventory_negative_incident_id',
                        $locked->id
                    )
                    ->lockForUpdate()
                    ->get()
                    ->contains(
                        fn (InventoryNegativeIncidentLine $line): bool =>
                            InventoryQuantity::isPositive(
                                $line->pending_deficit
                            )
                    );

                if ($pending || $locked->regularized_at === null) {
                    throw new DomainException(
                        'La incidencia debe estar físicamente regularizada antes de resolverse.'
                    );
                }
            }

            $changedAt = now();
            $attributes = ['status' => $target];

            if ($target === InventoryNegativeIncidentStatus::UnderReview) {
                $attributes += [
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => $changedAt,
                    'review_reason' => $reason,
                ];
            }

            if ($target === InventoryNegativeIncidentStatus::Resolved) {
                $attributes += [
                    'resolved_by_user_id' => $actor->id,
                    'resolved_at' => $changedAt,
                    'resolution_reason' => $reason,
                ];
            }

            $locked->forceFill($attributes)->save();

            InventoryNegativeIncidentStatusHistory::query()->create([
                'organization_id' => $organizationId,
                'inventory_negative_incident_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $target,
                'changed_by_user_id' => $actor->id,
                'reason' => $reason,
                'changed_at' => $changedAt,
            ]);

            return $locked->refresh()->load(['lines', 'statusHistory']);
        }, 3);
    }
}
