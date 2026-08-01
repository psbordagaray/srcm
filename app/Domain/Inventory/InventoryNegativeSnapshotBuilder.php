<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryMovementStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class InventoryNegativeSnapshotBuilder
{
    public function build(
        InventoryMovement $movement
    ): InventoryNegativeAuthorizationSnapshot {
        if ($movement->status !== InventoryMovementStatus::Draft) {
            throw new DomainException(
                'Solo un movimiento borrador puede solicitar una excepción.'
            );
        }

        $lines = InventoryMovementLine::query()
            ->where('organization_id', $movement->organization_id)
            ->where('inventory_movement_id', $movement->id)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();

        if ($lines->isEmpty()) {
            throw new DomainException(
                'El movimiento no contiene líneas para evaluar.'
            );
        }

        $movementFingerprint = $this->hash([
            'movement' => $this->movementData($movement),
            'lines' => $lines
                ->map(fn (InventoryMovementLine $line): array =>
                    $this->lineData($line))
                ->all(),
        ]);

        $effects = [];

        foreach ($lines as $line) {
            if ($line->source_location_id === null) {
                continue;
            }

            $key = implode(':', [
                $line->catalog_product_id,
                $line->source_location_id,
                $line->condition->value,
            ]);

            if (! isset($effects[$key])) {
                $effects[$key] = [
                    'catalog_product_id' =>
                        (int) $line->catalog_product_id,
                    'inventory_location_id' =>
                        (int) $line->source_location_id,
                    'condition' => $line->condition,
                    'requested_quantity' => '0.000000',
                    'base_unit_code' => $line->base_unit_code,
                ];
            }

            if (
                $effects[$key]['base_unit_code']
                    !== $line->base_unit_code
            ) {
                throw new DomainException(
                    'Una posición contiene unidades base incompatibles.'
                );
            }

            $effects[$key]['requested_quantity'] =
                InventoryQuantity::add(
                    $effects[$key]['requested_quantity'],
                    $line->base_quantity
                );
        }

        if ($effects === []) {
            throw new DomainException(
                'El movimiento no produce ninguna salida de inventario.'
            );
        }

        ksort($effects, SORT_STRING);
        $positions = [];

        foreach ($effects as $effect) {
            $balance = DB::table('inventory_balances')
                ->where('organization_id', $movement->organization_id)
                ->where(
                    'catalog_product_id',
                    $effect['catalog_product_id']
                )
                ->where(
                    'inventory_location_id',
                    $effect['inventory_location_id']
                )
                ->where('condition', $effect['condition']->value)
                ->lockForUpdate()
                ->first();

            $current = InventoryQuantity::signed(
                $balance?->quantity ?? '0'
            );
            $requested = InventoryQuantity::positive(
                $effect['requested_quantity']
            );
            $projected = InventoryQuantity::subtract(
                $current,
                $requested
            );
            $currentDeficit = InventoryQuantity::deficit($current);
            $projectedDeficit = InventoryQuantity::deficit($projected);

            if (
                $balance
                && $balance->base_unit_code
                    !== $effect['base_unit_code']
            ) {
                throw new DomainException(
                    'La unidad base del saldo no coincide con el movimiento.'
                );
            }

            $positions[] = new InventoryNegativePositionSnapshot(
                catalogProductId: $effect['catalog_product_id'],
                inventoryLocationId: $effect['inventory_location_id'],
                condition: $effect['condition'],
                currentQuantity: $current,
                requestedQuantity: $requested,
                projectedQuantity: $projected,
                currentDeficit: $currentDeficit,
                projectedDeficit: $projectedDeficit,
                incrementalDeficit: InventoryQuantity::subtract(
                    $projectedDeficit,
                    $currentDeficit
                ),
                baseUnitCode: $effect['base_unit_code'],
                balanceVersion: (int) ($balance?->version ?? 0),
                createsNegative: InventoryQuantity::isNegative($projected),
            );
        }

        return new InventoryNegativeAuthorizationSnapshot(
            movementFingerprint: $movementFingerprint,
            snapshotFingerprint: $this->hash([
                'movement_fingerprint' => $movementFingerprint,
                'positions' => array_map(
                    fn (InventoryNegativePositionSnapshot $position): array =>
                        $position->canonical(),
                    $positions
                ),
            ]),
            positions: $positions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function movementData(InventoryMovement $movement): array
    {
        return [
            'id' => (int) $movement->id,
            'organization_id' => (int) $movement->organization_id,
            'type' => $movement->type->value,
            'status' => $movement->status->value,
            'created_by_user_id' =>
                (int) $movement->created_by_user_id,
            'effective_at' => $movement->effective_at?->format(DATE_ATOM),
            'reason' => $movement->reason,
            'source_type' => $movement->source_type,
            'source_id' => $movement->source_id,
            'source_reference' => $movement->source_reference,
            'metadata' => $this->canonicalize(
                $movement->metadata ?? []
            ),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function lineData(InventoryMovementLine $line): array
    {
        return [
            'id' => (int) $line->id,
            'sequence' => (int) $line->sequence,
            'catalog_product_id' => (int) $line->catalog_product_id,
            'condition' => $line->condition->value,
            'source_location_id' => $line->source_location_id === null
                ? null
                : (int) $line->source_location_id,
            'destination_location_id' =>
                $line->destination_location_id === null
                    ? null
                    : (int) $line->destination_location_id,
            'entered_quantity' => $line->entered_quantity,
            'entered_unit_code' => $line->entered_unit_code,
            'conversion_factor' => $line->conversion_factor,
            'base_quantity' => $line->base_quantity,
            'base_unit_code' => $line->base_unit_code,
            'notes' => $line->notes,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function hash(array $value): string
    {
        try {
            return hash('sha256', json_encode(
                $value,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            ));
        } catch (JsonException $exception) {
            throw new DomainException(
                'No pudo construirse la huella de autorización.',
                previous: $exception
            );
        }
    }
}
