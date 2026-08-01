<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryAvailabilityReader;
use App\Domain\Inventory\InventoryLocationManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class InventoryAvailabilityFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_availability_and_deficit_are_derived_per_condition(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Viewer);
        $product = $this->product('Aceite a granel', 'AVAIL-OIL', 'l', 3);
        $location = $this->location($organization);

        $this->balance(
            $organization,
            $product,
            $location,
            InventoryCondition::New,
            '15.500000'
        );
        $this->balance(
            $organization,
            $product,
            $location,
            InventoryCondition::Damaged,
            '-2.250000'
        );

        $positions = app(InventoryAvailabilityReader::class)
            ->positions($actor);
        $new = $positions->firstWhere(
            'condition',
            InventoryCondition::New
        );
        $damaged = $positions->firstWhere(
            'condition',
            InventoryCondition::Damaged
        );

        $this->assertCount(2, $positions);
        $this->assertNotNull($new);
        $this->assertNotNull($damaged);
        $this->assertSame('15.500000', $new->physicalQuantity);
        $this->assertSame('15.500000', $new->availableQuantity);
        $this->assertSame('0.000000', $new->deficitQuantity);
        $this->assertFalse($new->hasDeficit());
        $this->assertSame('-2.250000', $damaged->physicalQuantity);
        $this->assertSame('0.000000', $damaged->availableQuantity);
        $this->assertSame('2.250000', $damaged->deficitQuantity);
        $this->assertTrue($damaged->hasDeficit());
        $this->assertSame('l', $damaged->baseUnitCode);
        $this->assertSame(3, $damaged->quantityScale);
    }

    public function test_reader_uses_the_actor_current_organization(): void
    {
        $first = $this->organization();
        $second = $this->newOrganization('Tenant disponibilidad ajeno');
        $actor = $this->user($first, UserRole::Operator);
        $product = $this->product('Cable', 'AVAIL-CABLE', 'm', 3);

        $this->balance(
            $first,
            $product,
            $this->location($first),
            InventoryCondition::New,
            '10.000000'
        );
        $this->balance(
            $second,
            $product,
            $this->newLocation($second, 'Depósito ajeno'),
            InventoryCondition::New,
            '999.000000'
        );

        $positions = app(InventoryAvailabilityReader::class)
            ->positions($actor);

        $this->assertCount(1, $positions);
        $this->assertSame($first->id, $positions->sole()->organizationId);
        $this->assertSame('10.000000', $positions->sole()->physicalQuantity);
    }

    public function test_all_active_members_may_view_availability(): void
    {
        $organization = $this->organization();

        foreach (UserRole::cases() as $role) {
            $user = $this->user($organization, $role);

            $this->assertTrue(
                Gate::forUser($user)->allows(
                    'view-inventory-availability'
                )
            );
        }
    }

    public function test_location_with_nonzero_physical_balance_cannot_be_inactivated(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Artículo ubicado', 'AVAIL-LOC');
        $location = InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->where('type', InventoryLocationType::Receiving->value)
            ->firstOrFail();

        $balance = $this->balance(
            $organization,
            $product,
            $location,
            InventoryCondition::New,
            '1.000000'
        );

        $this->actingAs($admin);

        try {
            app(InventoryLocationManager::class)->toggleActive($location);
            $this->fail('La ubicación con saldo debió permanecer activa.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($location->refresh()->active);

        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_locations')
                ->where('id', $location->id)
                ->update(['active' => false])
        );

        $balance->forceFill(['quantity' => '0.000000'])->saveQuietly();

        $updated = app(InventoryLocationManager::class)
            ->toggleActive($location->refresh());

        $this->assertFalse($updated->active);
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
                ['slug' => 'availability'],
                ['name' => 'Availability', 'active' => true]
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

    private function assertDatabaseRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('La base debió impedir inactivar la ubicación.');
    }
}
