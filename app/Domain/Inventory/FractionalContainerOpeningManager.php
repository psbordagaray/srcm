<?php

namespace App\Domain\Inventory;

use App\Enums\FractionalContainerState;
use App\Enums\InventoryCondition;
use App\Models\CatalogProduct;
use App\Models\FractionalContainer;
use App\Models\FractionalContainerOpeningAuthorization;
use App\Models\FractionalContainerOpeningEvent;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FractionalContainerOpeningManager
{
    /**
     * @param list<int|string> $exactContainerIds
     */
    public function authorize(
        int $organizationId,
        int $catalogProductId,
        int $inventoryLocationId,
        InventoryCondition $condition,
        User $authorizer,
        string $idempotencyKey,
        DateTimeInterface|string $validFrom,
        DateTimeInterface|string $validUntil,
        int $maxConcurrentOpenContainers,
        ?int $maxNewOpenings = null,
        ?int $targetReadyOpenCount = null,
        array $exactContainerIds = []
    ): FractionalContainerOpeningAuthorization {
        $key = $this->normalizeKey($idempotencyKey);
        $from = CarbonImmutable::parse($validFrom)
            ->utc()
            ->startOfSecond();
        $until = CarbonImmutable::parse($validUntil)
            ->utc()
            ->startOfSecond();

        if (! $until->greaterThan($from)) {
            throw new DomainException(
                'La ventana de autorización de apertura debe tener '
                .'un fin posterior a su inicio.'
            );
        }

        if (! $until->greaterThan(CarbonImmutable::now('UTC'))) {
            throw new DomainException(
                'No puede emitirse una autorización de apertura '
                .'que ya se encuentre vencida.'
            );
        }

        if ($maxConcurrentOpenContainers < 1) {
            throw new DomainException(
                'La autorización debe permitir al menos un '
                .'contenedor abierto simultáneamente.'
            );
        }

        if ($maxNewOpenings !== null && $maxNewOpenings < 1) {
            throw new DomainException(
                'El límite de aperturas nuevas debe ser positivo.'
            );
        }

        if (
            $targetReadyOpenCount !== null
            && (
                $targetReadyOpenCount < 0
                || $targetReadyOpenCount
                    > $maxConcurrentOpenContainers
            )
        ) {
            throw new DomainException(
                'El objetivo de contenedores preparados debe estar '
                .'dentro del límite simultáneo autorizado.'
            );
        }

        $normalizedExactIds =
            $this->normalizeContainerIds($exactContainerIds);

        return DB::transaction(function () use (
            $organizationId,
            $catalogProductId,
            $inventoryLocationId,
            $condition,
            $authorizer,
            $key,
            $from,
            $until,
            $maxConcurrentOpenContainers,
            $maxNewOpenings,
            $targetReadyOpenCount,
            $normalizedExactIds
        ): FractionalContainerOpeningAuthorization {
            $this->lockAuthorizationScope(
                $organizationId,
                $catalogProductId,
                $inventoryLocationId,
                $authorizer
            );

            $existing = FractionalContainerOpeningAuthorization::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertAuthorizationReplay(
                    $existing,
                    $catalogProductId,
                    $inventoryLocationId,
                    $condition,
                    $authorizer,
                    $from,
                    $until,
                    $maxConcurrentOpenContainers,
                    $maxNewOpenings,
                    $targetReadyOpenCount,
                    $normalizedExactIds
                );

                return $existing->refresh();
            }

            $overlap = FractionalContainerOpeningAuthorization::query()
                ->where('organization_id', $organizationId)
                ->where('catalog_product_id', $catalogProductId)
                ->where('inventory_location_id', $inventoryLocationId)
                ->where('condition', $condition->value)
                ->whereNull('revoked_at')
                ->where('valid_from', '<', $until)
                ->where('valid_until', '>', $from)
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw new DomainException(
                    'Ya existe un sobre operativo de apertura '
                    .'superpuesto para el mismo producto, ubicación '
                    .'y condición.'
                );
            }

            $scopeContainers = FractionalContainer::query()
                ->forOrganization($organizationId)
                ->where('catalog_product_id', $catalogProductId)
                ->where(
                    'inventory_location_id',
                    $inventoryLocationId
                )
                ->where('condition', $condition->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $openCount = $scopeContainers
                ->filter(
                    static fn (FractionalContainer $container): bool =>
                        $container->state
                            === FractionalContainerState::Open
                )
                ->count();

            if ($openCount > $maxConcurrentOpenContainers) {
                throw new DomainException(
                    'La cantidad actualmente abierta ya excede '
                    .'el límite simultáneo solicitado.'
                );
            }

            if ($normalizedExactIds !== []) {
                $byId = $scopeContainers->keyBy('id');

                foreach ($normalizedExactIds as $containerId) {
                    $container = $byId->get($containerId);

                    if (! $container instanceof FractionalContainer) {
                        throw new DomainException(
                            'Un contenedor preautorizado no pertenece '
                            .'al producto, ubicación y condición '
                            .'del sobre operativo.'
                        );
                    }

                    if (
                        $container->state
                            !== FractionalContainerState::Sealed
                    ) {
                        throw new DomainException(
                            'La preautorización exacta sólo puede '
                            .'incluir contenedores actualmente sellados.'
                        );
                    }
                }
            }

            $authorization =
                FractionalContainerOpeningAuthorization::query()
                    ->create([
                        'organization_id' => $organizationId,
                        'catalog_product_id' => $catalogProductId,
                        'inventory_location_id' =>
                            $inventoryLocationId,
                        'condition' => $condition,
                        'authorized_by_user_id' =>
                            $authorizer->id,
                        'valid_from' => $from,
                        'valid_until' => $until,
                        'max_concurrent_open_containers' =>
                            $maxConcurrentOpenContainers,
                        'max_new_openings' => $maxNewOpenings,
                        'target_ready_open_count' =>
                            $targetReadyOpenCount,
                        'idempotency_key' => $key,
                    ]);

            foreach ($normalizedExactIds as $containerId) {
                DB::table(
                    'fractional_container_opening_authorization_containers'
                )->insert([
                    'opening_authorization_id' =>
                        $authorization->id,
                    'fractional_container_id' =>
                        $containerId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $authorization->refresh();
        }, 3);
    }

    /**
     * @param list<int|string> $containerIds
     * @return Collection<int, FractionalContainerOpeningEvent>
     */
    public function openBatch(
        FractionalContainerOpeningAuthorization|int $authorization,
        User $actor,
        array $containerIds,
        string $idempotencyKey
    ): Collection {
        $authorizationId =
            $authorization
                instanceof FractionalContainerOpeningAuthorization
                    ? (int) $authorization->getKey()
                    : $authorization;

        $normalizedIds =
            $this->normalizeContainerIds($containerIds);

        if ($normalizedIds === []) {
            throw new DomainException(
                'La apertura operativa requiere al menos '
                .'un contenedor.'
            );
        }

        $batchKey = $this->normalizeKey($idempotencyKey);

        return DB::transaction(function () use (
            $authorizationId,
            $actor,
            $normalizedIds,
            $batchKey
        ): Collection {
            $authorization =
                FractionalContainerOpeningAuthorization::query()
                    ->whereKey($authorizationId)
                    ->lockForUpdate()
                    ->first();

            if (! $authorization) {
                throw new DomainException(
                    'La autorización operativa de apertura no existe.'
                );
            }

            $this->guardOpeningActor($authorization, $actor);

            $eventKeys = [];

            foreach ($normalizedIds as $containerId) {
                $eventKeys[$containerId] =
                    $this->eventKey(
                        $batchKey,
                        $containerId
                    );
            }

            $replay = FractionalContainerOpeningEvent::query()
                ->where(
                    'organization_id',
                    $authorization->organization_id
                )
                ->where(
                    'opening_authorization_id',
                    $authorization->id
                )
                ->whereIn(
                    'idempotency_key',
                    array_values($eventKeys)
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('fractional_container_id');

            if ($replay->isNotEmpty()) {
                if ($replay->count() !== count($normalizedIds)) {
                    throw new DomainException(
                        'El replay de apertura está incompleto '
                        .'y se rechaza de forma fail-closed.'
                    );
                }

                $orderedReplay = collect();

                foreach ($normalizedIds as $containerId) {
                    $event = $replay->get($containerId);

                    if (
                        ! $event
                        instanceof FractionalContainerOpeningEvent
                        || (string) $event->idempotency_key
                            !== $eventKeys[$containerId]
                        || (int) $event->opened_by_user_id
                            !== (int) $actor->id
                    ) {
                        throw new DomainException(
                            'La clave de replay de apertura colisiona '
                            .'con otro contrato.'
                        );
                    }

                    $orderedReplay->push($event);
                }

                return $orderedReplay;
            }

            if ($authorization->revoked_at !== null) {
                throw new DomainException(
                    'La autorización operativa fue revocada.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            if (
                $now->lessThan($authorization->valid_from)
                || ! $now->lessThan($authorization->valid_until)
            ) {
                throw new DomainException(
                    'La autorización operativa no está vigente.'
                );
            }

            $restrictedIds = DB::table(
                'fractional_container_opening_authorization_containers'
            )
                ->where(
                    'opening_authorization_id',
                    $authorization->id
                )
                ->orderBy('fractional_container_id')
                ->lockForUpdate()
                ->pluck('fractional_container_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($restrictedIds !== []) {
                $allowed = array_fill_keys(
                    $restrictedIds,
                    true
                );

                foreach ($normalizedIds as $containerId) {
                    if (! isset($allowed[$containerId])) {
                        throw new DomainException(
                            'El contenedor no forma parte del lote '
                            .'exactamente preautorizado.'
                        );
                    }
                }
            }

            $scopeContainers = FractionalContainer::query()
                ->forOrganization(
                    (int) $authorization->organization_id
                )
                ->where(
                    'catalog_product_id',
                    $authorization->catalog_product_id
                )
                ->where(
                    'inventory_location_id',
                    $authorization->inventory_location_id
                )
                ->where(
                    'condition',
                    $authorization->condition->value
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $scopeById = $scopeContainers->keyBy('id');
            $openCount = $scopeContainers
                ->filter(
                    static fn (FractionalContainer $container): bool =>
                        $container->state
                            === FractionalContainerState::Open
                )
                ->count();

            $targets = collect();

            foreach ($normalizedIds as $containerId) {
                $container = $scopeById->get($containerId);

                if (! $container instanceof FractionalContainer) {
                    throw new DomainException(
                        'El contenedor no pertenece al alcance '
                        .'de la autorización operativa.'
                    );
                }

                if (
                    $container->state
                        !== FractionalContainerState::Sealed
                ) {
                    throw new DomainException(
                        'Una apertura nueva requiere un contenedor '
                        .'actualmente sellado.'
                    );
                }

                $targets->push($container);
            }

            $projectedOpen =
                $openCount + $targets->count();

            if (
                $projectedOpen
                    > (int) $authorization
                        ->max_concurrent_open_containers
            ) {
                throw new DomainException(
                    'La apertura excede el máximo simultáneo '
                    .'del sobre operativo.'
                );
            }

            if ($authorization->max_new_openings !== null) {
                $usedOpenings =
                    FractionalContainerOpeningEvent::query()
                        ->where(
                            'opening_authorization_id',
                            $authorization->id
                        )
                        ->lockForUpdate()
                        ->count();

                if (
                    $usedOpenings + $targets->count()
                        > (int) $authorization->max_new_openings
                ) {
                    throw new DomainException(
                        'La apertura excede la cuota de aperturas '
                        .'nuevas del sobre operativo.'
                    );
                }
            }

            $events = collect();
            $openedAt = CarbonImmutable::now('UTC');

            foreach ($targets as $container) {
                $remaining =
                    InventoryQuantity::positive(
                        $container->remaining_base_quantity
                    );

                $updated = DB::table('fractional_containers')
                    ->where('id', $container->id)
                    ->where(
                        'organization_id',
                        $authorization->organization_id
                    )
                    ->where(
                        'state',
                        FractionalContainerState::Sealed->value
                    )
                    ->where(
                        'remaining_base_quantity',
                        $remaining
                    )
                    ->update([
                        'state' =>
                            FractionalContainerState::Open->value,
                        'updated_at' => now(),
                    ]);

                if ($updated !== 1) {
                    throw new DomainException(
                        'El contenedor cambió durante la apertura '
                        .'operativa y la operación se abortó.'
                    );
                }

                $event =
                    FractionalContainerOpeningEvent::query()
                        ->create([
                            'organization_id' =>
                                $authorization->organization_id,
                            'opening_authorization_id' =>
                                $authorization->id,
                            'fractional_container_id' =>
                                $container->id,
                            'opened_by_user_id' =>
                                $actor->id,
                            'idempotency_key' =>
                                $eventKeys[
                                    (int) $container->id
                                ],
                            'state_before' =>
                                FractionalContainerState::Sealed,
                            'state_after' =>
                                FractionalContainerState::Open,
                            'remaining_before' =>
                                $remaining,
                            'remaining_after' =>
                                $remaining,
                            'opened_at' => $openedAt,
                        ]);

                $events->push($event);
            }

            return $events;
        }, 3);
    }

    public function revoke(
        FractionalContainerOpeningAuthorization|int $authorization,
        User $actor,
        string $reason
    ): FractionalContainerOpeningAuthorization {
        $authorizationId =
            $authorization
                instanceof FractionalContainerOpeningAuthorization
                    ? (int) $authorization->getKey()
                    : $authorization;

        $normalizedReason = Str::of($reason)
            ->squish()
            ->toString();

        if (
            $normalizedReason === ''
            || Str::length($normalizedReason) > 500
        ) {
            throw new DomainException(
                'La revocación requiere un motivo válido.'
            );
        }

        return DB::transaction(function () use (
            $authorizationId,
            $actor,
            $normalizedReason
        ): FractionalContainerOpeningAuthorization {
            $locked =
                FractionalContainerOpeningAuthorization::query()
                    ->whereKey($authorizationId)
                    ->lockForUpdate()
                    ->first();

            if (! $locked) {
                throw new DomainException(
                    'La autorización operativa no existe.'
                );
            }

            $this->guardAuthorizationActor(
                (int) $locked->organization_id,
                $actor
            );

            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $updated = DB::table(
                'fractional_container_opening_authorizations'
            )
                ->where('id', $locked->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_by_user_id' => $actor->id,
                    'revoked_at' => now(),
                    'revocation_reason' =>
                        $normalizedReason,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new DomainException(
                    'No pudo revocarse de forma exacta '
                    .'la autorización operativa.'
                );
            }

            return $locked->refresh();
        }, 3);
    }

    private function lockAuthorizationScope(
        int $organizationId,
        int $catalogProductId,
        int $inventoryLocationId,
        User $authorizer
    ): void {
        $organization = Organization::query()
            ->whereKey($organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $organization) {
            throw new DomainException(
                'La organización de la autorización no está activa.'
            );
        }

        $this->guardAuthorizationActor(
            $organizationId,
            $authorizer
        );

        $product = CatalogProduct::query()
            ->whereKey($catalogProductId)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $product
            || ! $product->allowsFractionalQuantity()
        ) {
            throw new DomainException(
                'La autorización requiere un producto '
                .'activo fraccionable.'
            );
        }

        $location = InventoryLocation::query()
            ->whereKey($inventoryLocationId)
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $location) {
            throw new DomainException(
                'La ubicación de apertura no pertenece '
                .'a la organización activa.'
            );
        }
    }

    private function guardAuthorizationActor(
        int $organizationId,
        User $actor
    ): void {
        if (
            (int) $actor->current_organization_id
                !== $organizationId
        ) {
            throw new DomainException(
                'La organización activa del usuario no coincide '
                .'con la autorización.'
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
            || ! $membership->role
                ->canAuthorizeFractionalContainerOpening()
        ) {
            throw new DomainException(
                'El rol del usuario no puede autorizar '
                .'aperturas de contenedores fraccionables.'
            );
        }
    }

    private function guardOpeningActor(
        FractionalContainerOpeningAuthorization $authorization,
        User $actor
    ): void {
        if (
            (int) $actor->current_organization_id
                !== (int) $authorization->organization_id
        ) {
            throw new DomainException(
                'La organización activa del operador no coincide '
                .'con el sobre operativo.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where(
                'organization_id',
                $authorization->organization_id
            )
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $membership
            || ! $membership->role
                ->canOpenFractionalContainer()
        ) {
            throw new DomainException(
                'El rol del usuario no puede ejecutar '
                .'aperturas de contenedores fraccionables.'
            );
        }
    }

    /**
     * @param list<int|string> $containerIds
     * @return list<int>
     */
    private function normalizeContainerIds(
        array $containerIds
    ): array {
        if (! array_is_list($containerIds)) {
            throw new DomainException(
                'Los contenedores deben expresarse '
                .'como una lista ordenada.'
            );
        }

        $normalized = [];
        $seen = [];

        foreach ($containerIds as $containerId) {
            if (
                (
                    ! is_int($containerId)
                    && ! ctype_digit((string) $containerId)
                )
                || (int) $containerId <= 0
            ) {
                throw new DomainException(
                    'La lista contiene un identificador '
                    .'de contenedor inválido.'
                );
            }

            $id = (int) $containerId;

            if (isset($seen[$id])) {
                throw new DomainException(
                    'Un contenedor no puede repetirse '
                    .'dentro de la misma operación.'
                );
            }

            $seen[$id] = true;
            $normalized[] = $id;
        }

        sort($normalized);

        return $normalized;
    }

    private function normalizeKey(string $value): string
    {
        $key = Str::of($value)->squish()->toString();

        if (
            $key === ''
            || Str::length($key) > 120
        ) {
            throw new DomainException(
                'La clave de idempotencia no es válida.'
            );
        }

        return $key;
    }

    private function eventKey(
        string $batchKey,
        int $containerId
    ): string {
        return hash(
            'sha256',
            $batchKey.'|container:'.$containerId
        );
    }

    /**
     * @param list<int> $exactContainerIds
     */
    private function assertAuthorizationReplay(
        FractionalContainerOpeningAuthorization $existing,
        int $catalogProductId,
        int $inventoryLocationId,
        InventoryCondition $condition,
        User $authorizer,
        CarbonImmutable $validFrom,
        CarbonImmutable $validUntil,
        int $maxConcurrentOpenContainers,
        ?int $maxNewOpenings,
        ?int $targetReadyOpenCount,
        array $exactContainerIds
    ): void {
        $storedIds = DB::table(
            'fractional_container_opening_authorization_containers'
        )
            ->where(
                'opening_authorization_id',
                $existing->id
            )
            ->orderBy('fractional_container_id')
            ->pluck('fractional_container_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $matches =
            (int) $existing->catalog_product_id
                === $catalogProductId
            && (int) $existing->inventory_location_id
                === $inventoryLocationId
            && $existing->condition === $condition
            && (int) $existing->authorized_by_user_id
                === (int) $authorizer->id
            && $existing->valid_from->equalTo($validFrom)
            && $existing->valid_until->equalTo($validUntil)
            && (int) $existing->max_concurrent_open_containers
                === $maxConcurrentOpenContainers
            && (
                $existing->max_new_openings === null
                    ? $maxNewOpenings === null
                    : (int) $existing->max_new_openings
                        === $maxNewOpenings
            )
            && (
                $existing->target_ready_open_count === null
                    ? $targetReadyOpenCount === null
                    : (int) $existing->target_ready_open_count
                        === $targetReadyOpenCount
            )
            && $storedIds === $exactContainerIds;

        if (! $matches) {
            throw new DomainException(
                'La clave de idempotencia ya existe con '
                .'otro contrato de autorización.'
            );
        }
    }
}
