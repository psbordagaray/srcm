<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommercialAvailabilityReader;
use App\Domain\Commerce\CommerceSalePolicyGuard;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercialAvailabilityFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_active_position_uses_physical_availability_as_v1_commercial_baseline(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization);
        $product = $this->product(
            'Aceite comercial',
            'COMM-AVAIL-OIL',
            'l',
            3
        );
        $location = $this->location(
            $organization,
            'Deposito comercial'
        );

        $this->balance(
            $organization,
            $product,
            $location,
            '12.500000'
        );

        $position = app(CommercialAvailabilityReader::class)
            ->positions($actor)
            ->sole();

        $this->assertSame('12.500000', $position->physicalQuantity);
        $this->assertSame(
            '12.500000',
            $position->physicalAvailableQuantity
        );
        $this->assertSame(
            '12.500000',
            $position->commercialAvailableQuantity
        );
        $this->assertSame([], $position->restrictionReasons);
        $this->assertTrue($position->isPromiseable());
        $this->assertFalse($position->hasCommercialRestriction());
        $this->assertSame('l', $position->baseUnitCode);
        $this->assertSame(3, $position->quantityScale);
        $this->assertSame(1, $position->balanceVersion);
    }

    public function test_negative_physical_position_never_becomes_commercially_available(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization);
        $product = $this->product(
            'Saldo negativo',
            'COMM-AVAIL-NEG'
        );
        $location = $this->location(
            $organization,
            'Deposito negativo'
        );

        $this->balance(
            $organization,
            $product,
            $location,
            '-2.000000'
        );

        $position = app(CommercialAvailabilityReader::class)
            ->positions($actor)
            ->sole();

        $this->assertSame('-2.000000', $position->physicalQuantity);
        $this->assertSame(
            '0.000000',
            $position->physicalAvailableQuantity
        );
        $this->assertSame(
            '0.000000',
            $position->commercialAvailableQuantity
        );
        $this->assertSame([], $position->restrictionReasons);
        $this->assertFalse($position->isPromiseable());
    }

    public function test_inactive_product_preserves_physical_truth_but_is_not_commercially_promiseable(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization);
        $product = $this->product(
            'Producto inactivo',
            'COMM-AVAIL-INACTIVE',
            'unit',
            0,
            false
        );
        $location = $this->location(
            $organization,
            'Deposito inactivo'
        );

        $this->balance(
            $organization,
            $product,
            $location,
            '3.000000'
        );

        $reader = app(CommercialAvailabilityReader::class);
        $position = $reader->positions($actor)->sole();

        $this->assertSame(
            '3.000000',
            $position->physicalAvailableQuantity
        );
        $this->assertSame(
            '0.000000',
            $position->commercialAvailableQuantity
        );
        $this->assertSame(
            ['product_inactive'],
            $position->restrictionReasons
        );
        $this->assertTrue($position->hasCommercialRestriction());
        $this->assertFalse($position->isPromiseable());

        $policy = app(CommerceSalePolicyGuard::class);
        $key = implode(':', [
            $product->id,
            $location->id,
            InventoryCondition::New->value,
        ]);

        $this->assertSame(
            '0.000000',
            $policy->availabilityMatrix($actor)[$key]['quantity']
        );

        $message = $policy->stockShortageMessage([
            [
                'catalog_product_id' => $product->id,
                'source_location_id' => $location->id,
                'condition' => InventoryCondition::New->value,
                'quantity' => '1',
            ],
        ], $actor);

        $this->assertNotNull($message);
        $this->assertStringContainsString(
            'Disponibles: 0',
            $message
        );
    }

    public function test_commercial_reader_preserves_inventory_tenant_boundary(): void
    {
        $first = $this->organization();
        $second = $this->newOrganization(
            'Tenant commercial availability'
        );
        $actor = $this->user($first);
        $product = $this->product(
            'Producto compartido',
            'COMM-AVAIL-TENANT'
        );

        $this->balance(
            $first,
            $product,
            $this->location($first, 'Deposito primero'),
            '4.000000'
        );
        $this->balance(
            $second,
            $product,
            $this->location($second, 'Deposito segundo'),
            '999.000000'
        );

        $positions = app(CommercialAvailabilityReader::class)
            ->positions($actor);

        $this->assertCount(1, $positions);
        $this->assertSame(
            $first->id,
            $positions->sole()->organizationId
        );
        $this->assertSame(
            '4.000000',
            $positions->sole()->commercialAvailableQuantity
        );
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

    private function user(Organization $organization): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => UserRole::Admin,
                    'active' => true,
                ]
            )
        );

        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        return $user->refresh();
    }

    private function product(
        string $name,
        string $sku,
        string $baseUnit = 'unit',
        int $scale = 0,
        bool $active = true
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'commercial-availability'],
                [
                    'name' => 'Commercial Availability',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'base_unit_code' => $baseUnit,
                'quantity_scale' => $scale,
                'active' => $active,
            ])->refresh()
        );
    }

    private function location(
        Organization $organization,
        string $name
    ): InventoryLocation {
        return InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'type' => InventoryLocationType::Warehouse,
                'active' => true,
            ])->refresh()
        );
    }

    private function balance(
        Organization $organization,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity
    ): InventoryBalance {
        return InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New,
            'quantity' => $quantity,
            'base_unit_code' => $product->base_unit_code,
            'version' => 1,
        ]);
    }
}