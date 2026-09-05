<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\ProductPresentationManager;
use App\Models\CatalogProduct;
use App\Models\Organization;
use App\Models\ProductCategory;
use App\Models\ProductPresentation;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductPresentationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_presentation_converts_exactly_to_base_quantity(): void
    {
        $organization = $this->organization();
        $product = $this->product(
            'Artículo por unidad',
            'PRESENT-UNIT',
            'unit',
            0
        );
        $manager = app(ProductPresentationManager::class);

        $presentation = $manager->create(
            $organization->id,
            $product->id,
            'BOX12',
            'Caja x 12',
            '12',
            0
        );

        $this->assertSame('box12', $presentation->unit_code);
        $this->assertSame('Caja x 12', $presentation->name);
        $this->assertSame(0, $presentation->quantity_scale);
        $this->assertSame(
            '12.00000000',
            $presentation->conversion_factor
        );
        $this->assertSame('unit', $presentation->base_unit_code);
        $this->assertSame(0, $presentation->base_quantity_scale);
        $this->assertSame(
            '24.000000',
            $manager->convert(
                $organization->id,
                $product->id,
                'box12',
                '2'
            )
        );

        $this->assertTrue(
            $product->productPresentations()
                ->whereKey($presentation->id)
                ->exists()
        );
    }

    public function test_fractional_presentation_respects_both_scales(): void
    {
        $organization = $this->organization();
        $product = $this->product(
            'Producto por peso',
            'PRESENT-KG',
            'kg',
            3
        );
        $manager = app(ProductPresentationManager::class);

        $presentation = $manager->create(
            $organization->id,
            $product->id,
            'bag250',
            'Bolsa de 250 g',
            '0.25',
            2
        );

        $this->assertSame(
            '0.125000',
            $presentation->toBaseQuantity('0.5')
        );

        $this->assertDomainRejected(
            fn () => $presentation->toBaseQuantity('0.333')
        );

        $this->assertDomainRejected(
            fn () => $manager->create(
                $organization->id,
                $product->id,
                'too-fine',
                'Conversión demasiado fina',
                '0.0005',
                0
            )
        );

        $unitProduct = $this->product(
            'Unidad indivisible',
            'PRESENT-NO-FRACTION',
            'unit',
            0
        );

        $this->assertDomainRejected(
            fn () => $manager->create(
                $organization->id,
                $unitProduct->id,
                'half',
                'Media unidad',
                '0.5',
                0
            )
        );
    }

    public function test_creation_is_exactly_idempotent_and_collision_safe(): void
    {
        $organization = $this->organization();
        $product = $this->product(
            'Producto idempotente',
            'PRESENT-IDEM',
            'unit',
            0
        );
        $manager = app(ProductPresentationManager::class);

        $first = $manager->create(
            $organization->id,
            $product->id,
            'pack6',
            'Pack x 6',
            '6',
            0
        );

        $second = $manager->create(
            $organization->id,
            $product->id,
            'PACK6',
            'Pack x 6',
            '6.00000000',
            0
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('product_presentations', 1);

        $this->assertDomainRejected(
            fn () => $manager->create(
                $organization->id,
                $product->id,
                'pack6',
                'Pack x 6',
                '12',
                0
            )
        );

        $this->assertDatabaseCount('product_presentations', 1);
    }

    public function test_conversion_does_not_create_parallel_stock(): void
    {
        $organization = $this->organization();
        $product = $this->product(
            'Cable por metro',
            'PRESENT-NO-STOCK',
            'm',
            3
        );
        $manager = app(ProductPresentationManager::class);

        $presentation = $manager->create(
            $organization->id,
            $product->id,
            'roll100',
            'Rollo de 100 m',
            '100',
            1
        );

        $movementCount = DB::table('inventory_movements')->count();
        $movementLineCount = DB::table(
            'inventory_movement_lines'
        )->count();
        $balanceCount = DB::table('inventory_balances')->count();
        $reservationCount = DB::table(
            'inventory_reservations'
        )->count();

        $this->assertSame(
            '50.000000',
            $presentation->toBaseQuantity('0.5')
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
            $balanceCount,
            DB::table('inventory_balances')->count()
        );
        $this->assertSame(
            $reservationCount,
            DB::table('inventory_reservations')->count()
        );
    }

    public function test_stale_or_inactive_presentation_fails_closed(): void
    {
        $organization = $this->organization();
        $product = $this->product(
            'Líquido fraccionable',
            'PRESENT-STALE',
            'l',
            3
        );
        $manager = app(ProductPresentationManager::class);

        $presentation = $manager->create(
            $organization->id,
            $product->id,
            'drum200',
            'Tambor de 200 L',
            '200',
            0
        );

        $product->update([
            'base_unit_code' => 'kg',
            'quantity_scale' => 3,
        ]);

        $this->assertDomainRejected(
            fn () => $presentation->refresh()->toBaseQuantity('1')
        );

        $presentation->update(['active' => false]);

        $this->assertDomainRejected(
            fn () => $manager->convert(
                $organization->id,
                $product->id,
                'drum200',
                '1'
            )
        );

        $this->assertDatabaseHas('product_presentations', [
            'id' => $presentation->id,
            'base_unit_code' => 'l',
            'base_quantity_scale' => 3,
            'active' => false,
        ]);
    }

    public function test_quantitative_contract_is_immutable(): void
    {
        $organization = $this->organization();
        $product = $this->product(
            'Producto con contrato fijo',
            'PRESENT-IMMUTABLE',
            'kg',
            3
        );
        $manager = app(ProductPresentationManager::class);

        $presentation = $manager->create(
            $organization->id,
            $product->id,
            'bag5',
            'Bolsa de 5 kg',
            '5',
            0
        );

        $this->assertDomainRejected(
            fn () => $presentation->update([
                'conversion_factor' => '10',
            ])
        );

        $presentation->refresh();

        $this->assertSame(
            '5.00000000',
            $presentation->conversion_factor
        );
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function category(): ProductCategory
    {
        return ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'product-presentations'],
                [
                    'name' => 'Product Presentations',
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
