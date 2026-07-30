<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CommerceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_operator_manage_commerce_but_viewer_cannot(): void
    {
        $admin = $this->user(UserRole::Admin);
        $operator = $this->user(UserRole::Operator);
        $viewer = $this->user(UserRole::Viewer);

        $this->assertTrue(
            Gate::forUser($admin)->allows('manage-commerce')
        );
        $this->assertTrue(
            Gate::forUser($operator)->allows('manage-commerce')
        );
        $this->assertFalse(
            Gate::forUser($viewer)->allows('manage-commerce')
        );
    }

    public function test_supplier_write_routes_require_manage_commerce_gate(): void
    {
        $routeNames = [
            'suppliers.create',
            'suppliers.store',
            'suppliers.edit',
            'suppliers.update',
            'suppliers.toggle-active',
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
                'can:manage-commerce',
                $route->gatherMiddleware(),
                "La ruta {$routeName} debe exigir manage-commerce."
            );
        }
    }

    public function test_verified_viewer_can_read_private_supplier_directory(): void
    {
        $supplier = $this->supplier();
        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee($supplier->party->name);

        $this->actingAs($viewer)
            ->get(route('suppliers.show', $supplier))
            ->assertOk()
            ->assertSee($supplier->party->email);
    }

    public function test_operator_and_admin_can_open_supplier_management(): void
    {
        $operator = $this->user(UserRole::Operator);
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($operator)
            ->get(route('suppliers.create'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('suppliers.create'))
            ->assertOk();
    }

    public function test_supplier_destroy_route_is_never_exposed(): void
    {
        $this->assertFalse(Route::has('suppliers.destroy'));
    }

    private function supplier(): Supplier
    {
        $party = BusinessParty::withoutEvents(
            fn () => BusinessParty::query()->create([
                'party_type' => 'organization',
                'name' => 'Proveedor Seguro',
                'tax_id' => '30-33333333-3',
                'email' => 'seguro@example.test',
            ])
        );

        return Supplier::withoutEvents(
            fn () => Supplier::query()->create([
                'business_party_id' => $party->id,
                'active' => true,
            ])
        )->load('party');
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
