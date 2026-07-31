<?php

namespace Database\Seeders;

use App\Enums\InventoryLocationType;
use App\Models\InventoryLocation;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryLocationSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->first();

        if (! $organization) {
            throw new RuntimeException(
                'No existe la organización SULU TV para crear ubicaciones.'
            );
        }

        DB::transaction(function () use ($organization): void {
            Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            InventoryLocation::withoutEvents(function () use (
                $organization
            ): void {
                $branch = $this->location(
                    (int) $organization->getKey(),
                    null,
                    'Sucursal principal',
                    InventoryLocationType::Branch
                );

                $warehouse = $this->location(
                    (int) $organization->getKey(),
                    (int) $branch->getKey(),
                    'Depósito principal',
                    InventoryLocationType::Warehouse
                );

                $this->location(
                    (int) $organization->getKey(),
                    (int) $warehouse->getKey(),
                    'Recepción',
                    InventoryLocationType::Receiving
                );
            });
        });
    }

    private function location(
        int $organizationId,
        ?int $parentId,
        string $name,
        InventoryLocationType $type
    ): InventoryLocation {
        return InventoryLocation::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'parent_id' => $parentId,
                'normalized_name' =>
                    InventoryLocation::normalizeName($name),
            ],
            [
                'name' => $name,
                'type' => $type,
                'active' => true,
            ]
        );
    }
}
