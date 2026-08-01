<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\Organization;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class CatalogProductQuantityRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_fractionability_is_configured_per_product(): void
    {
        $admin = $this->admin();
        $category = $this->category();

        $this->actingAs($admin)
            ->post(route('products.store'), [
                'product_category_id' => $category->id,
                'sku' => 'OIL-FRACTION',
                'name' => 'Aceite fraccionable',
                'base_unit_code' => 'L',
                'quantity_scale' => '3',
                'active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $fractional = CatalogProduct::query()
            ->where('sku', 'OIL-FRACTION')
            ->firstOrFail();

        $this->assertSame('l', $fractional->base_unit_code);
        $this->assertSame(3, $fractional->quantity_scale);
        $this->assertTrue($fractional->allowsFractionalQuantity());

        $this->actingAs($admin)
            ->post(route('products.store'), [
                'product_category_id' => $category->id,
                'sku' => 'SULU-UNIT',
                'name' => 'Artículo entero SULU',
                'active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $unit = CatalogProduct::query()
            ->where('sku', 'SULU-UNIT')
            ->firstOrFail();

        $this->assertSame('unit', $unit->base_unit_code);
        $this->assertSame(0, $unit->quantity_scale);
        $this->assertFalse($unit->allowsFractionalQuantity());

        $this->actingAs($admin)
            ->get(route('products.create'))
            ->assertOk()
            ->assertSee('Unidad base de inventario')
            ->assertSee('La venta fraccionada se habilita por artículo');
    }

    public function test_unit_product_rejects_fractional_precision(): void
    {
        $this->actingAs($this->admin())
            ->from(route('products.create'))
            ->post(route('products.store'), [
                'product_category_id' => $this->category()->id,
                'sku' => 'INVALID-UNIT',
                'name' => 'Unidad fraccionada inválida',
                'base_unit_code' => 'unit',
                'quantity_scale' => '2',
                'active' => '1',
            ])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('quantity_scale');

        $this->assertDatabaseMissing('catalog_products', [
            'sku' => 'INVALID-UNIT',
        ]);
    }

    public function test_legacy_update_preserves_existing_quantity_rules(): void
    {
        $admin = $this->admin();
        $category = $this->category();

        $this->actingAs($admin)->post(route('products.store'), [
            'product_category_id' => $category->id,
            'sku' => 'LEGACY-FRACTION',
            'name' => 'Producto fraccionable',
            'base_unit_code' => 'kg',
            'quantity_scale' => '3',
            'active' => '1',
        ])->assertSessionHasNoErrors();

        $product = CatalogProduct::query()
            ->where('sku', 'LEGACY-FRACTION')
            ->firstOrFail();

        $this->actingAs($admin)->put(
            route('products.update', $product),
            [
                'product_category_id' => $category->id,
                'sku' => 'LEGACY-FRACTION',
                'name' => 'Producto fraccionable actualizado',
                'active' => '1',
            ]
        )->assertSessionHasNoErrors();

        $this->assertSame('kg', $product->refresh()->base_unit_code);
        $this->assertSame(3, $product->quantity_scale);
    }

    public function test_quantity_rules_are_immutable_after_first_movement(): void
    {
        $product = $this->product('Cable por metro', 'LOCKED-M', 'm', 3);
        $organization = $this->organization();
        $location = $this->location($organization);
        $movement = InventoryMovement::query()->create([
            'organization_id' => $organization->id,
            'type' => InventoryMovementType::Receipt,
            'status' => InventoryMovementStatus::Draft,
            'effective_at' => now(),
            'idempotency_key' => 'quantity-rules:'.Str::uuid(),
        ]);

        InventoryMovementLine::query()->create([
            'organization_id' => $organization->id,
            'inventory_movement_id' => $movement->id,
            'sequence' => 1,
            'catalog_product_id' => $product->id,
            'condition' => InventoryCondition::New,
            'destination_location_id' => $location->id,
            'entered_quantity' => '1',
            'entered_unit_code' => 'm',
            'conversion_factor' => '1',
            'base_quantity' => '1',
            'base_unit_code' => 'm',
        ]);

        try {
            $product->update(['quantity_scale' => 2]);
            $this->fail('El modelo debió impedir reinterpretar el libro.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseRejected(
            fn () => DB::table('catalog_products')
                ->where('id', $product->id)
                ->update(['quantity_scale' => 2])
        );

        $this->assertSame(3, $product->refresh()->quantity_scale);
    }

    private function admin(): User
    {
        return User::query()
            ->where('email', 'test@example.com')
            ->firstOrFail();
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function location(
        Organization $organization
    ): InventoryLocation {
        return InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function category(): ProductCategory
    {
        return ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'quantity-rules'],
                ['name' => 'Quantity Rules', 'active' => true]
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

    private function assertDatabaseRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('La base debió rechazar la modificación histórica.');
    }
}
