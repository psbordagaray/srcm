<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CatalogAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_are_viewers_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertSame(UserRole::Viewer, $user->role);

        $this->assertFalse(
            Gate::forUser($user)->allows('manage-catalog')
        );
    }

    public function test_admin_and_operator_can_manage_catalog(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $this->assertTrue(
            Gate::forUser($admin)->allows('manage-catalog')
        );

        $this->assertTrue(
            Gate::forUser($operator)->allows('manage-catalog')
        );
    }

    public function test_catalog_write_routes_require_manage_catalog_gate(): void
    {
        $routeNames = [
            'product-categories.create',
            'product-categories.store',
            'product-categories.show',
            'product-categories.edit',
            'product-categories.update',
            'product-categories.toggle-active',
            'brands.create',
            'brands.store',
            'brands.show',
            'brands.edit',
            'brands.update',
            'brands.toggle-active',
            'technical-models.create',
            'technical-models.store',
            'technical-models.show',
            'technical-models.edit',
            'technical-models.update',
            'technical-models.toggle-active',
        ];

        foreach ($routeNames as $routeName) {
            $route = app('router')
                ->getRoutes()
                ->getByName($routeName);

            $this->assertNotNull(
                $route,
                "La ruta {$routeName} debe existir."
            );

            $this->assertContains(
                'can:manage-catalog',
                $route->gatherMiddleware(),
                "La ruta {$routeName} debe exigir manage-catalog."
            );
        }
    }

    public function test_unused_destroy_routes_are_not_exposed(): void
    {
        $this->assertFalse(Route::has('brands.destroy'));

        $this->assertFalse(
            Route::has('product-categories.destroy')
        );

        $this->assertFalse(
            Route::has('technical-models.destroy')
        );
    }

    public function test_viewer_can_read_catalog_and_knowledge(): void
    {
        $viewer = User::factory()->create([
            'role' => UserRole::Viewer,
        ]);

        $this->actingAs($viewer)
            ->get(route('brands.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('product-categories.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('technical-models.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('knowledge.explorer'))
            ->assertOk();
    }

    public function test_viewer_cannot_open_or_submit_catalog_management(): void
    {
        $viewer = User::factory()->create([
            'role' => UserRole::Viewer,
        ]);

        $this->actingAs($viewer)
            ->get(route('brands.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('brands.store'), [
                'name' => 'Blocked Brand',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('brands', [
            'name' => 'Blocked Brand',
        ]);
    }

    public function test_operator_and_admin_can_open_catalog_management(): void
    {
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($operator)
            ->get(route('brands.create'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('brands.create'))
            ->assertOk();
    }

    public function test_viewer_does_not_see_catalog_management_actions(): void
    {
        $viewer = User::factory()->create([
            'role' => UserRole::Viewer,
        ]);

        $response = $this->actingAs($viewer)
            ->get(route('brands.index'))
            ->assertOk();

        $response->assertDontSee(
            route('brands.create'),
            false
        );

        $response->assertDontSee('Editar');
        $response->assertDontSee('Inactivar');
        $response->assertSee('Consulta');
    }

    public function test_admin_sees_catalog_management_actions_and_role_label(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('brands.index'))
            ->assertOk();

        $response->assertSee(
            route('brands.create'),
            false
        );

        $response->assertSee('Administrador');
    }
}
