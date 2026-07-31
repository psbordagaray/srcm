<?php

namespace App\Domain\Inventory;

use App\Enums\InventoryMovementStatus;
use App\Models\CatalogProduct;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class InventoryMovementCreator
{
    public function create(
        InventoryMovementDraftData $data,
        User $actor
    ): InventoryMovement {
        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): InventoryMovement {
            $organizationId = (int) $actor->current_organization_id;

            if ($organizationId <= 0) {
                throw new DomainException(
                    'El usuario no posee una organización activa.'
                );
            }

            $this->lockActiveOrganization($organizationId);
            $this->guardActor($organizationId, $actor, $data);

            $existing = InventoryMovement::query()
                ->where('organization_id', $organizationId)
                ->where(
                    'idempotency_key',
                    $normalized['idempotency_key']
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    ($existing->metadata['_creation_fingerprint'] ?? null)
                        !== $normalized['fingerprint']
                ) {
                    throw new DomainException(
                        'La clave de idempotencia ya fue utilizada con otro contenido.'
                    );
                }

                return $existing->load('lines');
            }

            $products = CatalogProduct::query()
                ->where('active', true)
                ->whereIn(
                    'id',
                    collect($normalized['lines'])
                        ->pluck('catalog_product_id')
                        ->unique()
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== collect($normalized['lines'])
                ->pluck('catalog_product_id')->unique()->count()) {
                throw new DomainException(
                    'Una línea referencia un producto inexistente o inactivo.'
                );
            }

            $metadata = $normalized['metadata'];
            $metadata['_creation_fingerprint'] =
                $normalized['fingerprint'];

            $movement = InventoryMovement::query()->create([
                'organization_id' => $organizationId,
                'type' => $data->type,
                'status' => InventoryMovementStatus::Draft,
                'created_by_user_id' => $actor->id,
                'effective_at' => $normalized['effective_at'],
                'reason' => $normalized['reason'],
                'source_type' => $normalized['source_type'],
                'source_id' => $normalized['source_id'],
                'source_reference' =>
                    $normalized['source_reference'],
                'idempotency_key' =>
                    $normalized['idempotency_key'],
                'metadata' => $metadata,
            ]);

            foreach ($normalized['lines'] as $index => $line) {
                $product = $products->get($line['catalog_product_id']);
                $baseQuantity = InventoryQuantity::multiply(
                    $line['entered_quantity'],
                    $line['conversion_factor']
                );

                InventoryQuantity::assertFitsScale(
                    $baseQuantity,
                    (int) $product->quantity_scale
                );

                InventoryMovementLine::query()->create([
                    'organization_id' => $organizationId,
                    'inventory_movement_id' => $movement->id,
                    'sequence' => $index + 1,
                    'catalog_product_id' =>
                        $line['catalog_product_id'],
                    'condition' => $line['condition'],
                    'source_location_id' =>
                        $line['source_location_id'],
                    'destination_location_id' =>
                        $line['destination_location_id'],
                    'entered_quantity' =>
                        $line['entered_quantity'],
                    'entered_unit_code' =>
                        $line['entered_unit_code'],
                    'conversion_factor' =>
                        $line['conversion_factor'],
                    'base_quantity' => $baseQuantity,
                    'base_unit_code' => $product->base_unit_code,
                    'notes' => $line['notes'],
                ]);
            }

            return $movement->load('lines');
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
        User $actor,
        InventoryMovementDraftData $data
    ): void {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $membership
            || ! $membership->role->canDraftInventoryMovement(
                $data->type
            )
        ) {
            throw new DomainException(
                'El rol del usuario no puede crear este tipo de movimiento.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(
        InventoryMovementDraftData $data
    ): array {
        $reason = Str::of($data->reason)->squish()->toString();
        $idempotencyKey = Str::of($data->idempotencyKey)
            ->trim()
            ->toString();

        if ($reason === '') {
            throw new DomainException(
                'El movimiento requiere un motivo.'
            );
        }

        if (
            $idempotencyKey === ''
            || Str::length($idempotencyKey) > 100
        ) {
            throw new DomainException(
                'La clave de idempotencia es inválida.'
            );
        }

        if ($data->lines === []) {
            throw new DomainException(
                'El movimiento requiere al menos una línea.'
            );
        }

        $lines = [];

        foreach ($data->lines as $line) {
            if (! $line instanceof InventoryMovementLineData) {
                throw new DomainException(
                    'Las líneas del movimiento son inválidas.'
                );
            }

            $enteredUnitCode = Str::lower(
                trim($line->enteredUnitCode)
            );

            if (
                $line->catalogProductId <= 0
                || $enteredUnitCode === ''
                || Str::length($enteredUnitCode) > 16
            ) {
                throw new DomainException(
                    'Una línea contiene producto o unidad inválidos.'
                );
            }

            $lines[] = [
                'catalog_product_id' => $line->catalogProductId,
                'condition' => $line->condition->value,
                'source_location_id' => $line->sourceLocationId,
                'destination_location_id' =>
                    $line->destinationLocationId,
                'entered_quantity' => InventoryQuantity::positive(
                    $line->enteredQuantity
                ),
                'entered_unit_code' => $enteredUnitCode,
                'conversion_factor' => InventoryQuantity::factor(
                    $line->conversionFactor
                ),
                'notes' => filled($line->notes)
                    ? trim((string) $line->notes)
                    : null,
            ];
        }

        $metadata = $this->canonicalize($data->metadata);
        unset($metadata['_creation_fingerprint']);

        $sourceType = $this->optional($data->sourceType);
        $sourceId = $this->optional($data->sourceId);
        $sourceReference = $this->optional($data->sourceReference);

        if (
            ($sourceType !== null && Str::length($sourceType) > 64)
            || ($sourceId !== null && Str::length($sourceId) > 100)
            || (
                $sourceReference !== null
                && Str::length($sourceReference) > 255
            )
        ) {
            throw new DomainException(
                'La referencia de origen del movimiento es inválida.'
            );
        }

        $normalized = [
            'type' => $data->type->value,
            'effective_at' => CarbonImmutable::instance(
                $data->effectiveAt
            )->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'reason' => $reason,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_reference' => $sourceReference,
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata,
            'lines' => $lines,
        ];

        try {
            $normalized['fingerprint'] = hash(
                'sha256',
                json_encode(
                    $normalized,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'Los metadatos del movimiento no son serializables.',
                previous: $exception
            );
        }

        return $normalized;
    }

    private function optional(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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

                continue;
            }

            if (
                ! is_null($item)
                && ! is_scalar($item)
            ) {
                throw new DomainException(
                    'Los metadatos sólo admiten valores serializables.'
                );
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
