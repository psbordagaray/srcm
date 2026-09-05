<?php

namespace App\Domain\Inventory;

use App\Enums\FractionalContainerConsumptionPolicy;
use App\Enums\FractionalContainerState;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Models\CatalogProduct;
use App\Models\FractionalContainer;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FractionalContainerConsumptionManager
{
    public function __construct(
        private readonly InventoryMovementConfirmer $confirmer
    ) {
    }

    public function confirm(
        InventoryMovement|int $movement,
        User $actor,
        FractionalContainerConsumptionPolicy $policy =
            FractionalContainerConsumptionPolicy::ExhaustOpenContainer
    ): InventoryMovement {
        $movementId = $movement instanceof InventoryMovement
            ? (int) $movement->getKey()
            : $movement;

        return DB::transaction(function () use (
            $movementId,
            $actor,
            $policy
        ): InventoryMovement {
            $organizationId = InventoryMovement::query()
                ->whereKey($movementId)
                ->value('organization_id');

            if ($organizationId === null) {
                throw new DomainException(
                    'El movimiento de consumo no existe.'
                );
            }

            $this->lockActiveOrganization((int) $organizationId);

            $lockedMovement = InventoryMovement::query()
                ->whereKey($movementId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $lockedMovement) {
                throw new DomainException(
                    'No pudo bloquearse el movimiento de consumo.'
                );
            }

            $this->guardActor($lockedMovement, $actor);

            if ($lockedMovement->type !== InventoryMovementType::Issue) {
                throw new DomainException(
                    'Fractional Container Consumption Policy V1 '
                    .'sólo confirma movimientos de salida.'
                );
            }

            $lines = InventoryMovementLine::query()
                ->where('organization_id', $organizationId)
                ->where(
                    'inventory_movement_id',
                    $lockedMovement->id
                )
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw new DomainException(
                    'El movimiento de consumo no posee líneas.'
                );
            }

            if (
                $lockedMovement->status
                    === InventoryMovementStatus::Confirmed
            ) {
                $confirmed = $this->confirmer->confirm(
                    $lockedMovement,
                    $actor
                );

                $this->assertCompletedTraceability(
                    $lockedMovement,
                    $lines,
                    $policy
                );

                return $confirmed;
            }

            if (
                $lockedMovement->status
                    !== InventoryMovementStatus::Draft
            ) {
                throw new DomainException(
                    'Sólo un movimiento borrador puede consumir '
                    .'contenedores fraccionables.'
                );
            }

            $lineIds = $lines->pluck('id')->all();

            if (
                DB::table('fractional_container_consumptions')
                    ->where('organization_id', $organizationId)
                    ->whereIn('inventory_movement_line_id', $lineIds)
                    ->exists()
            ) {
                throw new DomainException(
                    'Un movimiento borrador no puede conservar '
                    .'historial parcial de consumo.'
                );
            }

            foreach ($lines as $line) {
                $this->allocateLine(
                    $lockedMovement,
                    $line,
                    $policy
                );
            }

            return $this->confirmer->confirm(
                $lockedMovement,
                $actor
            );
        }, 3);
    }

    private function lockActiveOrganization(
        int $organizationId
    ): void {
        $organization = DB::table('organizations')
            ->where('id', $organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->first(['id']);

        if (! $organization) {
            throw new DomainException(
                'La organización del consumo no está activa.'
            );
        }
    }

    private function guardActor(
        InventoryMovement $movement,
        User $actor
    ): void {
        if (
            (int) $actor->current_organization_id
                !== (int) $movement->organization_id
        ) {
            throw new DomainException(
                'El movimiento no pertenece a la organización '
                .'activa del usuario.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where(
                'organization_id',
                $movement->organization_id
            )
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $membership
            || ! $membership->role->canConfirmInventoryMovement(
                $movement->type
            )
        ) {
            throw new DomainException(
                'El rol del usuario no puede confirmar '
                .'este consumo de inventario.'
            );
        }
    }

    private function allocateLine(
        InventoryMovement $movement,
        InventoryMovementLine $line,
        FractionalContainerConsumptionPolicy $policy
    ): void {
        if (
            $policy
                !== FractionalContainerConsumptionPolicy::ExhaustOpenContainer
        ) {
            throw new DomainException(
                'La política solicitada no está implementada en V1.'
            );
        }

        $product = CatalogProduct::query()
            ->whereKey($line->catalog_product_id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $product || ! $product->allowsFractionalQuantity()) {
            throw new DomainException(
                'La línea requiere un producto activo fraccionable.'
            );
        }

        if (
            $line->source_location_id === null
            || $line->destination_location_id !== null
        ) {
            throw new DomainException(
                'La política fraccionada V1 requiere una salida '
                .'desde una única ubicación de origen.'
            );
        }

        if (
            (string) $line->entered_unit_code
                !== (string) $line->base_unit_code
            || ! InventoryQuantity::equal(
                $line->conversion_factor,
                '1'
            )
        ) {
            throw new DomainException(
                'La política fraccionada V1 sólo consume cantidades '
                .'libres expresadas en la unidad base. '
                .'Una presentación cerrada no implica apertura.'
            );
        }

        InventoryQuantity::assertFitsScale(
            $line->base_quantity,
            (int) $product->quantity_scale,
            'La cantidad fraccionada'
        );

        $containers = FractionalContainer::query()
            ->forOrganization((int) $movement->organization_id)
            ->where(
                'catalog_product_id',
                $line->catalog_product_id
            )
            ->where(
                'inventory_location_id',
                $line->source_location_id
            )
            ->where(
                'condition',
                $line->condition->value
            )
            ->where(
                'base_unit_code',
                $line->base_unit_code
            )
            ->whereIn(
                'state',
                [
                    FractionalContainerState::Open->value,
                    FractionalContainerState::Sealed->value,
                ]
            )
            ->where('remaining_base_quantity', '>', 0)
            ->orderByRaw(
                'CASE WHEN state = ? THEN 0 ELSE 1 END',
                [FractionalContainerState::Open->value]
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($containers->isEmpty()) {
            throw new DomainException(
                'No existen contenedores fraccionables elegibles '
                .'para la línea.'
            );
        }

        $available = InventoryQuantity::signed('0');

        foreach ($containers as $container) {
            $available = InventoryQuantity::add(
                $available,
                $container->remaining_base_quantity
            );
        }

        if (
            ! InventoryQuantity::lessThanOrEqual(
                $line->base_quantity,
                $available
            )
        ) {
            throw new DomainException(
                'Los contenedores trazables no alcanzan para '
                .'la cantidad solicitada.'
            );
        }

        $pending = InventoryQuantity::positive(
            $line->base_quantity
        );
        $sequence = 1;

        foreach ($containers as $container) {
            if (InventoryQuantity::equal($pending, '0')) {
                break;
            }

            $remainingBefore = InventoryQuantity::positive(
                $container->remaining_base_quantity
            );
            $consumed = InventoryQuantity::minimum(
                $pending,
                $remainingBefore
            );
            $remainingAfter = InventoryQuantity::subtract(
                $remainingBefore,
                $consumed
            );
            $stateBefore = $container->state;
            $stateAfter = InventoryQuantity::equal(
                $remainingAfter,
                '0'
            )
                ? FractionalContainerState::Exhausted
                : FractionalContainerState::Open;

            $updated = DB::table('fractional_containers')
                ->where('id', $container->id)
                ->where(
                    'organization_id',
                    $movement->organization_id
                )
                ->update([
                    'state' => $stateAfter->value,
                    'remaining_base_quantity' => $remainingAfter,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new DomainException(
                    'No pudo actualizarse de forma exacta '
                    .'el contenedor bloqueado.'
                );
            }

            DB::table(
                'fractional_container_consumptions'
            )->insert([
                'organization_id' => $movement->organization_id,
                'inventory_movement_line_id' => $line->id,
                'fractional_container_id' => $container->id,
                'sequence' => $sequence,
                'policy' => $policy->value,
                'consumed_base_quantity' => $consumed,
                'base_unit_code' => $line->base_unit_code,
                'state_before' => $stateBefore->value,
                'state_after' => $stateAfter->value,
                'remaining_before' => $remainingBefore,
                'remaining_after' => $remainingAfter,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $pending = InventoryQuantity::subtract(
                $pending,
                $consumed
            );
            $sequence++;
        }

        if (! InventoryQuantity::equal($pending, '0')) {
            throw new DomainException(
                'La asignación fraccionada no cerró exactamente '
                .'la cantidad solicitada.'
            );
        }
    }

    /**
     * @param Collection<int, InventoryMovementLine> $lines
     */
    private function assertCompletedTraceability(
        InventoryMovement $movement,
        Collection $lines,
        FractionalContainerConsumptionPolicy $policy
    ): void {
        foreach ($lines as $line) {
            $history = DB::table(
                'fractional_container_consumptions'
            )
                ->where(
                    'organization_id',
                    $movement->organization_id
                )
                ->where(
                    'inventory_movement_line_id',
                    $line->id
                )
                ->orderBy('sequence')
                ->get();

            if ($history->isEmpty()) {
                throw new DomainException(
                    'La confirmación existente no conserva '
                    .'trazabilidad fraccionada completa.'
                );
            }

            $total = InventoryQuantity::signed('0');
            $expectedSequence = 1;

            foreach ($history as $record) {
                if (
                    (int) $record->sequence !== $expectedSequence
                    || (string) $record->policy !== $policy->value
                    || (string) $record->base_unit_code
                        !== (string) $line->base_unit_code
                ) {
                    throw new DomainException(
                        'El historial fraccionado existente '
                        .'no reproduce el contrato confirmado.'
                    );
                }

                $consumed = InventoryQuantity::positive(
                    $record->consumed_base_quantity
                );
                $expectedAfter = InventoryQuantity::subtract(
                    $record->remaining_before,
                    $consumed
                );

                if (
                    ! InventoryQuantity::equal(
                        $expectedAfter,
                        $record->remaining_after
                    )
                ) {
                    throw new DomainException(
                        'El historial fraccionado existente '
                        .'no conserva aritmética exacta.'
                    );
                }

                $total = InventoryQuantity::add(
                    $total,
                    $consumed
                );
                $expectedSequence++;
            }

            if (
                ! InventoryQuantity::equal(
                    $total,
                    $line->base_quantity
                )
            ) {
                throw new DomainException(
                    'El historial fraccionado existente '
                    .'no cubre exactamente la línea confirmada.'
                );
            }
        }
    }
}
