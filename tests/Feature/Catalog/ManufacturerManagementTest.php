<?php

namespace Tests\Feature\Catalog;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ManufacturerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_users_read_catalog_and_only_managers_see_actions(): void
    {
        $manufacturer = Manufacturer::withoutEvents(
            fn () => Manufacturer::query()->create([
                'name' => 'TP Vision',
                'website' => 'https://www.tpvision.com',
                'description' => 'Fabricante de electrónica.',
                'active' => true,
            ])
        );

        $viewerResponse = $this
            ->actingAs($this->user(UserRole::Viewer))
            ->get(route('manufacturers.index'))
            ->assertOk()
            ->assertSee($manufacturer->name)
            ->assertSee('Consulta')
            ->assertDontSee('Nuevo fabricante')
            ->assertDontSee('Editar')
            ->assertDontSee('Inactivar');

        $viewerResponse->assertSee(
            'Una marca no necesariamente fabrica',
            false
        );

        $this
            ->actingAs($this->user(UserRole::Operator))
            ->get(route('manufacturers.index'))
            ->assertOk()
            ->assertSee('Nuevo fabricante')
            ->assertSee('Editar')
            ->assertSee('Inactivar');
    }

    public function test_manager_creates_normalized_searchable_manufacturer(): void
    {
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->post(route('manufacturers.store'), [
                'name' => '  TP   Vision  ',
                'website' => 'tpvision.com',
                'description' =>
                    'Responsable técnico de determinadas líneas.',
                'active' => 1,
            ])
            ->assertRedirect(
                route('manufacturers.index')
            );

        $manufacturer = Manufacturer::query()->sole();

        $this->assertSame(
            'TP Vision',
            $manufacturer->name
        );

        $this->assertSame(
            'tp vision',
            $manufacturer->normalized_name
        );

        $this->assertSame(
            'tp-vision',
            $manufacturer->slug
        );

        $this->assertSame(
            'https://tpvision.com',
            $manufacturer->website
        );

        $this->actingAs($admin)
            ->get(route('manufacturers.index', [
                'search' => 'TP Vision',
            ]))
            ->assertOk()
            ->assertSee('TP Vision');
    }

    public function test_equivalent_duplicate_is_rejected_without_audit(): void
    {
        Manufacturer::withoutEvents(
            fn () => Manufacturer::query()->create([
                'name' => 'TP Vision',
                'active' => true,
            ])
        );

        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->post(route('manufacturers.store'), [
                'name' => 'tp-vision',
                'website' => null,
                'description' => null,
                'active' => 1,
            ])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount(
            'manufacturers',
            1
        );

        $this->assertDatabaseCount(
            'audit_logs',
            0
        );
    }

    public function test_manager_updates_and_toggles_with_audit(): void
    {
        $manufacturer = Manufacturer::withoutEvents(
            fn () => Manufacturer::query()->create([
                'name' => 'Original Factory',
                'active' => true,
            ])
        );

        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->put(
                route(
                    'manufacturers.update',
                    $manufacturer
                ),
                [
                    'name' => 'Updated Factory',
                    'website' => 'factory.example',
                    'description' => 'Actualizado.',
                    'active' => 1,
                ]
            )
            ->assertRedirect(
                route('manufacturers.index')
            );

        $this->actingAs($admin)
            ->patch(
                route(
                    'manufacturers.toggle-active',
                    $manufacturer
                )
            )
            ->assertRedirect(
                route('manufacturers.index')
            );

        $manufacturer->refresh();

        $this->assertSame(
            'Updated Factory',
            $manufacturer->name
        );

        $this->assertFalse($manufacturer->active);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Manufacturer::class,
            'auditable_id' => (string) $manufacturer->id,
            'event' => 'updated',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Manufacturer::class,
            'auditable_id' => (string) $manufacturer->id,
            'event' => 'deactivated',
        ]);

        $this->actingAs($admin)
            ->get(route('audit-logs.index', [
                'entity' => 'manufacturer',
            ]))
            ->assertOk()
            ->assertSee('Fabricante');
    }

    public function test_viewer_cannot_mutate_manufacturers(): void
    {
        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)
            ->get(route('manufacturers.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('manufacturers.store'), [
                'name' => 'Bloqueado',
                'active' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing(
            'manufacturers',
            ['name' => 'Bloqueado']
        );

        $this->assertDatabaseCount(
            'audit_logs',
            0
        );
    }

    public function test_routes_are_apb_and_destroy_is_not_exposed(): void
    {
        $this->assertTrue(
            Route::has('manufacturers.index')
        );

        $this->assertTrue(
            Route::has('manufacturers.toggle-active')
        );

        $this->assertFalse(
            Route::has('manufacturers.destroy')
        );

        $writeRoutes = [
            'manufacturers.create',
            'manufacturers.store',
            'manufacturers.show',
            'manufacturers.edit',
            'manufacturers.update',
            'manufacturers.toggle-active',
        ];

        foreach ($writeRoutes as $routeName) {
            $route = app('router')
                ->getRoutes()
                ->getByName($routeName);

            $this->assertNotNull($route);

            $this->assertContains(
                'can:manage-catalog',
                $route->gatherMiddleware()
            );
        }
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
