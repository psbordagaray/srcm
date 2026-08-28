<?php

namespace App\Domain\Device;

use App\Models\OperationalDevice;
use App\Models\OperationalDeviceCapabilityGrant;
use App\Models\OperationalDeviceOperationClaim;
use App\Models\Organization;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class OperationalDeviceReplayGuard
{
    public function claim(
        Organization $organization,
        OperationalDevice $device,
        OperationalDeviceOperationData $data
    ): OperationalDeviceOperationResolution {
        if (
            (int) $device->organization_id
                !== (int) $organization->getKey()
        ) {
            throw new DomainException(
                'El dispositivo operativo no pertenece a la organización solicitada.'
            );
        }

        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $organization,
            $device,
            $normalized
        ): OperationalDeviceOperationResolution {
            $organizationId = (int) $organization->getKey();

            $locked = OperationalDevice::query()
                ->forOrganization($organizationId)
                ->whereKey($device->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->active) {
                throw new DomainException(
                    'El dispositivo operativo no está habilitado.'
                );
            }

            $hasCapability =
                OperationalDeviceCapabilityGrant::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'operational_device_id',
                        $locked->getKey()
                    )
                    ->where(
                        'capability',
                        $normalized['capability']
                    )
                    ->exists();

            if (! $hasCapability) {
                throw new DomainException(
                    'El dispositivo no posee la capacidad requerida.'
                );
            }

            $existing = OperationalDeviceOperationClaim::query()
                ->forOrganization($organizationId)
                ->where(
                    'operational_device_id',
                    $locked->getKey()
                )
                ->where(
                    'client_operation_id',
                    $normalized['client_operation_id']
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    $existing->capability->value
                        !== $normalized['capability']
                    || $existing->operation_type
                        !== $normalized['operation_type']
                    || $existing->request_fingerprint
                        !== $normalized['request_fingerprint']
                ) {
                    throw new DomainException(
                        'El identificador de operación ya fue utilizado con otro contenido.'
                    );
                }

                return new OperationalDeviceOperationResolution(
                    $existing,
                    true
                );
            }

            $claim = OperationalDeviceOperationClaim::query()
                ->create([
                    'organization_id' => $organizationId,
                    'operational_device_id' => $locked->getKey(),
                    'client_operation_id' =>
                        $normalized['client_operation_id'],
                    'capability' => $normalized['capability'],
                    'operation_type' => $normalized['operation_type'],
                    'request_fingerprint' =>
                        $normalized['request_fingerprint'],
                ]);

            return new OperationalDeviceOperationResolution(
                $claim,
                false
            );
        }, 3);
    }

    /**
     * @return array{
     *   client_operation_id:string,
     *   capability:string,
     *   operation_type:string,
     *   request_fingerprint:string
     * }
     */
    private function normalize(
        OperationalDeviceOperationData $data
    ): array {
        $clientOperationId = trim($data->clientOperationId);

        if (! Str::isUuid($clientOperationId)) {
            throw new DomainException(
                'El identificador de operación del dispositivo debe ser un UUID.'
            );
        }

        $operationType = Str::lower(trim($data->operationType));

        if (
            $operationType === ''
            || Str::length($operationType) > 100
            || preg_match(
                '/^[a-z0-9][a-z0-9._:-]*$/',
                $operationType
            ) !== 1
        ) {
            throw new DomainException(
                'El tipo de operación del dispositivo es inválido.'
            );
        }

        $payload = $this->canonicalize($data->payload);

        try {
            $fingerprint = hash(
                'sha256',
                json_encode(
                    [
                        'capability' => $data->capability->value,
                        'operation_type' => $operationType,
                        'payload' => $payload,
                    ],
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'El contenido de la operación no es serializable.',
                previous: $exception
            );
        }

        return [
            'client_operation_id' => $clientOperationId,
            'capability' => $data->capability->value,
            'operation_type' => $operationType,
            'request_fingerprint' => $fingerprint,
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

                continue;
            }

            if (
                ! is_null($item)
                && ! is_scalar($item)
            ) {
                throw new DomainException(
                    'El contenido de la operación sólo admite valores serializables.'
                );
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
