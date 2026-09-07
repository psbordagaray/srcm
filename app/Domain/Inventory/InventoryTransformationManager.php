<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryTransformationDirection;
use App\Enums\InventoryTransformationOutputRole;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\InventoryTransformation;
use App\Models\InventoryTransformationLineage;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class InventoryTransformationManager
{
    /**
     * @param list<int> $inputMovementLineIds
     * @param list<array{
     *     movement_line_id: int,
     *     role: InventoryTransformationOutputRole|string
     * }> $outputs
     */
    public function record(
        array $inputMovementLineIds,
        array $outputs,
        DateTimeInterface $effectiveAt,
        string $idempotencyKey,
        User $actor
    ): InventoryTransformation {
        $organizationId = (int) $actor->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(
                'El usuario no posee una organización activa.'
            );
        }

        $normalized = $this->normalize(
            $organizationId,
            $inputMovementLineIds,
            $outputs,
            $effectiveAt,
            $idempotencyKey
        );

        return DB::transaction(function () use (
            $organizationId,
            $normalized,
            $actor
        ): InventoryTransformation {
            $this->lockActiveOrganization($organizationId);
            $this->guardActor($organizationId, $actor);

            $existing = InventoryTransformation::query()
                ->where('organization_id', $organizationId)
                ->where(
                    'idempotency_key',
                    $normalized['idempotency_key']
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals(
                    (string) $existing->fingerprint,
                    $normalized['fingerprint']
                )) {
                    throw new DomainException(
                        'La clave de idempotencia de transformación ya fue utilizada con otro contenido.'
                    );
                }

                return $existing->load('lineages.movementLine.movement');
            }

            $allLineIds = array_values(array_unique(array_merge(
                $normalized['input_line_ids'],
                array_column($normalized['outputs'], 'movement_line_id')
            )));

            $lines = InventoryMovementLine::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $allLineIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lines->count() !== count($allLineIds)) {
                throw new DomainException(
                    'Toda línea de transformación debe existir en la organización activa.'
                );
            }

            $movementIds = $lines
                ->pluck('inventory_movement_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $movements = InventoryMovement::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $movementIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($movements->count() !== count($movementIds)) {
                throw new DomainException(
                    'No pudieron bloquearse todos los movimientos de la transformación.'
                );
            }

            foreach ($normalized['input_line_ids'] as $lineId) {
                $this->guardInputLine(
                    $lines->get($lineId),
                    $movements
                );
            }

            foreach ($normalized['outputs'] as $output) {
                $this->guardOutputLine(
                    $lines->get($output['movement_line_id']),
                    $movements
                );
            }

            $transformation = InventoryTransformation::query()->create([
                'organization_id' => $organizationId,
                'effective_at' => $normalized['effective_at'],
                'created_by_user_id' => $actor->id,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            foreach (
                $normalized['input_line_ids'] as $index => $lineId
            ) {
                InventoryTransformationLineage::query()->create([
                    'organization_id' => $organizationId,
                    'inventory_transformation_id' => $transformation->id,
                    'inventory_movement_line_id' => $lineId,
                    'direction' => InventoryTransformationDirection::Input,
                    'output_role' => null,
                    'sequence' => $index + 1,
                ]);
            }

            foreach ($normalized['outputs'] as $index => $output) {
                InventoryTransformationLineage::query()->create([
                    'organization_id' => $organizationId,
                    'inventory_transformation_id' => $transformation->id,
                    'inventory_movement_line_id' => $output['movement_line_id'],
                    'direction' => InventoryTransformationDirection::Output,
                    'output_role' => $output['role'],
                    'sequence' => $index + 1,
                ]);
            }

            return $transformation
                ->refresh()
                ->load('lineages.movementLine.movement');
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
                'La organización no está activa.'
            );
        }
    }

    private function guardActor(
        int $organizationId,
        User $actor
    ): void {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $membership
            || ! $membership->role->canRecordInventoryTransformation()
        ) {
            throw new DomainException(
                'El rol del usuario no puede registrar transformaciones de inventario.'
            );
        }
    }

    private function guardInputLine(
        InventoryMovementLine $line,
        $movements
    ): void {
        $movement = $movements->get($line->inventory_movement_id);

        if (
            ! $movement
            || $movement->status !== InventoryMovementStatus::Confirmed
        ) {
            throw new DomainException(
                'Toda entrada de transformación debe estar confirmada.'
            );
        }

        if (
            $movement->type !== InventoryMovementType::TransformationInput
            || $line->source_location_id === null
            || $line->destination_location_id !== null
        ) {
            throw new DomainException(
                'La entrada no posee semántica física de consumo por transformación.'
            );
        }
    }

    private function guardOutputLine(
        InventoryMovementLine $line,
        $movements
    ): void {
        $movement = $movements->get($line->inventory_movement_id);

        if (
            ! $movement
            || $movement->status !== InventoryMovementStatus::Confirmed
        ) {
            throw new DomainException(
                'Toda salida de transformación debe estar confirmada.'
            );
        }

        if (
            $movement->type !== InventoryMovementType::TransformationOutput
            || $line->source_location_id !== null
            || $line->destination_location_id === null
        ) {
            throw new DomainException(
                'La salida no posee semántica física de producción por transformación.'
            );
        }
    }

    /**
     * @param list<int> $inputMovementLineIds
     * @param list<array{
     *     movement_line_id: int,
     *     role: InventoryTransformationOutputRole|string
     * }> $outputs
     * @return array{
     *     input_line_ids: list<int>,
     *     outputs: list<array{
     *         movement_line_id: int,
     *         role: InventoryTransformationOutputRole
     *     }>,
     *     effective_at: CarbonImmutable,
     *     idempotency_key: string,
     *     fingerprint: string
     * }
     */
    private function normalize(
        int $organizationId,
        array $inputMovementLineIds,
        array $outputs,
        DateTimeInterface $effectiveAt,
        string $idempotencyKey
    ): array {
        $idempotencyKey = Str::of($idempotencyKey)
            ->trim()
            ->toString();

        if (
            $idempotencyKey === ''
            || Str::length($idempotencyKey) > 100
        ) {
            throw new DomainException(
                'La clave de idempotencia de transformación es inválida.'
            );
        }

        if ($inputMovementLineIds === []) {
            throw new DomainException(
                'La transformación requiere al menos una entrada.'
            );
        }

        if ($outputs === []) {
            throw new DomainException(
                'La transformación requiere al menos una salida.'
            );
        }

        $inputIds = [];

        foreach ($inputMovementLineIds as $lineId) {
            $lineId = (int) $lineId;

            if ($lineId <= 0 || in_array($lineId, $inputIds, true)) {
                throw new DomainException(
                    'Las entradas de transformación contienen una línea inválida o duplicada.'
                );
            }

            $inputIds[] = $lineId;
        }

        sort($inputIds, SORT_NUMERIC);

        $normalizedOutputs = [];
        $seenOutputIds = [];

        foreach ($outputs as $output) {
            if (! is_array($output)) {
                throw new DomainException(
                    'Las salidas de transformación son inválidas.'
                );
            }

            $lineId = (int) ($output['movement_line_id'] ?? 0);
            $rawRole = $output['role'] ?? null;
            $role = $rawRole instanceof InventoryTransformationOutputRole
                ? $rawRole
                : (
                    is_string($rawRole)
                        ? InventoryTransformationOutputRole::tryFrom(
                            trim($rawRole)
                        )
                        : null
                );

            if (
                $lineId <= 0
                || $role === null
                || isset($seenOutputIds[$lineId])
            ) {
                throw new DomainException(
                    'Las salidas de transformación contienen una línea o rol inválidos.'
                );
            }

            if (in_array($lineId, $inputIds, true)) {
                throw new DomainException(
                    'Una misma línea no puede ser entrada y salida de la transformación.'
                );
            }

            $seenOutputIds[$lineId] = true;
            $normalizedOutputs[] = [
                'movement_line_id' => $lineId,
                'role' => $role,
            ];
        }

        usort(
            $normalizedOutputs,
            static fn (array $left, array $right): int =>
                $left['movement_line_id'] <=> $right['movement_line_id']
        );

        $effective = CarbonImmutable::instance($effectiveAt)->utc();

        $fingerprintPayload = [
            'organization_id' => $organizationId,
            'effective_at' => $effective->format('Y-m-d\TH:i:s.u\Z'),
            'input_line_ids' => $inputIds,
            'outputs' => array_map(
                static fn (array $output): array => [
                    'movement_line_id' => $output['movement_line_id'],
                    'role' => $output['role']->value,
                ],
                $normalizedOutputs
            ),
        ];

        try {
            $fingerprint = hash(
                'sha256',
                json_encode(
                    $fingerprintPayload,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'No pudo serializarse la intención de transformación.',
                previous: $exception
            );
        }

        return [
            'input_line_ids' => $inputIds,
            'outputs' => $normalizedOutputs,
            'effective_at' => $effective,
            'idempotency_key' => $idempotencyKey,
            'fingerprint' => $fingerprint,
        ];
    }
}
