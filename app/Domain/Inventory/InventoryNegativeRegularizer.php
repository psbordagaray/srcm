<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryNegativeIncidentStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryNegativeIncident;
use App\Models\InventoryNegativeIncidentLine;
use App\Models\InventoryNegativeRegularization;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class InventoryNegativeRegularizer
{
    /**
     * @param iterable<InventoryMovement> $movements
     */
    public function apply(iterable $movements, User $actor): void
    {
        $movements = collect($movements)->values();

        if ($movements->isEmpty()) {
            return;
        }

        $organizationIds = $movements
            ->pluck('organization_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        if ($organizationIds->count() !== 1) {
            throw new DomainException(
                'La regularización sólo admite movimientos de una organización.'
            );
        }

        $organizationId = (int) $organizationIds->first();

        if (
            (int) $actor->current_organization_id !== $organizationId
        ) {
            throw new DomainException(
                'El actor no pertenece a la organización regularizada.'
            );
        }

        $effects = $this->effectsByPosition($movements);

        foreach ($effects as $effect) {
            if (! InventoryQuantity::isPositive($effect['delta'])) {
                continue;
            }

            $balance = DB::table('inventory_balances')
                ->where([
                    'organization_id' => $organizationId,
                    'catalog_product_id' =>
                        $effect['catalog_product_id'],
                    'inventory_location_id' =>
                        $effect['inventory_location_id'],
                    'condition' => $effect['condition'],
                ])
                ->lockForUpdate()
                ->first(['quantity']);

            if (! $balance) {
                throw new DomainException(
                    'La posición proyectada no existe para regularizar.'
                );
            }

            $previousQuantity = InventoryQuantity::subtract(
                $balance->quantity,
                $effect['delta']
            );
            $available = InventoryQuantity::minimum(
                $effect['delta'],
                InventoryQuantity::deficit($previousQuantity)
            );

            if (! InventoryQuantity::isPositive($available)) {
                continue;
            }

            $this->allocatePosition(
                $organizationId,
                $effect,
                $available,
                $actor
            );
        }
    }

    /**
     * @param array{
     *     catalog_product_id: int,
     *     inventory_location_id: int,
     *     condition: string,
     *     delta: string,
     *     contributors: list<array{movement_id: int, remaining: string}>
     * } $effect
     */
    private function allocatePosition(
        int $organizationId,
        array $effect,
        string $available,
        User $actor
    ): void {
        $lineIds = DB::table('inventory_negative_incident_lines as line')
            ->join(
                'inventory_negative_incidents as incident',
                'incident.id',
                '=',
                'line.inventory_negative_incident_id'
            )
            ->where('line.organization_id', $organizationId)
            ->where('line.catalog_product_id', $effect['catalog_product_id'])
            ->where(
                'line.inventory_location_id',
                $effect['inventory_location_id']
            )
            ->where('line.condition', $effect['condition'])
            ->where('line.pending_deficit', '>', 0)
            ->whereIn('incident.status', [
                InventoryNegativeIncidentStatus::Open->value,
                InventoryNegativeIncidentStatus::UnderReview->value,
            ])
            ->orderBy('incident.opened_at')
            ->orderBy('incident.id')
            ->orderBy('line.sequence')
            ->lockForUpdate()
            ->pluck('line.id');

        $remaining = $available;
        $contributors = $effect['contributors'];

        foreach ($lineIds as $lineId) {
            if (! InventoryQuantity::isPositive($remaining)) {
                break;
            }

            $line = InventoryNegativeIncidentLine::query()
                ->whereKey($lineId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->firstOrFail();
            $lineAllocation = InventoryQuantity::minimum(
                $remaining,
                $line->pending_deficit
            );
            $unattributed = $lineAllocation;
            $appliedAt = now();

            foreach ($contributors as $index => $contributor) {
                if (
                    ! InventoryQuantity::isPositive($unattributed)
                    || ! InventoryQuantity::isPositive(
                        $contributor['remaining']
                    )
                ) {
                    continue;
                }

                $quantity = InventoryQuantity::minimum(
                    $unattributed,
                    $contributor['remaining']
                );

                InventoryNegativeRegularization::query()->create([
                    'organization_id' => $organizationId,
                    'inventory_negative_incident_id' =>
                        $line->inventory_negative_incident_id,
                    'inventory_negative_incident_line_id' => $line->id,
                    'regularizing_movement_id' =>
                        $contributor['movement_id'],
                    'applied_by_user_id' => $actor->id,
                    'quantity' => $quantity,
                    'applied_at' => $appliedAt,
                ]);

                $contributors[$index]['remaining'] =
                    InventoryQuantity::subtract(
                        $contributor['remaining'],
                        $quantity
                    );
                $unattributed = InventoryQuantity::subtract(
                    $unattributed,
                    $quantity
                );
            }

            if (InventoryQuantity::isPositive($unattributed)) {
                throw new DomainException(
                    'No pudo atribuirse completamente la regularización.'
                );
            }

            $pending = InventoryQuantity::subtract(
                $line->pending_deficit,
                $lineAllocation
            );
            $line->forceFill([
                'pending_deficit' => $pending,
                'regularized_at' => InventoryQuantity::equal($pending, '0')
                    ? $appliedAt
                    : null,
            ])->save();

            $this->completeIncidentIfRegularized(
                $line->inventory_negative_incident_id,
                $organizationId,
                $appliedAt
            );
            $remaining = InventoryQuantity::subtract(
                $remaining,
                $lineAllocation
            );
        }
    }

    private function completeIncidentIfRegularized(
        int $incidentId,
        int $organizationId,
        mixed $regularizedAt
    ): void {
        $pending = InventoryNegativeIncidentLine::query()
            ->where('organization_id', $organizationId)
            ->where('inventory_negative_incident_id', $incidentId)
            ->where('pending_deficit', '>', 0)
            ->exists();

        if ($pending) {
            return;
        }

        $incident = InventoryNegativeIncident::query()
            ->whereKey($incidentId)
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($incident->regularized_at === null) {
            $incident->forceFill([
                'regularized_at' => $regularizedAt,
            ])->save();
        }
    }

    /**
     * @return array<string, array{
     *     catalog_product_id: int,
     *     inventory_location_id: int,
     *     condition: string,
     *     delta: string,
     *     contributors: list<array{movement_id: int, remaining: string}>
     * }>
     */
    private function effectsByPosition(iterable $movements): array
    {
        $byMovement = [];
        $combined = [];

        foreach ($movements as $movement) {
            foreach ($movement->lines as $line) {
                $condition = $line->condition->value;
                $common = [
                    'catalog_product_id' => (int) $line->catalog_product_id,
                    'condition' => $condition,
                ];

                if ($line->source_location_id !== null) {
                    $this->addEffect(
                        $byMovement,
                        (int) $movement->id,
                        $common,
                        (int) $line->source_location_id,
                        InventoryQuantity::negate($line->base_quantity)
                    );
                }

                if ($line->destination_location_id !== null) {
                    $this->addEffect(
                        $byMovement,
                        (int) $movement->id,
                        $common,
                        (int) $line->destination_location_id,
                        InventoryQuantity::positive($line->base_quantity)
                    );
                }
            }
        }

        ksort($byMovement, SORT_NUMERIC);

        foreach ($byMovement as $movementId => $effects) {
            ksort($effects, SORT_STRING);

            foreach ($effects as $key => $effect) {
                if (! isset($combined[$key])) {
                    $combined[$key] = [
                        ...$effect,
                        'delta' => '0.000000',
                        'contributors' => [],
                    ];
                }

                $combined[$key]['delta'] = InventoryQuantity::add(
                    $combined[$key]['delta'],
                    $effect['delta']
                );

                if (InventoryQuantity::isPositive($effect['delta'])) {
                    $combined[$key]['contributors'][] = [
                        'movement_id' => (int) $movementId,
                        'remaining' => $effect['delta'],
                    ];
                }
            }
        }

        ksort($combined, SORT_STRING);

        return $combined;
    }

    /**
     * @param array<int, array<string, array<string, mixed>>> $effects
     * @param array{catalog_product_id: int, condition: string} $common
     */
    private function addEffect(
        array &$effects,
        int $movementId,
        array $common,
        int $locationId,
        string $delta
    ): void {
        $key = implode(':', [
            $common['catalog_product_id'],
            $locationId,
            $common['condition'],
        ]);

        if (! isset($effects[$movementId][$key])) {
            $effects[$movementId][$key] = [
                ...$common,
                'inventory_location_id' => $locationId,
                'delta' => '0.000000',
            ];
        }

        $effects[$movementId][$key]['delta'] = InventoryQuantity::add(
            $effects[$movementId][$key]['delta'],
            $delta
        );
    }
}
