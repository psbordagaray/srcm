<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryLocationType;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\InventoryLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InventoryLocationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_read_and_only_admin_sees_management_actions(): void
    {
        $this->seed(InventoryLocationSeeder::class);

        $branch = InventoryLocation::query()
            ->roots()
            ->sole();
        $viewer = $this->user(UserRole::Viewer);
        $operator = $this->user(UserRole::Operator);
        $admin = $this->user(UserRole::Admin);

        foreach ([$viewer, $operator] as $user) {
            $this->actingAs($user)
                ->get(route('inventory-locations.index'))
                ->assertOk()
                ->assertSee('Sucursal principal')
                ->assertSee(
                    route('inventory-locations.index'),
                    false
                )
                ->assertDontSee(
                    route('inventory-locations.create'),
                    false
                )
                ->assertDontSee(
                    route('inventory-locations.edit', $branch),
                    false
                );
        }

        $this->actingAs($admin)
            ->get(route('inventory-locations.index'))
            ->assertOk()
            ->assertSee(
                route('inventory-locations.create'),
                false
            )
            ->assertSee(
                route('inventory-locations.edit', $branch),
                false
            );
    }

    public function test_admin_creates_normalized_scoped_and_audited_location(): void
    {
        $this->seed(InventoryLocationSeeder::class);

        $admin = $this->user(UserRole::Admin);
        $current = $this->defaultOrganization();
        $other = $this->organization('Organización maliciosa');
        $branch = InventoryLocation::query()
            ->forOrganization($current)
            ->roots()
            ->sole();

        $this->actingAs($admin)
            ->post(route('inventory-locations.store'), [
                'organization_id' => $other->id,
                'parent_id' => $branch->id,
                'name' => '  Estantería   Norte  ',
                'type' => InventoryLocationType::Shelf->value,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory-locations.index'));

        $location = InventoryLocation::query()
            ->where('normalized_name', 'estanterianorte')
            ->sole();

        $this->assertSame($current->id, $location->organization_id);
        $this->assertSame($branch->id, $location->parent_id);
        $this->assertSame('Estantería Norte', $location->name);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $current->id,
            'auditable_type' => InventoryLocation::class,
            'auditable_id' => $location->id,
            'event' => 'created',
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('inventory-locations.index', [
                'search' => 'estanteria norte',
                'type' => InventoryLocationType::Shelf->value,
                'status' => 'active',
            ]))
            ->assertOk()
            ->assertSee('Estantería Norte')
            ->assertDontSee('Depósito principal');
    }

    public function test_admin_updates_moves_and_inactivates_with_audit(): void
    {
        $this->seed(InventoryLocationSeeder::class);

        $admin = $this->user(UserRole::Admin);
        $organization = $this->defaultOrganization();
        $branch = InventoryLocation::query()
            ->forOrganization($organization)
            ->where('type', InventoryLocationType::Branch->value)
            ->sole();
        $receiving = InventoryLocation::query()
            ->forOrganization($organization)
            ->where('type', InventoryLocationType::Receiving->value)
            ->sole();

        $this->actingAs($admin)
            ->put(
                route('inventory-locations.update', $receiving),
                [
                    'parent_id' => $branch->id,
                    'name' => 'Recepción técnica',
                    'type' =>
                        InventoryLocationType::Receiving->value,
                ]
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inventory-locations.index'));

        $receiving->refresh();

        $this->assertSame($branch->id, $receiving->parent_id);
        $this->assertSame('Recepción técnica', $receiving->name);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => InventoryLocation::class,
            'auditable_id' => $receiving->id,
            'event' => 'updated',
        ]);

        $this->actingAs($admin)
            ->patch(
                route(
                    'inventory-locations.toggle-active',
                    $receiving
                )
            )
            ->assertRedirect(route('inventory-locations.index'));

        $this->assertFalse($receiving->fresh()->active);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => InventoryLocation::class,
            'auditable_id' => $receiving->id,
            'event' => 'deactivated',
        ]);
    }

    public function test_non_admin_cannot_mutate_locations(): void
    {
        $this->seed(InventoryLocationSeeder::class);

        $location = InventoryLocation::query()
            ->where('type', InventoryLocationType::Receiving->value)
            ->sole();

        foreach ([UserRole::Viewer, UserRole::Operator] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)
                ->get(route('inventory-locations.create'))
                ->assertForbidden();

            $this->actingAs($user)
                ->post(route('inventory-locations.store'), [
                    'parent_id' => null,
                    'name' => 'Ubicación bloqueada',
                    'type' => InventoryLocationType::Sector->value,
                ])
                ->assertForbidden();

            $this->actingAs($user)
                ->get(route('inventory-locations.edit', $location))
                ->assertForbidden();

            $this->actingAs($user)
                ->put(
                    route('inventory-locations.update', $location),
                    [
                        'parent_id' => null,
                        'name' => 'Alterada',
                        'type' =>
                            InventoryLocationType::Receiving->value,
                    ]
                )
                ->assertForbidden();

            $this->actingAs($user)
                ->patch(
                    route(
                        'inventory-locations.toggle-active',
                        $location
                    )
                )
                ->assertForbidden();
        }

        $this->assertSame('Recepción', $location->fresh()->name);
        $this->assertTrue($location->fresh()->active);
    }

    public function test_foreign_location_is_hidden_and_foreign_parent_is_rejected(): void
    {
        $this->seed(InventoryLocationSeeder::class);

        $admin = $this->user(UserRole::Admin);
        $current = $this->defaultOrganization();
        $other = $this->organization('Segundo tenant');

        $foreign = InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id' => $other->id,
                'parent_id' => null,
                'name' => 'Depósito secreto',
                'type' => InventoryLocationType::Warehouse,
                'active' => true,
            ])
        );

        $this->actingAs($admin)
            ->get(route('inventory-locations.index'))
            ->assertOk()
            ->assertDontSee('Depósito secreto');

        $this->actingAs($admin)
            ->get(route('inventory-locations.edit', $foreign))
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(
                route('inventory-locations.update', $foreign),
                [
                    'parent_id' => null,
                    'name' => 'Intento de alteración',
                    'type' =>
                        InventoryLocationType::Warehouse->value,
                ]
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->post(route('inventory-locations.store'), [
                'organization_id' => $other->id,
                'parent_id' => $foreign->id,
                'name' => 'Cruce bloqueado',
                'type' => InventoryLocationType::Shelf->value,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('inventory_locations', [
            'organization_id' => $current->id,
            'normalized_name' => 'crucebloqueado',
        ]);
    }

    public function test_edit_form_excludes_self_and_descendants_as_parents(): void
    {
        $this->seed(InventoryLocationSeeder::class);

        $admin = $this->user(UserRole::Admin);
        $locations = InventoryLocation::query()->get();
        $branch = $locations->firstWhere(
            'type',
            InventoryLocationType::Branch
        );

        $response = $this->actingAs($admin)
            ->get(route('inventory-locations.edit', $branch))
            ->assertOk();

        foreach ($locations as $location) {
            $response->assertDontSee(
                'value="'.$location->id.'"',
                false
            );
        }
    }

    public function test_routes_are_scoped_authorized_and_non_destructive(): void
    {
        $readRoute = app('router')
            ->getRoutes()
            ->getByName('inventory-locations.index');

        $this->assertNotNull($readRoute);
        $this->assertContains(
            RequireOrganization::class,
            $readRoute->gatherMiddleware()
        );
        $this->assertContains(
            'can:view-inventory',
            $readRoute->gatherMiddleware()
        );

        foreach ([
            'inventory-locations.create',
            'inventory-locations.store',
            'inventory-locations.edit',
            'inventory-locations.update',
            'inventory-locations.toggle-active',
        ] as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);

            $this->assertNotNull($route);
            $this->assertContains(
                RequireOrganization::class,
                $route->gatherMiddleware()
            );
            $this->assertContains(
                'can:manage-inventory-locations',
                $route->gatherMiddleware()
            );
        }

        $this->assertFalse(
            Route::has('inventory-locations.show')
        );
        $this->assertFalse(
            Route::has('inventory-locations.destroy')
        );
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

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
