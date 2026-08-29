<?php

namespace App\Domain\Device;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\OperationalDeviceCapability;
use App\Enums\UserRole;
use App\Models\OperationalDevice;
use App\Models\OperationalDeviceCapabilityGrant;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OperationalDeviceRegistry
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $auditRecorder,
    ) {
    }

    /**
     * @param array<int, OperationalDeviceCapability> $capabilities
     */
    public function register(
        User $actor,
        string $label,
        array $capabilities
    ): OperationalDevice {
        $organization = $this->currentOrganization->get($actor);

        $this->assertAdmin($actor);
        $label = $this->normalizeLabel($label);
        $capabilities = $this->normalizeCapabilities($capabilities);

        return DB::transaction(function () use (
            $organization,
            $label,
            $capabilities
        ): OperationalDevice {
            $this->lockActiveOrganization((int) $organization->getKey());

            $device = OperationalDevice::query()->create([
                'organization_id' => $organization->getKey(),
                'public_id' => Str::uuid()->toString(),
                'label' => $label,
                'active' => true,
            ]);

            foreach ($capabilities as $capability) {
                OperationalDeviceCapabilityGrant::query()->create([
                    'organization_id' => $organization->getKey(),
                    'operational_device_id' => $device->getKey(),
                    'capability' => $capability,
                ]);
            }

            $this->auditRecorder->record(
                $device,
                'registered',
                null,
                [
                    'public_id' => $device->public_id,
                    'label' => $device->label,
                    'active' => true,
                    'capabilities' => array_map(
                        fn (OperationalDeviceCapability $capability): string =>
                            $capability->value,
                        $capabilities
                    ),
                ]
            );

            return $device->load('capabilityGrants');
        }, 3);
    }

    public function deactivate(
        User $actor,
        OperationalDevice $device
    ): OperationalDevice {
        $organization = $this->currentOrganization->get($actor);

        $this->assertAdmin($actor);
        $this->assertOrganization($device, $organization);

        return DB::transaction(function () use (
            $organization,
            $device
        ): OperationalDevice {
            $this->lockActiveOrganization((int) $organization->getKey());

            $locked = OperationalDevice::query()
                ->forOrganization($organization)
                ->whereKey($device->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->active) {
                return $locked;
            }

            $locked->active = false;
            $locked->save();

            $this->auditRecorder->record(
                $locked,
                'deactivated',
                ['active' => true],
                ['active' => false]
            );

            return $locked->fresh();
        }, 3);
    }

    private function assertAdmin(User $actor): void
    {
        if (
            $this->currentOrganization->roleFor($actor)
                !== UserRole::Admin
        ) {
            throw new DomainException(
                'Sólo un administrador puede gestionar dispositivos operativos.'
            );
        }
    }

    private function assertOrganization(
        OperationalDevice $device,
        Organization $organization
    ): void {
        if (
            (int) $device->organization_id
                !== (int) $organization->getKey()
        ) {
            throw new DomainException(
                'El dispositivo operativo no pertenece a la organización activa.'
            );
        }
    }

    private function normalizeLabel(string $label): string
    {
        $label = Str::of($label)->squish()->toString();

        if (
            $label === ''
            || Str::length($label) > 120
        ) {
            throw new DomainException(
                'La etiqueta del dispositivo operativo es inválida.'
            );
        }

        return $label;
    }

    /**
     * @param array<int, OperationalDeviceCapability> $capabilities
     * @return array<int, OperationalDeviceCapability>
     */
    private function normalizeCapabilities(array $capabilities): array
    {
        $normalized = [];

        foreach ($capabilities as $capability) {
            if (! $capability instanceof OperationalDeviceCapability) {
                throw new DomainException(
                    'La declaración de capacidades del dispositivo es inválida.'
                );
            }

            $normalized[$capability->value] = $capability;
        }

        ksort($normalized, SORT_STRING);

        return array_values($normalized);
    }

    private function lockActiveOrganization(int $organizationId): void
    {
        $organization = Organization::query()
            ->whereKey($organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $organization) {
            throw new DomainException(
                'La organización no está activa.'
            );
        }
    }
}
