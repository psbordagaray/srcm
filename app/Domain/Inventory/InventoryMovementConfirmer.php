<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryNegativeOverrideStatus;
use App\Enums\InventoryNegativeRequestStatus;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\InventoryNegativeIncident;
use App\Models\InventoryNegativeOverride;
use App\Models\InventoryNegativeRequest;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class InventoryMovementConfirmer
{
    public function __construct(
        private readonly InventoryBalanceProjector $projector,
        private readonly InventoryNegativeSnapshotBuilder $negativeSnapshots,
        private readonly InventoryNegativeIncidentRecorder $negativeIncidents,
        private readonly InventoryNegativeRegularizer $negativeRegularizer
    ) {
    }

    public function confirmWithNegativeOverride(
        InventoryMovement|int $movement,
        InventoryNegativeOverride|int $override,
        User $actor
    ): InventoryNegativeConfirmationResult {
        $movementId = $movement instanceof InventoryMovement
            ? (int) $movement->getKey()
            : $movement;
        $overrideId = $override instanceof InventoryNegativeOverride
            ? (int) $override->getKey()
            : $override;

        return DB::transaction(function () use (
            $movementId,
            $overrideId,
            $actor
        ): InventoryNegativeConfirmationResult {
            $organizationId = (int) $actor->current_organization_id;

            if ($organizationId <= 0) {
                throw new DomainException(
                    'El usuario no posee una organización activa.'
                );
            }

            $this->lockActiveOrganization($organizationId);

            $lockedMovement = InventoryMovement::query()
                ->whereKey($movementId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
            $lockedOverride = InventoryNegativeOverride::query()
                ->whereKey($overrideId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $lockedMovement || ! $lockedOverride) {
                throw new DomainException(
                    'El movimiento o el Override no existen en la organización activa.'
                );
            }

            $lockedRequest = InventoryNegativeRequest::query()
                ->whereKey(
                    $lockedOverride->inventory_negative_request_id
                )
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $lockedRequest) {
                throw new DomainException(
                    'El Override no posee una solicitud válida.'
                );
            }

            $this->guardActor($lockedMovement, $actor);
            $this->guardNegativeAuthorizationLinks(
                $lockedMovement,
                $lockedRequest,
                $lockedOverride,
                $actor
            );

            if (
                $lockedMovement->status
                    === InventoryMovementStatus::Confirmed
            ) {
                return $this->completedNegativeConfirmation(
                    $lockedMovement,
                    $lockedRequest,
                    $lockedOverride
                );
            }

            if (
                $lockedOverride->status
                    === InventoryNegativeOverrideStatus::Invalidated
                && $lockedRequest->status
                    === InventoryNegativeRequestStatus::Invalidated
            ) {
                return new InventoryNegativeConfirmationResult(
                    movement: $lockedMovement,
                    request: $lockedRequest,
                    override: $lockedOverride,
                    incident: null,
                    invalidated: true
                );
            }

            if (
                $lockedOverride->status
                    !== InventoryNegativeOverrideStatus::Active
                || $lockedRequest->status
                    !== InventoryNegativeRequestStatus::Approved
            ) {
                throw new DomainException(
                    'La autorización negativa no está activa y aprobada.'
                );
            }

            if (
                $lockedMovement->status
                    !== InventoryMovementStatus::Draft
            ) {
                return $this->invalidateNegativeAuthorization(
                    $lockedMovement,
                    $lockedRequest,
                    $lockedOverride,
                    'El movimiento dejó de estar disponible como borrador.'
                );
            }

            try {
                $snapshot = $this->negativeSnapshots->build(
                    $lockedMovement
                );
            } catch (DomainException) {
                return $this->invalidateNegativeAuthorization(
                    $lockedMovement,
                    $lockedRequest,
                    $lockedOverride,
                    'El movimiento ya no puede reproducir el snapshot autorizado.'
                );
            }

            if (
                ! $snapshot->requiresOverride()
                || ! hash_equals(
                    $lockedOverride->movement_fingerprint,
                    $snapshot->movementFingerprint
                )
                || ! hash_equals(
                    $lockedOverride->snapshot_fingerprint,
                    $snapshot->snapshotFingerprint
                )
                || ! hash_equals(
                    $lockedRequest->movement_fingerprint,
                    $snapshot->movementFingerprint
                )
                || ! hash_equals(
                    $lockedRequest->snapshot_fingerprint,
                    $snapshot->snapshotFingerprint
                )
            ) {
                return $this->invalidateNegativeAuthorization(
                    $lockedMovement,
                    $lockedRequest,
                    $lockedOverride,
                    'El movimiento o los saldos cambiaron después de la aprobación.'
                );
            }

            $lines = InventoryMovementLine::query()
                ->where('organization_id', $organizationId)
                ->where('inventory_movement_id', $lockedMovement->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                return $this->invalidateNegativeAuthorization(
                    $lockedMovement,
                    $lockedRequest,
                    $lockedOverride,
                    'El movimiento perdió sus líneas autorizadas.'
                );
            }

            $this->validateLines($lockedMovement, $lines);
            $lockedMovement->setRelation('lines', $lines);

            $this->projector->applyAuthorizedNegative(
                $lockedMovement,
                $snapshot
            );

            $incident = $this->negativeIncidents->record(
                $lockedMovement,
                $lockedRequest,
                $lockedOverride,
                $snapshot,
                $actor
            );
            $this->negativeRegularizer->apply(
                [$lockedMovement],
                $actor
            );
            $confirmedAt = now();

            $lockedMovement->forceFill([
                'status' => InventoryMovementStatus::Confirmed,
                'confirmed_at' => $confirmedAt,
                'confirmed_by_user_id' => $actor->id,
            ])->save();
            $lockedOverride->forceFill([
                'status' => InventoryNegativeOverrideStatus::Consumed,
                'consumed_at' => $confirmedAt,
            ])->save();
            $lockedRequest->forceFill([
                'status' => InventoryNegativeRequestStatus::Fulfilled,
                'fulfilled_at' => $confirmedAt,
            ])->save();

            return new InventoryNegativeConfirmationResult(
                movement: $lockedMovement->refresh()->load('lines'),
                request: $lockedRequest->refresh()->load('lines'),
                override: $lockedOverride->refresh(),
                incident: $incident,
                invalidated: false
            );
        }, 3);
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
            $organizationId = InventoryMovement::query()
                ->whereKey($movementId)
                ->value('organization_id');

            if ($organizationId === null) {
                throw new DomainException(
                    'El movimiento no existe.'
                );
            }

            $this->lockActiveOrganization((int) $organizationId);

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

            $activeOverride = InventoryNegativeOverride::query()
                ->where(
                    'organization_id',
                    $lockedMovement->organization_id
                )
                ->where(
                    'inventory_movement_id',
                    $lockedMovement->id
                )
                ->where(
                    'status',
                    InventoryNegativeOverrideStatus::Active->value
                )
                ->lockForUpdate()
                ->first(['id']);

            if ($activeOverride) {
                throw new DomainException(
                    'El movimiento posee un Override activo y requiere el flujo explícito de confirmación excepcional.'
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

            $this->guardFractionalContainerTraceability(
                $lockedMovement,
                $lines
            );

            $this->projector->apply($lockedMovement);
            $this->negativeRegularizer->apply(
                [$lockedMovement],
                $actor
            );

            $lockedMovement->forceFill([
                'status' => InventoryMovementStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $actor->id,
            ])->save();

            return $lockedMovement->refresh()->load('lines');
        }, 3);
    }

    /**
     * @return array{InventoryMovement, InventoryMovement}
     */
    public function confirmCorrectionPair(
        InventoryMovement|int $reversal,
        InventoryMovement|int $replacement,
        User $actor
    ): array {
        $reversalId = $reversal instanceof InventoryMovement
            ? (int) $reversal->getKey()
            : $reversal;
        $replacementId = $replacement instanceof InventoryMovement
            ? (int) $replacement->getKey()
            : $replacement;

        if ($reversalId === $replacementId) {
            throw new DomainException(
                'El reverso y el reemplazo deben ser movimientos diferentes.'
            );
        }

        return DB::transaction(function () use (
            $reversalId,
            $replacementId,
            $actor
        ): array {
            $movementIds = [$reversalId, $replacementId];
            $organizationIds = InventoryMovement::query()
                ->whereIn('id', $movementIds)
                ->pluck('organization_id');

            if (
                $organizationIds->count() !== 2
                || $organizationIds->unique()->count() !== 1
            ) {
                throw new DomainException(
                    'El reverso y el reemplazo deben existir en una misma organización.'
                );
            }

            $organizationId = (int) $organizationIds->first();
            $this->lockActiveOrganization($organizationId);

            $locked = InventoryMovement::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $movementIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedReversal = $locked->get($reversalId);
            $lockedReplacement = $locked->get($replacementId);

            if (! $lockedReversal || ! $lockedReplacement) {
                throw new DomainException(
                    'No pudieron bloquearse ambos movimientos de corrección.'
                );
            }

            if ($lockedReversal->type !== InventoryMovementType::Reversal) {
                throw new DomainException(
                    'El primer movimiento de la corrección debe ser un reverso.'
                );
            }

            foreach ([$lockedReversal, $lockedReplacement] as $movement) {
                $this->guardActor($movement, $actor);

                if ($movement->status !== InventoryMovementStatus::Draft) {
                    throw new DomainException(
                        'Ambos movimientos de la corrección deben estar en borrador.'
                    );
                }
            }

            $lines = InventoryMovementLine::query()
                ->where('organization_id', $organizationId)
                ->whereIn('inventory_movement_id', $movementIds)
                ->orderBy('inventory_movement_id')
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
                ->groupBy('inventory_movement_id');

            foreach ([$lockedReversal, $lockedReplacement] as $movement) {
                $movementLines = $lines->get(
                    $movement->id,
                    collect()
                )->values();

                if ($movementLines->isEmpty()) {
                    throw new DomainException(
                        'La corrección no admite movimientos sin líneas.'
                    );
                }

                $this->validateLines($movement, $movementLines);
                $movement->setRelation('lines', $movementLines);
            }

            $this->projector->applyMany([
                $lockedReversal,
                $lockedReplacement,
            ]);
            $this->negativeRegularizer->apply([
                $lockedReversal,
                $lockedReplacement,
            ], $actor);

            $confirmedAt = now();

            foreach ([$lockedReversal, $lockedReplacement] as $movement) {
                $movement->forceFill([
                    'status' => InventoryMovementStatus::Confirmed,
                    'confirmed_at' => $confirmedAt,
                    'confirmed_by_user_id' => $actor->id,
                ])->save();
            }

            return [
                $lockedReversal->refresh()->load('lines'),
                $lockedReplacement->refresh()->load('lines'),
            ];
        }, 3);
    }

    private function guardNegativeAuthorizationLinks(
        InventoryMovement $movement,
        InventoryNegativeRequest $request,
        InventoryNegativeOverride $override,
        User $actor
    ): void {
        if (
            (int) $request->inventory_movement_id
                !== (int) $movement->id
            || (int) $override->inventory_movement_id
                !== (int) $movement->id
            || (int) $override->inventory_negative_request_id
                !== (int) $request->id
            || (int) $request->requested_by_user_id
                !== (int) $override->authorized_user_id
            || (int) $movement->created_by_user_id
                !== (int) $override->authorized_user_id
        ) {
            throw new DomainException(
                'El Override no corresponde al movimiento y solicitante exactos.'
            );
        }

        if ((int) $override->authorized_user_id !== (int) $actor->id) {
            throw new DomainException(
                'El Override sólo puede consumirlo el usuario autorizado.'
            );
        }

        if (
            ! hash_equals(
                $request->movement_fingerprint,
                $override->movement_fingerprint
            )
            || ! hash_equals(
                $request->snapshot_fingerprint,
                $override->snapshot_fingerprint
            )
        ) {
            throw new DomainException(
                'La solicitud y el Override conservan huellas diferentes.'
            );
        }
    }

    private function completedNegativeConfirmation(
        InventoryMovement $movement,
        InventoryNegativeRequest $request,
        InventoryNegativeOverride $override
    ): InventoryNegativeConfirmationResult {
        if (
            $override->status
                !== InventoryNegativeOverrideStatus::Consumed
            || $request->status
                !== InventoryNegativeRequestStatus::Fulfilled
        ) {
            throw new DomainException(
                'La confirmación existente no cerró su autorización negativa.'
            );
        }

        $incident = InventoryNegativeIncident::query()
            ->where('organization_id', $movement->organization_id)
            ->where('inventory_movement_id', $movement->id)
            ->where('inventory_negative_request_id', $request->id)
            ->where('inventory_negative_override_id', $override->id)
            ->lockForUpdate()
            ->first();

        if (! $incident) {
            throw new DomainException(
                'La confirmación negativa existente no posee incidencia.'
            );
        }

        return new InventoryNegativeConfirmationResult(
            movement: $movement->load('lines'),
            request: $request->load('lines'),
            override: $override,
            incident: $incident->load(['lines', 'statusHistory']),
            invalidated: false
        );
    }

    private function invalidateNegativeAuthorization(
        InventoryMovement $movement,
        InventoryNegativeRequest $request,
        InventoryNegativeOverride $override,
        string $reason
    ): InventoryNegativeConfirmationResult {
        $invalidatedAt = now();

        $override->forceFill([
            'status' => InventoryNegativeOverrideStatus::Invalidated,
            'invalidated_at' => $invalidatedAt,
            'invalidation_reason' => $reason,
        ])->save();
        $request->forceFill([
            'status' => InventoryNegativeRequestStatus::Invalidated,
            'invalidated_at' => $invalidatedAt,
            'invalidation_reason' => $reason,
        ])->save();

        return new InventoryNegativeConfirmationResult(
            movement: $movement->refresh()->load('lines'),
            request: $request->refresh()->load('lines'),
            override: $override->refresh(),
            incident: null,
            invalidated: true
        );
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

        $membership = OrganizationMembership::query()
            ->where('organization_id', $movement->organization_id)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $membership) {
            throw new DomainException(
                'El usuario no posee una membresía activa en la organización.'
            );
        }

        if (! $membership->role->canConfirmInventoryMovement(
            $movement->type
        )) {
            throw new DomainException(
                'El rol del usuario no puede confirmar este tipo de movimiento.'
            );
        }
    }

    /**
     * @param Collection<int, InventoryMovementLine> $lines
     */
    /**
     * @param Collection<int, InventoryMovementLine> $lines
     */
    private function guardFractionalContainerTraceability(
        InventoryMovement $movement,
        Collection $lines
    ): void {
        if ($movement->type !== InventoryMovementType::Issue) {
            return;
        }

        $products = CatalogProduct::query()
            ->whereIn(
                'id',
                $lines->pluck('catalog_product_id')->unique()
            )
            ->get()
            ->keyBy('id');

        foreach ($lines as $line) {
            $product = $products->get(
                $line->catalog_product_id
            );

            if (
                ! $product
                || ! $product->allowsFractionalQuantity()
                || $line->source_location_id === null
                || (string) $line->entered_unit_code
                    !== (string) $line->base_unit_code
                || ! InventoryQuantity::equal(
                    $line->conversion_factor,
                    '1'
                )
            ) {
                continue;
            }

            $hasRegisteredContainer = DB::table(
                'fractional_containers'
            )
                ->where(
                    'organization_id',
                    $movement->organization_id
                )
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
                ->exists();

            if (! $hasRegisteredContainer) {
                continue;
            }

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
                    'La salida fraccionada posee contenedores '
                    .'registrados y requiere trazabilidad física '
                    .'antes de confirmarse.'
                );
            }

            $total = InventoryQuantity::signed('0');
            $expectedSequence = 1;

            foreach ($history as $record) {
                $containerMatches = DB::table(
                    'fractional_containers'
                )
                    ->where(
                        'id',
                        $record->fractional_container_id
                    )
                    ->where(
                        'organization_id',
                        $movement->organization_id
                    )
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
                    ->exists();

                if (
                    ! $containerMatches
                    || (int) $record->sequence
                        !== $expectedSequence
                    || (string) $record->policy
                        !== 'agotar_contenedor_abierto'
                    || (string) $record->base_unit_code
                        !== (string) $line->base_unit_code
                ) {
                    throw new DomainException(
                        'La trazabilidad fraccionada no coincide '
                        .'con la línea que intenta confirmarse.'
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
                        'La trazabilidad fraccionada contiene '
                        .'aritmética inconsistente.'
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
                    'La trazabilidad fraccionada no cubre '
                    .'exactamente la cantidad de la línea.'
                );
            }
        }
    }

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
