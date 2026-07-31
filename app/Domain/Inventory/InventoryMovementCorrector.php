<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InventoryMovementCorrector
{
    public function __construct(
        private readonly InventoryMovementConfirmer $confirmer
    ) {
    }

    public function correct(
        InventoryMovement|int $original,
        InventoryMovement|int $replacement,
        User $actor,
        string $reason,
        string $idempotencyKey
    ): InventoryMovementCorrection {
        $originalId = $original instanceof InventoryMovement
            ? (int) $original->getKey()
            : $original;
        $replacementId = $replacement instanceof InventoryMovement
            ? (int) $replacement->getKey()
            : $replacement;
        $reason = Str::of($reason)->squish()->toString();
        $idempotencyKey = Str::of($idempotencyKey)
            ->trim()
            ->toString();

        if ($reason === '') {
            throw new DomainException(
                'La corrección requiere un motivo obligatorio.'
            );
        }

        if ($idempotencyKey === '' || Str::length($idempotencyKey) > 60) {
            throw new DomainException(
                'La clave de idempotencia de la corrección es inválida.'
            );
        }

        if ($originalId === $replacementId) {
            throw new DomainException(
                'El reemplazo no puede ser el movimiento original.'
            );
        }

        return DB::transaction(function () use (
            $originalId,
            $replacementId,
            $actor,
            $reason,
            $idempotencyKey
        ): InventoryMovementCorrection {
            $organizationId = InventoryMovement::query()
                ->whereKey($originalId)
                ->value('organization_id');

            if ($organizationId === null) {
                throw new DomainException(
                    'El movimiento original no existe.'
                );
            }

            $organizationId = (int) $organizationId;
            $this->lockActiveOrganization($organizationId);
            $this->guardAdministrator($organizationId, $actor);

            $locked = InventoryMovement::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', [$originalId, $replacementId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedOriginal = $locked->get($originalId);
            $lockedReplacement = $locked->get($replacementId);

            if (! $lockedOriginal || ! $lockedReplacement) {
                throw new DomainException(
                    'El reemplazo debe pertenecer a la organización del original.'
                );
            }

            $existing = $this->existingCorrection(
                $lockedOriginal,
                $lockedReplacement,
                $idempotencyKey
            );

            if ($existing !== null) {
                return $existing;
            }

            $this->guardCorrectable(
                $lockedOriginal,
                $lockedReplacement
            );

            $reversal = $this->createReversal(
                $lockedOriginal,
                $actor,
                $reason,
                $idempotencyKey
            );

            $replacementMetadata = $lockedReplacement->metadata ?? [];
            $replacementMetadata['correction_key'] = $idempotencyKey;
            $replacementMetadata['replaces_public_id'] =
                $lockedOriginal->public_id;

            $lockedReplacement->forceFill([
                'reason' => $reason,
                'replaces_movement_id' => $lockedOriginal->id,
                'metadata' => $replacementMetadata,
            ])->save();

            [$confirmedReversal, $confirmedReplacement] =
                $this->confirmer->confirmCorrectionPair(
                    $reversal,
                    $lockedReplacement,
                    $actor
                );

            return new InventoryMovementCorrection(
                $lockedOriginal->refresh(),
                $confirmedReversal,
                $confirmedReplacement
            );
        }, 3);
    }

    private function lockActiveOrganization(int $organizationId): void
    {
        $organization = DB::table('organizations')
            ->where('id', $organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->first(['id']);

        if (! $organization) {
            throw new DomainException(
                'La organización del movimiento no está activa.'
            );
        }
    }

    private function guardAdministrator(
        int $organizationId,
        User $actor
    ): void {
        if (
            (int) $actor->current_organization_id
                !== $organizationId
        ) {
            throw new DomainException(
                'Solo puede corregirse dentro de la organización activa.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $membership
            || ! $membership->role->canCorrectInventory()
        ) {
            throw new DomainException(
                'Solo un administrador activo puede corregir movimientos.'
            );
        }
    }

    private function existingCorrection(
        InventoryMovement $original,
        InventoryMovement $replacement,
        string $idempotencyKey
    ): ?InventoryMovementCorrection {
        $reversal = InventoryMovement::query()
            ->where('organization_id', $original->organization_id)
            ->where('reverses_movement_id', $original->id)
            ->lockForUpdate()
            ->first();
        $existingReplacement = InventoryMovement::query()
            ->where('organization_id', $original->organization_id)
            ->where('replaces_movement_id', $original->id)
            ->lockForUpdate()
            ->first();

        if (! $reversal && ! $existingReplacement) {
            return null;
        }

        if (
            ! $reversal
            || ! $existingReplacement
            || (int) $existingReplacement->id !== (int) $replacement->id
            || $reversal->status !== InventoryMovementStatus::Confirmed
            || $existingReplacement->status
                !== InventoryMovementStatus::Confirmed
            || ($reversal->metadata['correction_key'] ?? null)
                !== $idempotencyKey
            || ($existingReplacement->metadata['correction_key'] ?? null)
                !== $idempotencyKey
        ) {
            throw new DomainException(
                'El movimiento ya posee otra corrección o una corrección incompleta.'
            );
        }

        return new InventoryMovementCorrection(
            $original,
            $reversal->load('lines'),
            $existingReplacement->load('lines')
        );
    }

    private function guardCorrectable(
        InventoryMovement $original,
        InventoryMovement $replacement
    ): void {
        if ($original->status !== InventoryMovementStatus::Confirmed) {
            throw new DomainException(
                'Solo puede corregirse un movimiento confirmado.'
            );
        }

        if ($original->type === InventoryMovementType::Reversal) {
            throw new DomainException(
                'Un reverso no se corrige directamente.'
            );
        }

        if ($replacement->status !== InventoryMovementStatus::Draft) {
            throw new DomainException(
                'El reemplazo debe encontrarse en borrador.'
            );
        }

        if (
            $replacement->type === InventoryMovementType::Reversal
            || $replacement->reverses_movement_id !== null
            || $replacement->replaces_movement_id !== null
        ) {
            throw new DomainException(
                'El reemplazo ya está vinculado o utiliza un tipo inválido.'
            );
        }
    }

    private function createReversal(
        InventoryMovement $original,
        User $actor,
        string $reason,
        string $idempotencyKey
    ): InventoryMovement {
        $reversal = InventoryMovement::query()->create([
            'organization_id' => $original->organization_id,
            'type' => InventoryMovementType::Reversal,
            'status' => InventoryMovementStatus::Draft,
            'created_by_user_id' => $actor->id,
            'effective_at' => now(),
            'reason' => $reason,
            'source_type' => 'inventory_correction',
            'source_id' => $original->public_id,
            'source_reference' => $original->source_reference,
            'idempotency_key' =>
                'correction:'.$idempotencyKey.':reversal',
            'reverses_movement_id' => $original->id,
            'metadata' => [
                'correction_key' => $idempotencyKey,
                'reverses_public_id' => $original->public_id,
            ],
        ]);

        $originalLines = InventoryMovementLine::query()
            ->where('organization_id', $original->organization_id)
            ->where('inventory_movement_id', $original->id)
            ->orderBy('sequence')
            ->get();

        foreach ($originalLines as $line) {
            InventoryMovementLine::query()->create([
                'organization_id' => $original->organization_id,
                'inventory_movement_id' => $reversal->id,
                'sequence' => $line->sequence,
                'catalog_product_id' => $line->catalog_product_id,
                'condition' => $line->condition,
                'source_location_id' => $line->destination_location_id,
                'destination_location_id' => $line->source_location_id,
                'entered_quantity' =>
                    $line->getRawOriginal('entered_quantity'),
                'entered_unit_code' => $line->entered_unit_code,
                'conversion_factor' =>
                    $line->getRawOriginal('conversion_factor'),
                'base_quantity' =>
                    $line->getRawOriginal('base_quantity'),
                'base_unit_code' => $line->base_unit_code,
                'notes' => $line->notes,
            ]);
        }

        return $reversal;
    }
}
