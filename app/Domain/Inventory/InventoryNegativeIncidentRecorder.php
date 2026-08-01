<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryNegativeIncidentStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryNegativeIncident;
use App\Models\InventoryNegativeIncidentLine;
use App\Models\InventoryNegativeIncidentStatusHistory;
use App\Models\InventoryNegativeOverride;
use App\Models\InventoryNegativeRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class InventoryNegativeIncidentRecorder
{
    public function record(
        InventoryMovement $movement,
        InventoryNegativeRequest $request,
        InventoryNegativeOverride $override,
        InventoryNegativeAuthorizationSnapshot $snapshot,
        User $actor
    ): InventoryNegativeIncident {
        $existing = InventoryNegativeIncident::query()
            ->where('organization_id', $movement->organization_id)
            ->where('inventory_movement_id', $movement->id)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if (
                (int) $existing->inventory_negative_request_id
                    !== (int) $request->id
                || (int) $existing->inventory_negative_override_id
                    !== (int) $override->id
            ) {
                throw new DomainException(
                    'El movimiento ya posee otra incidencia negativa.'
                );
            }

            $existing->load(['lines', 'statusHistory']);

            if (
                $existing->lines->isEmpty()
                || $existing->statusHistory->isEmpty()
            ) {
                throw new DomainException(
                    'La incidencia existente está incompleta.'
                );
            }

            return $existing;
        }

        $positions = array_values(array_filter(
            $snapshot->positions,
            fn (InventoryNegativePositionSnapshot $position): bool =>
                $position->createsNegative
        ));

        if ($positions === []) {
            throw new DomainException(
                'No existe déficit incremental para registrar.'
            );
        }

        foreach ($positions as $position) {
            $balance = DB::table('inventory_balances')
                ->where('organization_id', $movement->organization_id)
                ->where(
                    'catalog_product_id',
                    $position->catalogProductId
                )
                ->where(
                    'inventory_location_id',
                    $position->inventoryLocationId
                )
                ->where('condition', $position->condition->value)
                ->lockForUpdate()
                ->first();

            if (
                ! $balance
                || ! InventoryQuantity::equal(
                    $balance->quantity,
                    $position->projectedQuantity
                )
            ) {
                throw new DomainException(
                    'El saldo proyectado no coincide con la incidencia.'
                );
            }
        }

        $openedAt = now();
        $incident = InventoryNegativeIncident::query()->create([
            'organization_id' => $movement->organization_id,
            'inventory_movement_id' => $movement->id,
            'inventory_negative_request_id' => $request->id,
            'inventory_negative_override_id' => $override->id,
            'requested_by_user_id' => $request->requested_by_user_id,
            'granted_by_user_id' => $override->granted_by_user_id,
            'status' => InventoryNegativeIncidentStatus::Open,
            'reason' => $request->reason,
            'opened_at' => $openedAt,
        ]);

        foreach ($positions as $index => $position) {
            InventoryNegativeIncidentLine::query()->create([
                'organization_id' => $movement->organization_id,
                'inventory_negative_incident_id' => $incident->id,
                'sequence' => $index + 1,
                'catalog_product_id' => $position->catalogProductId,
                'inventory_location_id' =>
                    $position->inventoryLocationId,
                'condition' => $position->condition,
                'previous_quantity' => $position->currentQuantity,
                'outgoing_quantity' => $position->requestedQuantity,
                'incoming_quantity' => $position->incomingQuantity,
                'net_quantity' => InventoryQuantity::subtract(
                    $position->incomingQuantity,
                    $position->requestedQuantity
                ),
                'resulting_quantity' => $position->projectedQuantity,
                'previous_deficit' => $position->currentDeficit,
                'resulting_deficit' => $position->projectedDeficit,
                'incremental_deficit' =>
                    $position->incrementalDeficit,
                'pending_deficit' => $position->incrementalDeficit,
                'base_unit_code' => $position->baseUnitCode,
            ]);
        }

        InventoryNegativeIncidentStatusHistory::query()->create([
            'organization_id' => $movement->organization_id,
            'inventory_negative_incident_id' => $incident->id,
            'from_status' => null,
            'to_status' => InventoryNegativeIncidentStatus::Open,
            'changed_by_user_id' => $actor->id,
            'reason' => 'Incidencia creada por confirmación excepcional.',
            'changed_at' => $openedAt,
        ]);

        return $incident->load(['lines', 'statusHistory']);
    }
}
