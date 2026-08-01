<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryAvailabilityHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_active_members_may_open_the_scoped_read_only_screen(): void
    {
        $organization = $this->organization();

        foreach (UserRole::cases() as $role) {
            $user = $this->user($organization, $role);

            $this->actingAs($user)
                ->get(route('inventory-availability.index'))
                ->assertOk()
                ->assertSee('Disponibilidad')
                ->assertSee(
                    route('inventory-availability.index'),
                    false
                );
        }

        $route = app('router')
            ->getRoutes()
            ->getByName('inventory-availability.index');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains(
            RequireOrganization::class,
            $route->gatherMiddleware()
        );
        $this->assertContains(
            'can:view-inventory-availability',
            $route->gatherMiddleware()
        );
    }

    public function test_screen_shows_exact_quantities_without_foreign_tenant_data(): void
    {
        $organization = $this->organization();
        $other = $this->newOrganization('Tenant disponibilidad secreto');
        $viewer = $this->user($organization, UserRole::Viewer);
        $product = $this->product(
            'Aceite a granel',
            'UI-AVAIL-OIL',
            'l',
            3
        );

        $this->balance(
            $organization,
            $product,
            $this->location($organization),
            InventoryCondition::New,
            '15.500000'
        );
        $this->balance(
            $organization,
            $product,
            $this->location($organization),
            InventoryCondition::Damaged,
            '-2.250000'
        );
        $this->balance(
            $other,
            $product,
            $this->newLocation($other, 'Depósito secreto'),
            InventoryCondition::New,
            '999.000000'
        );

        $this->actingAs($viewer)
            ->get(route('inventory-availability.index'))
            ->assertOk()
            ->assertSee('Aceite a granel')
            ->assertSee('UI-AVAIL-OIL')
            ->assertSee('15,500')
            ->assertSee('-2,250')
            ->assertSee('2,250')
            ->assertSee('Dañado o para reparar')
            ->assertSee('Litro')
            ->assertDontSee('Depósito secreto')
            ->assertDontSee('999,000');
    }

    public function test_filters_combine_without_crossing_dimensions(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $firstLocation = $this->location($organization);
        $secondLocation = $this->newLocation(
            $organization,
            'Sector fraccionados'
        );
        $oil = $this->product(
            'Aceite técnico',
            'UI-FILTER-OIL',
            'l',
            3
        );
        $cable = $this->product(
            'Cable visible',
            'UI-FILTER-CABLE',
            'm',
            3
        );

        $this->balance(
            $organization,
            $oil,
            $secondLocation,
            InventoryCondition::Damaged,
            '-1.125000'
        );
        $this->balance(
            $organization,
            $oil,
            $firstLocation,
            InventoryCondition::New,
            '8.000000'
        );
        $this->balance(
            $organization,
            $cable,
            $secondLocation,
            InventoryCondition::Damaged,
            '-9.000000'
        );

        $this->actingAs($operator)
            ->get(route('inventory-availability.index', [
                'search' => 'aceite',
                'location' => $secondLocation->id,
                'condition' => InventoryCondition::Damaged->value,
                'status' => 'deficit',
            ]))
            ->assertOk()
            ->assertSee('Aceite técnico')
            ->assertSee('-1,125')
            ->assertDontSee('Cable visible')
            ->assertDontSee('8,000');
    }

    public function test_inactive_and_invalid_filters_fail_closed_to_known_options(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product(
            'Producto archivado',
            'UI-INACTIVE'
        );
        $location = $this->location($organization);

        $this->balance(
            $organization,
            $product,
            $location,
            InventoryCondition::New,
            '4.000000'
        );

        $product->forceFill(['active' => false])->saveQuietly();

        $this->actingAs($admin)
            ->get(route('inventory-availability.index', [
                'status' => 'inactive',
            ]))
            ->assertOk()
            ->assertSee('Producto archivado')
            ->assertSee('Producto inactivo');

        $this->actingAs($admin)
            ->get(route('inventory-availability.index', [
                'location' => '../../otro-tenant',
                'condition' => 'inventada',
                'status' => 'inyeccion',
            ]))
            ->assertOk()
            ->assertSee('Producto archivado')
            ->assertDontSee('value="inventada" selected', false)
            ->assertDontSee('value="inyeccion" selected', false);
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function newOrganization(string $name): Organization
    {
        return Organization::withoutEvents(
            fn () => Organization::query()->create([
                'name' => $name,
                'slug' => Str::slug($name),
                'active' => true,
            ])
        );
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role,
                    'active' => true,
                ]
            )
        );

        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        return $user->refresh();
    }

    private function location(
        Organization $organization
    ): InventoryLocation {
        return InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function newLocation(
        Organization $organization,
        string $name
    ): InventoryLocation {
        return InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'type' => InventoryLocationType::Warehouse,
                'active' => true,
            ])
        );
    }

    private function product(
        string $name,
        string $sku,
        string $baseUnit = 'unit',
        int $scale = 0
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'availability-http'],
                ['name' => 'Availability HTTP', 'active' => true]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'base_unit_code' => $baseUnit,
                'quantity_scale' => $scale,
                'active' => true,
            ])->refresh()
        );
    }

    private function balance(
        Organization $organization,
        CatalogProduct $product,
        InventoryLocation $location,
        InventoryCondition $condition,
        string $quantity
    ): InventoryBalance {
        return InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => $condition,
            'quantity' => $quantity,
            'base_unit_code' => $product->base_unit_code,
            'version' => 1,
        ]);
    }
}
