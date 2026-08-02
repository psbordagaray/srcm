<?php

namespace App\Domain\Service;

use App\Enums\ServiceCustodyEventType;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\OrganizationMembership;
use App\Models\ServiceAsset;
use App\Models\ServiceAssetIdentifier;
use App\Models\ServiceCustodyEvent;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderIntake;
use App\Models\ServiceOrderStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class ServiceOrderIntakeManager
{
    public function create(
        ServiceOrderIntakeData $data,
        User $actor
    ): ServiceOrder {
        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceOrder {
            $organizationId = (int) $actor->current_organization_id;

            if ($organizationId <= 0) {
                throw new DomainException(
                    'El usuario no posee una organización activa.'
                );
            }

            $organization = DB::table('organizations')
                ->where('id', $organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first(['id', 'name', 'slug']);

            if (! $organization) {
                throw new DomainException(
                    'La organización activa no está disponible.'
                );
            }

            $this->guardActor($organizationId, $actor);

            $existing = ServiceOrder::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    ($existing->metadata['_intake_fingerprint'] ?? null)
                        !== $normalized['fingerprint']
                ) {
                    throw new DomainException(
                        'La clave de idempotencia ya fue utilizada con otro ingreso.'
                    );
                }

                return $existing->load([
                    'asset.identifiers',
                    'intake',
                    'statusHistory',
                    'custodyEvents',
                ]);
            }

            $customer = $this->resolveParty(
                $organizationId,
                $data->customerBusinessPartyId,
                $normalized['customer_name'],
                'cliente'
            );
            $owner = $this->resolveParty(
                $organizationId,
                $data->ownerBusinessPartyId,
                $normalized['owner_name'],
                'propietario',
                required: false
            );

            $customerName = $customer?->name
                ?? $normalized['customer_name'];
            $ownerName = $owner?->name
                ?? $normalized['owner_name']
                ?? $customerName;

            if ($customerName === null) {
                throw new DomainException(
                    'El ingreso requiere identificar a quien entrega el activo.'
                );
            }

            $location = InventoryLocation::query()
                ->forOrganization($organizationId)
                ->whereKey($data->intakeLocationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $location) {
                throw new DomainException(
                    'La ubicación de ingreso no pertenece a la organización o está inactiva.'
                );
            }

            $asset = $this->resolveAsset(
                $organizationId,
                $actor,
                $normalized
            );
            $asset->load('identifiers');

            $receivedAt = CarbonImmutable::now();
            $orderNumber = ((int) ServiceOrder::query()
                ->forOrganization($organizationId)
                ->max('order_number')) + 1;
            $metadata = $normalized['metadata'];
            $metadata['_intake_fingerprint'] =
                $normalized['fingerprint'];

            $order = ServiceOrder::query()->create([
                'organization_id' => $organizationId,
                'order_number' => $orderNumber,
                'service_asset_id' => $asset->id,
                'customer_business_party_id' => $customer?->id,
                'owner_business_party_id' => $owner?->id,
                'intake_location_id' => $location->id,
                'status' => ServiceOrderStatus::Received,
                'created_by_user_id' => $actor->id,
                'received_at' => $receivedAt,
                'promised_at' => $normalized['promised_at'],
                'idempotency_key' => $normalized['idempotency_key'],
                'metadata' => $metadata,
            ]);

            $identifierSnapshot = $asset->identifiers
                ->map(fn (ServiceAssetIdentifier $identifier): array => [
                    'type' => $identifier->identifier_type->value,
                    'value' => $identifier->value,
                    'normalized_value' => $identifier->normalized_value,
                ])
                ->values()
                ->all();

            ServiceOrderIntake::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'asset_type_snapshot' => $asset->asset_type,
                'brand_name_snapshot' => $asset->brand_name,
                'model_name_snapshot' => $asset->model_name,
                'color_snapshot' => $normalized['color'],
                'identifiers_snapshot' => $identifierSnapshot,
                'customer_name_snapshot' => $customerName,
                'owner_name_snapshot' => $ownerName,
                'customer_reported_issue' =>
                    $normalized['customer_reported_issue'],
                'intake_observations' =>
                    $normalized['intake_observations'],
                'received_accessories' =>
                    $normalized['received_accessories'],
                'contact_available' =>
                    $normalized['contact_available'],
                'contact_reference' =>
                    $normalized['contact_reference'],
                'recorded_by_user_id' => $actor->id,
                'recorded_at' => $receivedAt,
            ]);

            ServiceOrderStatusHistory::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'from_status' => null,
                'to_status' => ServiceOrderStatus::Received,
                'changed_by_user_id' => $actor->id,
                'reason' => 'Ingreso del activo para servicio técnico.',
                'changed_at' => $receivedAt,
            ]);

            ServiceCustodyEvent::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'event_type' => ServiceCustodyEventType::Received,
                'from_holder_type' => $owner
                    ? 'registered_owner'
                    : ($normalized['owner_name'] !== null
                        ? 'declared_owner'
                        : 'customer'),
                'from_holder_reference' => $owner
                    ? 'business-party:'.$owner->id
                    : ($normalized['owner_name'] === null && $customer
                        ? 'business-party:'.$customer->id
                        : null),
                'from_holder_name' => $ownerName,
                'to_holder_type' => 'organization',
                'to_holder_reference' => (string) $organization->slug,
                'to_holder_name' => (string) $organization->name,
                'location_id' => $location->id,
                'condition_notes' =>
                    $normalized['intake_observations'],
                'accessories_snapshot' =>
                    $normalized['received_accessories'],
                'recorded_by_user_id' => $actor->id,
                'occurred_at' => $receivedAt,
            ]);

            return $order->load([
                'asset.identifiers',
                'intake',
                'statusHistory',
                'custodyEvents',
            ]);
        }, 3);
    }

    private function guardActor(int $organizationId, User $actor): void
    {
        if (! $actor->exists || $actor->trashed()) {
            throw new DomainException(
                'El usuario responsable no está activo.'
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
            || ! $membership->role->canCreateServiceOrders()
        ) {
            throw new DomainException(
                'El rol del usuario no puede recibir activos para servicio.'
            );
        }
    }

    private function resolveParty(
        int $organizationId,
        ?int $partyId,
        ?string $fallbackName,
        string $role,
        bool $required = true
    ): ?BusinessParty {
        if ($partyId === null) {
            if ($required && $fallbackName === null) {
                throw new DomainException(
                    "El ingreso requiere identificar al {$role}."
                );
            }

            return null;
        }

        $party = BusinessParty::query()
            ->forOrganization($organizationId)
            ->whereKey($partyId)
            ->lockForUpdate()
            ->first();

        if (! $party) {
            throw new DomainException(
                "El {$role} no pertenece a la organización activa."
            );
        }

        return $party;
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function resolveAsset(
        int $organizationId,
        User $actor,
        array $normalized
    ): ServiceAsset {
        $assetIds = [];

        foreach ($normalized['identifiers'] as $identifier) {
            $existing = ServiceAssetIdentifier::query()
                ->forOrganization($organizationId)
                ->where('identifier_type', $identifier['type'])
                ->where('normalized_value', $identifier['normalized_value'])
                ->lockForUpdate()
                ->first(['id', 'service_asset_id']);

            if ($existing) {
                $assetIds[(int) $existing->service_asset_id] = true;
            }
        }

        if (count($assetIds) > 1) {
            throw new DomainException(
                'Los identificadores ingresados pertenecen a activos diferentes.'
            );
        }

        $assetId = array_key_first($assetIds);

        if ($assetId === null) {
            $asset = ServiceAsset::query()->create([
                'organization_id' => $organizationId,
                'asset_type' => $normalized['asset_type'],
                'brand_name' => $normalized['brand_name'],
                'model_name' => $normalized['model_name'],
                'color' => $normalized['color'],
                'created_by_user_id' => $actor->id,
            ]);
        } else {
            $asset = ServiceAsset::query()
                ->forOrganization($organizationId)
                ->whereKey($assetId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $asset->asset_type->value !== $normalized['asset_type']
                || $asset->normalized_brand_name
                    !== $normalized['normalized_brand_name']
                || $asset->normalized_model_name
                    !== $normalized['normalized_model_name']
            ) {
                throw new DomainException(
                    'El tipo, marca o modelo no coincide con el activo identificado.'
                );
            }
        }

        foreach ($normalized['identifiers'] as $identifier) {
            $exists = ServiceAssetIdentifier::query()
                ->forOrganization($organizationId)
                ->where('service_asset_id', $asset->id)
                ->where('identifier_type', $identifier['type'])
                ->where('normalized_value', $identifier['normalized_value'])
                ->exists();

            if ($exists) {
                continue;
            }

            ServiceAssetIdentifier::query()->create([
                'organization_id' => $organizationId,
                'service_asset_id' => $asset->id,
                'identifier_type' => $identifier['type'],
                'value' => $identifier['value'],
                'created_by_user_id' => $actor->id,
            ]);
        }

        return $asset;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(ServiceOrderIntakeData $data): array
    {
        $brandName = Str::of($data->brandName)->squish()->toString();
        $modelName = Str::of($data->modelName)->squish()->toString();
        $customerName = $this->optional($data->customerName);
        $ownerName = $this->optional($data->ownerName);
        $color = $this->optional($data->color);
        $reportedIssue = Str::of($data->customerReportedIssue)
            ->squish()
            ->toString();
        $observations = $this->optionalMultiline($data->intakeObservations);
        $accessories = $this->optionalMultiline($data->receivedAccessories);
        $contactReference = $this->optional($data->contactReference);
        $idempotencyKey = trim($data->idempotencyKey);

        if (
            $brandName === ''
            || $modelName === ''
            || Str::length($brandName) > 255
            || Str::length($modelName) > 255
        ) {
            throw new DomainException(
                'El activo requiere marca y modelo válidos.'
            );
        }

        if (
            $reportedIssue === ''
            || Str::length($reportedIssue) > 5000
        ) {
            throw new DomainException(
                'El ingreso requiere una falla declarada válida.'
            );
        }

        foreach ([
            $customerName,
            $ownerName,
            $color,
            $contactReference,
        ] as $shortValue) {
            if ($shortValue !== null && Str::length($shortValue) > 255) {
                throw new DomainException(
                    'Un dato breve del ingreso excede la longitud permitida.'
                );
            }
        }

        foreach ([$observations, $accessories] as $longValue) {
            if ($longValue !== null && Str::length($longValue) > 10000) {
                throw new DomainException(
                    'Una observación del ingreso excede la longitud permitida.'
                );
            }
        }

        if (
            $idempotencyKey === ''
            || Str::length($idempotencyKey) > 100
        ) {
            throw new DomainException(
                'La clave de idempotencia del ingreso es inválida.'
            );
        }

        if ($data->intakeLocationId <= 0) {
            throw new DomainException(
                'La ubicación de ingreso es inválida.'
            );
        }

        if ($data->contactAvailable && $contactReference === null) {
            throw new DomainException(
                'Debe indicarse cómo contactar al cliente.'
            );
        }

        if (! $data->contactAvailable && $contactReference !== null) {
            throw new DomainException(
                'El medio de contacto contradice la falta de contacto declarada.'
            );
        }

        $promisedAt = $data->promisedAt
            ? CarbonImmutable::instance($data->promisedAt)
            : null;

        if ($promisedAt && $promisedAt->lessThan(CarbonImmutable::now())) {
            throw new DomainException(
                'La fecha prometida no puede estar en el pasado.'
            );
        }

        $identifiers = $this->normalizeIdentifiers($data->identifiers);
        $metadata = $this->canonicalize($data->metadata);
        unset($metadata['_intake_fingerprint']);

        $normalized = [
            'asset_type' => $data->assetType->value,
            'brand_name' => $brandName,
            'normalized_brand_name' =>
                ServiceAsset::normalizeName($brandName),
            'model_name' => $modelName,
            'normalized_model_name' =>
                ServiceAsset::normalizeName($modelName),
            'identifiers' => $identifiers,
            'intake_location_id' => $data->intakeLocationId,
            'customer_business_party_id' =>
                $data->customerBusinessPartyId,
            'customer_name' => $customerName,
            'owner_business_party_id' =>
                $data->ownerBusinessPartyId,
            'owner_name' => $ownerName,
            'color' => $color,
            'customer_reported_issue' => $reportedIssue,
            'intake_observations' => $observations,
            'received_accessories' => $accessories,
            'contact_available' => $data->contactAvailable,
            'contact_reference' => $contactReference,
            'promised_at' => $promisedAt?->utc()
                ->format('Y-m-d\TH:i:s.u\Z'),
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata,
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
                'Los metadatos del ingreso no son serializables.',
                previous: $exception
            );
        }

        return $normalized;
    }

    /**
     * @param list<ServiceAssetIdentifierData> $identifiers
     * @return list<array{type: string, value: string, normalized_value: string}>
     */
    private function normalizeIdentifiers(array $identifiers): array
    {
        if (count($identifiers) > 10) {
            throw new DomainException(
                'El activo posee demasiados identificadores simultáneos.'
            );
        }

        $normalized = [];
        $seen = [];

        foreach ($identifiers as $identifier) {
            if (! $identifier instanceof ServiceAssetIdentifierData) {
                throw new DomainException(
                    'La lista de identificadores técnicos es inválida.'
                );
            }

            $value = Str::of($identifier->value)->squish()->toString();
            $normalizedValue = $identifier->type->normalize($value);

            if (
                $normalizedValue === ''
                || Str::length($value) > 255
                || Str::length($normalizedValue) > 255
            ) {
                throw new DomainException(
                    'Un identificador técnico es inválido.'
                );
            }

            if (
                $identifier->type === ServiceIdentifierType::Imei
                && ! preg_match('/^\d{14,16}$/', $normalizedValue)
            ) {
                throw new DomainException(
                    'El IMEI debe contener entre 14 y 16 dígitos.'
                );
            }

            $key = $identifier->type->value.':'.$normalizedValue;

            if (isset($seen[$key])) {
                throw new DomainException(
                    'El mismo identificador fue informado más de una vez.'
                );
            }

            $seen[$key] = true;
            $normalized[] = [
                'type' => $identifier->type->value,
                'value' => $value,
                'normalized_value' => $normalizedValue,
            ];
        }

        usort(
            $normalized,
            fn (array $first, array $second): int =>
                [$first['type'], $first['normalized_value']]
                <=> [$second['type'], $second['normalized_value']]
        );

        return $normalized;
    }

    private function optional(?string $value): ?string
    {
        $value = Str::of((string) $value)->squish()->toString();

        return $value === '' ? null : $value;
    }

    private function optionalMultiline(?string $value): ?string
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

            if (! is_null($item) && ! is_scalar($item)) {
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
