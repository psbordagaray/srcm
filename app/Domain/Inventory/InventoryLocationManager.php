<?php

namespace App\Domain\Inventory;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\InventoryLocation;
use App\Models\Organization;
use DomainException;
use Illuminate\Support\Facades\DB;

class InventoryLocationManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): InventoryLocation
    {
        $organizationId = $this->currentOrganization->id();

        return DB::transaction(function () use (
            $data,
            $organizationId
        ): InventoryLocation {
            $this->lockOrganization($organizationId);

            return InventoryLocation::query()->create([
                'organization_id' => $organizationId,
                'parent_id' => $this->parentId($data),
                'name' => $data['name'],
                'type' => $data['type'],
                'active' => true,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(
        InventoryLocation $location,
        array $data
    ): InventoryLocation {
        $organizationId = $this->currentOrganization->id();

        $this->assertCurrentOrganization(
            $location,
            $organizationId
        );

        return DB::transaction(function () use (
            $location,
            $data,
            $organizationId
        ): InventoryLocation {
            $this->lockOrganization($organizationId);

            $locked = InventoryLocation::query()
                ->forOrganization($organizationId)
                ->whereKey($location->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->fill([
                'parent_id' => $this->parentId($data),
                'name' => $data['name'],
                'type' => $data['type'],
            ])->save();

            return $locked->fresh();
        });
    }

    public function toggleActive(
        InventoryLocation $location
    ): InventoryLocation {
        $organizationId = $this->currentOrganization->id();

        $this->assertCurrentOrganization(
            $location,
            $organizationId
        );

        return DB::transaction(function () use (
            $location,
            $organizationId
        ): InventoryLocation {
            $this->lockOrganization($organizationId);

            $locked = InventoryLocation::query()
                ->forOrganization($organizationId)
                ->whereKey($location->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->active = ! $locked->active;
            $locked->save();

            return $locked->fresh();
        });
    }

    private function assertCurrentOrganization(
        InventoryLocation $location,
        int $organizationId
    ): void {
        if (
            (int) $location->organization_id
                !== $organizationId
        ) {
            throw new DomainException(
                'La ubicación no pertenece a la organización activa.'
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parentId(array $data): ?int
    {
        $parentId = $data['parent_id'] ?? null;

        return filled($parentId)
            ? (int) $parentId
            : null;
    }

    private function lockOrganization(
        int $organizationId
    ): void {
        Organization::query()
            ->whereKey($organizationId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
