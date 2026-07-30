<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\SupplierOffer;
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

        $this->assertTrue(Gate::forUser($admin)->allows('manage-commerce'));
        $this->assertTrue(Gate::forUser($operator)->allows('manage-commerce'));
        $this->assertFalse(Gate::forUser($viewer)->allows('manage-commerce'));
    }

    public function test_commerce_write_routes_require_manage_commerce_gate(): void
    {
        $routeNames = [
            'suppliers.create',
            'suppliers.store',
            'suppliers.edit',
            'suppliers.update',
            'suppliers.toggle-active',
            'supplier-offers.create',
            'supplier-offers.store',
            'supplier-offers.edit',
            'supplier-offers.update',
            'supplier-offers.toggle-active',
        ];

        foreach ($routeNames as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "La ruta {$routeName} debe existir.");
            $this->assertContains(
                'can:manage-commerce',
                $route->gatherMiddleware(),
                "La ruta {$routeName} debe exigir manage-commerce."
            );
        }
    }

    public function test_verified_viewer_can_read_private_commerce(): void
    {
        [$supplier, $offer] = $this->offer();
        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee($supplier->party->name);

        $this->actingAs($viewer)
            ->get(route('supplier-offers.index'))
            ->assertOk()
            ->assertSee($offer->product->name);

        $this->actingAs($viewer)
            ->get(route('supplier-offers.show', $offer))
            ->assertOk()
            ->assertSee($offer->supplier_code);
    }

    public function test_operator_and_admin_can_open_commerce_management(): void
    {
        $operator = $this->user(UserRole::Operator);
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($operator)
            ->get(route('suppliers.create'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('supplier-offers.create'))
            ->assertOk();
    }

    public function test_commerce_destroy_routes_are_never_exposed(): void
    {
        $this->assertFalse(Route::has('suppliers.destroy'));
        $this->assertFalse(Route::has('supplier-offers.destroy'));
    }

    private function offer(): array
    {
        $party = BusinessParty::withoutEvents(
            fn () => BusinessParty::query()->create([
                'party_type' => 'organization',
                'name' => 'Proveedor Seguro',
                'tax_id' => '30-33333333-3',
                'email' => 'seguro@example.test',
            ])
        );

        $supplier = Supplier::withoutEvents(
            fn () => Supplier::query()->create([
                'business_party_id' => $party->id,
                'active' => true,
            ])
        )->load('party');

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->create([
                'name' => 'Controles',
                'slug' => 'controles',
                'active' => true,
            ])
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'SEG-001',
                'name' => 'Producto Seguro',
                'active' => true,
            ])
        );

        $offer = SupplierOffer::withoutEvents(
            fn () => SupplierOffer::query()->create([
                'supplier_id' => $supplier->id,
                'catalog_product_id' => $product->id,
                'supplier_code' => 'PROV-SEG-1',
                'availability_status' => 'available',
                'checked_at' => now()->toDateString(),
                'active' => true,
            ])
        )->load(['supplier.party', 'product']);

        return [$supplier, $offer];
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
