<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\FractionalContainerManager;
use App\Domain\Inventory\ProductPresentationManager;
use App\Enums\FractionalContainerState;
use App\Enums\InventoryCondition;
use App\Models\CatalogProduct;
use App\Models\FractionalContainer;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\ProductCategory;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FractionalContainerFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_registers_sealed_container_with_exact_physical_origin(): void
    {
        $organization = $this->organization();
        $location = $this->location($organization);
        $product = $this->product(
            'Harina 000',
            'FRACTION-CONTAINER-KG',
            'kg',
            3
        );

        $presentation = app(ProductPresentationManager::class)->create(
            $organization->id,
            $product->id,
            'bag25',
            'Bolsa de 25 kg',
            '25',
            0
        );

        $container = app(FractionalContainerManager::class)->register(
            $organization->id,
            $product->id,
            $location->id,
            'BAG-A-001',
            '25',
            InventoryCondition::New,
            $presentation->id
        );

        $this->assertSame('BAG-A-001', $container->container_code);
        $this->assertSame(
            'baga001',
            $container->normalized_container_code
        );
        $this->assertSame(
            FractionalContainerState::Sealed,
            $container->state
        );
        $this->assertTrue($container->isSealed());
        $this->assertSame(
            '25.000000',
            $container->original_base_quantity
        );
        $this->assertSame(
            '25.000000',
            $container->remaining_base_quantity
        );
        $this->assertSame('kg', $container->base_unit_code);
        $this->assertSame(3, $container->base_quantity_scale);
        $this->assertSame($presentation->id, $container->product_presentation_id);
        $this->assertSame($location->id, $container->inventory_location_id);
    }

    public function test_registration_is_idempotent_and_collision_safe(): void
    {
        $organization = $this->organization();
        $location = $this->location($organization);
        $product = $this->product(
            'Aceite a granel',
            'FRACTION-CONTAINER-L',
            'l',
            3
        );

        $manager = app(FractionalContainerManager::class);

        $first = $manager->register(
            $organization->id,
            $product->id,
            $location->id,
            'DRUM-A',
            '200',
            InventoryCondition::New
        );

        $second = $manager->register(
            $organization->id,
            $product->id,
            $location->id,
            ' drum-a ',
            '200.000',
            InventoryCondition::New
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('fractional_containers', 1);

        $this->assertDomainRejected(
            fn () => $manager->register(
                $organization->id,
                $product->id,
                $location->id,
                'DRUM-A',
                '180',
                InventoryCondition::New
            )
        );

        $this->assertDatabaseCount('fractional_containers', 1);
    }

    public function test_rejects_non_fractional_or_inexact_container_contracts(): void
    {
        $organization = $this->organization();
        $location = $this->location($organization);
        $manager = app(FractionalContainerManager::class);

        $unitProduct = $this->product(
            'Unidad indivisible',
            'FRACTION-CONTAINER-UNIT',
            'unit',
            0
        );

        $this->assertDomainRejected(
            fn () => $manager->register(
                $organization->id,
                $unitProduct->id,
                $location->id,
                'UNIT-BOX',
                '12'
            )
        );

        $kgProduct = $this->product(
            'Producto por peso',
            'FRACTION-CONTAINER-KG-STRICT',
            'kg',
            3
        );

        $this->assertDomainRejected(
            fn () => $manager->register(
                $organization->id,
                $kgProduct->id,
                $location->id,
                'TOO-FINE',
                '25.0005'
            )
        );

        $otherProduct = $this->product(
            'Otro producto',
            'FRACTION-CONTAINER-OTHER',
            'kg',
            3
        );

        $otherPresentation = app(ProductPresentationManager::class)->create(
            $organization->id,
            $otherProduct->id,
            'bag25other',
            'Bolsa de otro producto',
            '25',
            0
        );

        $this->assertDomainRejected(
            fn () => $manager->register(
                $organization->id,
                $kgProduct->id,
                $location->id,
                'MISMATCH-PRESENTATION',
                '25',
                InventoryCondition::New,
                $otherPresentation->id
            )
        );
    }

    public function test_registration_does_not_create_or_modify_inventory_stock(): void
    {
        $organization = $this->organization();
        $location = $this->location($organization);
        $product = $this->product(
            'Lubricante fraccionable',
            'FRACTION-CONTAINER-NO-STOCK',
            'l',
            3
        );

        $movementCount = DB::table('inventory_movements')->count();
        $movementLineCount = DB::table(
            'inventory_movement_lines'
        )->count();
        $balanceRows = DB::table('inventory_balances')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
        $reservationCount = DB::table(
            'inventory_reservations'
        )->count();

        app(FractionalContainerManager::class)->register(
            $organization->id,
            $product->id,
            $location->id,
            'NO-STOCK-DRUM',
            '200'
        );

        $this->assertSame(
            $movementCount,
            DB::table('inventory_movements')->count()
        );
        $this->assertSame(
            $movementLineCount,
            DB::table('inventory_movement_lines')->count()
        );
        $this->assertSame(
            $balanceRows,
            DB::table('inventory_balances')
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all()
        );
        $this->assertSame(
            $reservationCount,
            DB::table('inventory_reservations')->count()
        );
    }

    public function test_container_state_and_balance_are_fail_closed_until_policy_cut(): void
    {
        $organization = $this->organization();
        $location = $this->location($organization);
        $product = $this->product(
            'Aceite sellado',
            'FRACTION-CONTAINER-SEALED',
            'l',
            3
        );

        $container = app(FractionalContainerManager::class)->register(
            $organization->id,
            $product->id,
            $location->id,
            'SEALED-A',
            '200'
        );

        $this->assertDomainRejected(
            fn () => $container->update([
                'state' => FractionalContainerState::Open,
            ])
        );

        $container->refresh();

        $this->assertDomainRejected(
            fn () => $container->update([
                'remaining_base_quantity' => '180',
            ])
        );

        $container->refresh();

        $this->assertDomainRejected(
            fn () => $container->delete()
        );

        $container->refresh();

        $this->assertSame(
            FractionalContainerState::Sealed,
            $container->state
        );
        $this->assertSame(
            '200.000000',
            $container->remaining_base_quantity
        );

        $manager = app(FractionalContainerManager::class);

        $this->assertFalse(method_exists($manager, 'open'));
        $this->assertFalse(method_exists($manager, 'consume'));
        $this->assertFalse(method_exists($manager, 'allocate'));
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function location(Organization $organization): InventoryLocation
    {
        return InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function category(): ProductCategory
    {
        return ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'fractional-containers'],
                [
                    'name' => 'Fractional Containers',
                    'active' => true,
                ]
            )
        );
    }

    private function product(
        string $name,
        string $sku,
        string $baseUnit,
        int $scale
    ): CatalogProduct {
        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $this->category()->id,
                'sku' => $sku,
                'name' => $name,
                'base_unit_code' => $baseUnit,
                'quantity_scale' => $scale,
                'active' => true,
            ])->refresh()
        );
    }

    private function assertDomainRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('La operación debía ser rechazada por el dominio.');
    }
}
