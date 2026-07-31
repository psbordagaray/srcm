<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryLocationManager;
use App\Enums\InventoryLocationType;
use App\Enums\UserRole;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\InventoryLocationSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Tests\TestCase;

class InventoryLocationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sulu_tv_location_seeder_is_idempotent(): void
    {
        $this->seed(InventoryLocationSeeder::class);
        $this->seed(InventoryLocationSeeder::class);

        $organization = $this->defaultOrganization();

        $this->assertDatabaseCount('inventory_locations', 3);

        $branch = InventoryLocation::query()
            ->forOrganization($organization)
            ->roots()
            ->sole();

        $warehouse = InventoryLocation::query()
            ->forOrganization($organization)
            ->where('parent_id', $branch->id)
            ->sole();

        $receiving = InventoryLocation::query()
            ->forOrganization($organization)
            ->where('parent_id', $warehouse->id)
            ->sole();

        $this->assertSame('Sucursal principal', $branch->name);
        $this->assertSame(
            InventoryLocationType::Branch,
            $branch->type
        );
        $this->assertSame('Depósito principal', $warehouse->name);
        $this->assertSame(
            InventoryLocationType::Warehouse,
            $warehouse->type
        );
        $this->assertSame('Recepción', $receiving->name);
        $this->assertSame(
            InventoryLocationType::Receiving,
            $receiving->type
        );
        $this->assertTrue($branch->active);
        $this->assertTrue($warehouse->active);
        $this->assertTrue($receiving->active);
    }

    public function test_manager_assigns_server_tenant_and_rejects_foreign_parent(): void
    {
        $admin = $this->admin();
        $current = $this->defaultOrganization();
        $other = $this->organization('Otra organización');

        $foreignParent = InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id' => $other->id,
                'parent_id' => null,
                'name' => 'Depósito ajeno',
                'type' => InventoryLocationType::Warehouse,
                'active' => true,
            ])
        );

        $this->actingAs($admin);

        $manager = app(InventoryLocationManager::class);

        $location = $manager->create([
            'organization_id' => $other->id,
            'parent_id' => null,
            'name' => 'Estantería segura',
            'type' => InventoryLocationType::Shelf,
        ]);

        $this->assertSame(
            $current->id,
            $location->organization_id
        );

        $this->assertDomainRejected(
            fn () => $manager->create([
                'organization_id' => $other->id,
                'parent_id' => $foreignParent->id,
                'name' => 'Posición maliciosa',
                'type' => InventoryLocationType::Position,
            ]),
            'no pertenece a la organización activa'
        );

        $this->assertDatabaseMissing('inventory_locations', [
            'organization_id' => $current->id,
            'normalized_name' => 'posicionmaliciosa',
        ]);
    }

    public function test_database_rejects_cross_organization_parent(): void
    {
        $this->seed(InventoryLocationSeeder::class);

        $current = $this->defaultOrganization();
        $other = $this->organization('Tenant de ataque');

        $parent = InventoryLocation::query()
            ->forOrganization($current)
            ->roots()
            ->sole();

        $rejected = false;

        try {
            DB::table('inventory_locations')->insert([
                'organization_id' => $other->id,
                'parent_id' => $parent->id,
                'name' => 'Cruce directo',
                'normalized_name' => 'crucedirecto',
                'type' => InventoryLocationType::Sector->value,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'La base debe rechazar padres de otra organización.'
        );
    }

    public function test_hierarchy_guards_cycles_duplicates_and_active_ancestry(): void
    {
        $this->seed(InventoryLocationSeeder::class);

        $admin = $this->admin();
        $this->actingAs($admin);

        $locations = InventoryLocation::query()
            ->forOrganization($this->defaultOrganization())
            ->get()
            ->keyBy(fn (InventoryLocation $location) =>
                $location->type->value);

        $branch = $locations[InventoryLocationType::Branch->value];
        $warehouse =
            $locations[InventoryLocationType::Warehouse->value];
        $receiving =
            $locations[InventoryLocationType::Receiving->value];

        $manager = app(InventoryLocationManager::class);

        $this->assertDomainRejected(
            fn () => $manager->update($branch, [
                'parent_id' => $receiving->id,
                'name' => $branch->name,
                'type' => $branch->type,
            ]),
            'no puede depender de sí misma'
        );

        $this->assertDomainRejected(
            fn () => $manager->create([
                'parent_id' => $branch->id,
                'name' => 'deposito-principal',
                'type' => InventoryLocationType::Warehouse,
            ]),
            'ubicación activa equivalente'
        );

        $this->assertDomainRejected(
            fn () => $manager->toggleActive($warehouse),
            'descendientes activos'
        );

        $receiving = $manager->toggleActive($receiving);
        $this->assertFalse($receiving->active);

        $warehouse = $manager->toggleActive($warehouse);
        $this->assertFalse($warehouse->active);

        $this->assertDomainRejected(
            fn () => $manager->toggleActive($receiving),
            'ubicación inactiva'
        );

        $this->assertDomainRejected(
            fn () => $manager->create([
                'parent_id' => $warehouse->id,
                'name' => 'Estantería inválida',
                'type' => InventoryLocationType::Shelf,
            ]),
            'ubicación inactiva'
        );
    }

    public function test_organization_is_immutable_and_physical_delete_is_rejected(): void
    {
        $this->seed(InventoryLocationSeeder::class);

        $location = InventoryLocation::query()
            ->roots()
            ->sole();
        $other = $this->organization('Organización destino');

        $location->organization_id = $other->id;

        $this->assertDomainRejected(
            fn () => $location->save(),
            'organización de una ubicación no puede cambiarse'
        );

        $location->refresh();

        try {
            $location->delete();
            $this->fail(
                'Una ubicación no debe poder eliminarse físicamente.'
            );
        } catch (LogicException $exception) {
            $this->assertStringContainsString(
                'no pueden eliminarse físicamente',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('inventory_locations', [
            'id' => $location->id,
        ]);
    }

    public function test_members_view_but_only_admin_manages_locations(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'email_verified_at' => now(),
            ]);

            $this->assertTrue(
                Gate::forUser($user)->allows('view-inventory')
            );

            $this->assertSame(
                $role === UserRole::Admin,
                Gate::forUser($user)->allows(
                    'manage-inventory-locations'
                )
            );
        }
    }

    public function test_mutations_are_audited_and_seeder_is_silent(): void
    {
        $this->seed(InventoryLocationSeeder::class);

        $organization = $this->defaultOrganization();

        $this->assertDatabaseMissing('audit_logs', [
            'auditable_type' => InventoryLocation::class,
        ]);

        $admin = $this->admin();
        $this->actingAs($admin);

        $manager = app(InventoryLocationManager::class);

        $location = $manager->create([
            'parent_id' => null,
            'name' => 'Sector transitorio',
            'type' => InventoryLocationType::Sector,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'auditable_type' => InventoryLocation::class,
            'auditable_id' => $location->id,
            'event' => 'created',
            'user_id' => $admin->id,
        ]);

        $branch = InventoryLocation::query()
            ->forOrganization($organization)
            ->roots()
            ->where('id', '!=', $location->id)
            ->sole();

        $location = $manager->update($location, [
            'parent_id' => $branch->id,
            'name' => 'Sector temporal',
            'type' => InventoryLocationType::Sector,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'auditable_type' => InventoryLocation::class,
            'auditable_id' => $location->id,
            'event' => 'updated',
        ]);

        $manager->toggleActive($location);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'auditable_type' => InventoryLocation::class,
            'auditable_id' => $location->id,
            'event' => 'deactivated',
        ]);
    }

    private function defaultOrganization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function organization(string $name): Organization
    {
        return Organization::withoutEvents(
            fn () => Organization::query()->create([
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
                'active' => true,
            ])
        );
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);
    }

    private function assertDomainRejected(
        callable $operation,
        string $expectedMessage
    ): void {
        try {
            $operation();
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                $expectedMessage,
                $exception->getMessage()
            );

            return;
        }

        $this->fail('La operación de dominio debía ser rechazada.');
    }
}
