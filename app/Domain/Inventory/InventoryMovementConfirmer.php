<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class InventoryMovementConfirmer
{
    public function __construct(
        private readonly InventoryBalanceProjector $projector
    ) {
    }

    public function confirm(
        InventoryMovement|int $movement,
        User $actor
    ): InventoryMovement {
        $movementId = $movement instanceof InventoryMovement
            ? (int) $movement->getKey()
            : $movement;

        return DB::transaction(function () use (
            $movementId,
            $actor
        ): InventoryMovement {
            $lockedMovement = InventoryMovement::query()
                ->whereKey($movementId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardActor($lockedMovement, $actor);

            if (
                $lockedMovement->status
                    === InventoryMovementStatus::Confirmed
            ) {
                return $lockedMovement->load('lines');
            }

            if (
                $lockedMovement->status
                    !== InventoryMovementStatus::Draft
            ) {
                throw new DomainException(
                    'Solo un movimiento borrador puede confirmarse.'
                );
            }

            $lines = InventoryMovementLine::query()
                ->where(
                    'organization_id',
                    $lockedMovement->organization_id
                )
                ->where(
                    'inventory_movement_id',
                    $lockedMovement->id
                )
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw new DomainException(
                    'No puede confirmarse un movimiento sin líneas.'
                );
            }

            $this->validateLines($lockedMovement, $lines);

            $lockedMovement->setRelation('lines', $lines);

            $this->projector->apply($lockedMovement);

            $lockedMovement->forceFill([
                'status' => InventoryMovementStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $actor->id,
            ])->save();

            return $lockedMovement->refresh()->load('lines');
        }, 3);
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
                'El movimiento no pertenece a la organización activa del usuario.'
            );
        }

        $membershipExists = OrganizationMembership::query()
            ->where('organization_id', $movement->organization_id)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->exists();

        if (! $membershipExists) {
            throw new DomainException(
                'El usuario no posee una membresía activa en la organización.'
            );
        }
    }

    /**
     * @param Collection<int, InventoryMovementLine> $lines
     */
    private function validateLines(
        InventoryMovement $movement,
        Collection $lines
    ): void {
        $products = CatalogProduct::query()
            ->whereIn(
                'id',
                $lines->pluck('catalog_product_id')->unique()
            )
            ->get()
            ->keyBy('id');

        foreach ($lines as $line) {
            if (
                (int) $line->organization_id
                    !== (int) $movement->organization_id
            ) {
                throw new DomainException(
                    'La línea no pertenece a la organización del movimiento.'
                );
            }

            $product = $products->get($line->catalog_product_id);

            if (! $product) {
                throw new DomainException(
                    'Una línea referencia un producto inexistente.'
                );
            }

            if ($product->base_unit_code !== $line->base_unit_code) {
                throw new DomainException(
                    'La unidad base de la línea no coincide con la del producto.'
                );
            }

            InventoryQuantity::assertEquivalent(
                $line->entered_quantity,
                $line->conversion_factor,
                $line->base_quantity
            );
            InventoryQuantity::assertFitsScale(
                $line->base_quantity,
                (int) $product->quantity_scale
            );

            $this->validateDirection($movement, $line);
            $this->guardActiveLocation(
                $movement,
                $line->source_location_id
            );
            $this->guardActiveLocation(
                $movement,
                $line->destination_location_id
            );
        }

        if ($movement->type === InventoryMovementType::Reversal) {
            $this->validateReversal($movement, $lines);
        }
    }

    private function validateDirection(
        InventoryMovement $movement,
        InventoryMovementLine $line
    ): void {
        $type = $movement->type;

        if (
            $line->source_location_id === null
            && $line->destination_location_id === null
        ) {
            throw new DomainException(
                'Cada línea requiere una ubicación de origen o destino.'
            );
        }

        if (
            $line->source_location_id !== null
            && (int) $line->source_location_id
                === (int) $line->destination_location_id
        ) {
            throw new DomainException(
                'Una transferencia requiere ubicaciones diferentes.'
            );
        }

        if ($type->requiresSource() && $line->source_location_id === null) {
            throw new DomainException(
                'El tipo de movimiento requiere ubicación de origen.'
            );
        }

        if (
            $type->requiresDestination()
            && $line->destination_location_id === null
        ) {
            throw new DomainException(
                'El tipo de movimiento requiere ubicación de destino.'
            );
        }

        if (! $type->allowsSource() && $line->source_location_id !== null) {
            throw new DomainException(
                'El tipo de movimiento no admite ubicación de origen.'
            );
        }

        if (
            ! $type->allowsDestination()
            && $line->destination_location_id !== null
        ) {
            throw new DomainException(
                'El tipo de movimiento no admite ubicación de destino.'
            );
        }
    }

    private function guardActiveLocation(
        InventoryMovement $movement,
        mixed $locationId
    ): void {
        if ($locationId === null) {
            return;
        }

        $visited = [];
        $currentId = (int) $locationId;

        while ($currentId !== 0) {
            if (isset($visited[$currentId])) {
                throw new DomainException(
                    'La jerarquía de ubicaciones contiene un ciclo.'
                );
            }

            $visited[$currentId] = true;

            $location = InventoryLocation::query()
                ->whereKey($currentId)
                ->where('organization_id', $movement->organization_id)
                ->first();

            if (! $location || ! $location->active) {
                throw new DomainException(
                    'La ubicación y toda su jerarquía deben estar activas.'
                );
            }

            $currentId = (int) ($location->parent_id ?? 0);
        }
    }

    /**
     * @param Collection<int, InventoryMovementLine> $lines
     */
    private function validateReversal(
        InventoryMovement $movement,
        Collection $lines
    ): void {
        $original = InventoryMovement::query()
            ->whereKey($movement->reverses_movement_id)
            ->where('organization_id', $movement->organization_id)
            ->where(
                'status',
                InventoryMovementStatus::Confirmed->value
            )
            ->with(['lines' => fn ($query) => $query->orderBy('sequence')])
            ->first();

        if (! $original || $original->lines->count() !== $lines->count()) {
            throw new DomainException(
                'El reverso debe reflejar exactamente las líneas del movimiento original.'
            );
        }

        foreach ($original->lines->values() as $index => $originalLine) {
            $reversalLine = $lines->values()->get($index);

            foreach ([
                'sequence',
                'catalog_product_id',
                'condition',
                'entered_quantity',
                'entered_unit_code',
                'conversion_factor',
                'base_quantity',
                'base_unit_code',
            ] as $attribute) {
                if (
                    (string) $originalLine->getRawOriginal($attribute)
                        !== (string) $reversalLine->getRawOriginal($attribute)
                ) {
                    throw new DomainException(
                        'El reverso altera los datos de la línea original.'
                    );
                }
            }

            if (
                (int) $originalLine->source_location_id
                    !== (int) $reversalLine->destination_location_id
                || (int) $originalLine->destination_location_id
                    !== (int) $reversalLine->source_location_id
            ) {
                throw new DomainException(
                    'El reverso debe intercambiar origen y destino.'
                );
            }
        }
    }
}
