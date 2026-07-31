<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\Organization;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryLedgerFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_ledger_schema_and_safe_product_quantity_defaults(): void
    {
        $this->assertTrue(Schema::hasColumns('inventory_movements', [
            'organization_id',
            'public_id',
            'type',
            'status',
            'idempotency_key',
            'reverses_movement_id',
            'replaces_movement_id',
        ]));
        $this->assertTrue(Schema::hasColumns('inventory_movement_lines', [
            'organization_id',
            'inventory_movement_id',
            'catalog_product_id',
            'condition',
            'source_location_id',
            'destination_location_id',
            'entered_quantity',
            'entered_unit_code',
            'conversion_factor',
            'base_quantity',
            'base_unit_code',
        ]));
        $this->assertTrue(Schema::hasColumns('inventory_balances', [
            'organization_id',
            'catalog_product_id',
            'inventory_location_id',
            'condition',
            'quantity',
            'base_unit_code',
            'version',
        ]));

        $product = $this->product()->refresh();

        $this->assertSame('unit', $product->base_unit_code);
        $this->assertSame(0, (int) $product->quantity_scale);
    }

    public function test_idempotency_is_unique_inside_each_organization(): void
    {
        $first = $this->organization();
        $second = $this->newOrganization('Segundo tenant');
        $key = 'receipt:external:123';

        $this->movement($first, InventoryMovementType::Receipt, $key);
        $this->movement($second, InventoryMovementType::Receipt, $key);

        $this->assertQueryRejected(
            fn () => $this->movement(
                $first,
                InventoryMovementType::Receipt,
                $key
            )
        );

        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_database_rejects_cross_organization_movement_relations(): void
    {
        $first = $this->organization();
        $second = $this->newOrganization('Tenant hostil');
        $location = $this->location();
        $product = $this->product();
        $movement = $this->movement(
            $second,
            InventoryMovementType::Issue
        );

        $this->assertQueryRejected(
            fn () => DB::table('inventory_movement_lines')->insert([
                'organization_id' => $second->id,
                'inventory_movement_id' => $movement->id,
                'sequence' => 1,
                'catalog_product_id' => $product->id,
                'condition' => InventoryCondition::New->value,
                'source_location_id' => $location->id,
                'destination_location_id' => null,
                'entered_quantity' => '1.000000',
                'entered_unit_code' => 'unit',
                'conversion_factor' => '1.00000000',
                'base_quantity' => '1.000000',
                'base_unit_code' => 'unit',
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );

        $this->assertDatabaseCount('inventory_movement_lines', 0);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_fractional_quantity_keeps_entered_and_base_units(): void
    {
        $organization = $this->organization();
        $location = $this->location();
        $product = $this->product('Aceite a granel', 'OIL-BULK');

        $product->forceFill([
            'base_unit_code' => 'l',
            'quantity_scale' => 3,
        ])->saveQuietly();

        $movement = $this->movement(
            $organization,
            InventoryMovementType::InitialBalance
        );

        $drum = InventoryMovementLine::query()->create([
            'organization_id' => $organization->id,
            'inventory_movement_id' => $movement->id,
            'sequence' => 1,
            'catalog_product_id' => $product->id,
            'condition' => InventoryCondition::New,
            'destination_location_id' => $location->id,
            'entered_quantity' => '1',
            'entered_unit_code' => 'DRUM',
            'conversion_factor' => '200',
            'base_quantity' => '200',
            'base_unit_code' => 'L',
        ]);

        $fraction = InventoryMovementLine::query()->create([
            'organization_id' => $organization->id,
            'inventory_movement_id' => $movement->id,
            'sequence' => 2,
            'catalog_product_id' => $product->id,
            'condition' => InventoryCondition::New,
            'destination_location_id' => $location->id,
            'entered_quantity' => '0.5',
            'entered_unit_code' => 'L',
            'conversion_factor' => '1',
            'base_quantity' => '0.5',
            'base_unit_code' => 'L',
        ]);

        $this->assertSame('drum', $drum->entered_unit_code);
        $this->assertSame('200.000000', $drum->base_quantity);
        $this->assertSame('l', $fraction->base_unit_code);
        $this->assertSame('0.500000', $fraction->base_quantity);
    }

    public function test_unit_product_rejects_fractional_base_quantity(): void
    {
        $organization = $this->organization();
        $movement = $this->movement(
            $organization,
            InventoryMovementType::Receipt
        );

        $this->expectException(DomainException::class);

        InventoryMovementLine::query()->create([
            'organization_id' => $organization->id,
            'inventory_movement_id' => $movement->id,
            'sequence' => 1,
            'catalog_product_id' => $this->product()->id,
            'condition' => InventoryCondition::New,
            'destination_location_id' => $this->location()->id,
            'entered_quantity' => '0.5',
            'entered_unit_code' => 'unit',
            'conversion_factor' => '1',
            'base_quantity' => '0.5',
            'base_unit_code' => 'unit',
        ]);
    }

    public function test_balance_dimension_is_unique_and_keeps_decimal_precision(): void
    {
        $organization = $this->organization();
        $location = $this->location();
        $product = $this->product('Cable por metro', 'CABLE-M');

        $product->forceFill([
            'base_unit_code' => 'm',
            'quantity_scale' => 3,
        ])->saveQuietly();

        $balance = InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New,
            'quantity' => '199.500000',
            'base_unit_code' => 'm',
            'version' => 1,
        ]);

        $this->assertSame('199.500000', $balance->quantity);

        $this->assertQueryRejected(
            fn () => InventoryBalance::query()->create([
                'organization_id' => $organization->id,
                'catalog_product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'condition' => InventoryCondition::New,
                'quantity' => '1.000000',
                'base_unit_code' => 'm',
            ])
        );
    }

    public function test_confirmed_movement_and_lines_are_immutable(): void
    {
        $organization = $this->organization();
        $movement = $this->movement(
            $organization,
            InventoryMovementType::Receipt
        );
        $line = InventoryMovementLine::query()->create([
            'organization_id' => $organization->id,
            'inventory_movement_id' => $movement->id,
            'sequence' => 1,
            'catalog_product_id' => $this->product()->id,
            'condition' => InventoryCondition::New,
            'destination_location_id' => $this->location()->id,
            'entered_quantity' => '1',
            'entered_unit_code' => 'unit',
            'conversion_factor' => '1',
            'base_quantity' => '1',
            'base_unit_code' => 'unit',
        ]);
        $user = User::factory()->create();

        $movement->forceFill([
            'status' => InventoryMovementStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $user->id,
        ])->save();

        foreach ([
            fn () => $movement->update(['reason' => 'Alterado']),
            fn () => $line->update(['notes' => 'Alterado']),
            fn () => $line->delete(),
            fn () => $movement->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('La mutación de historia confirmada fue aceptada.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseHas('inventory_movements', [
            'id' => $movement->id,
            'status' => InventoryMovementStatus::Confirmed->value,
            'reason' => 'Prueba de fundación',
        ]);
        $this->assertDatabaseHas('inventory_movement_lines', [
            'id' => $line->id,
            'notes' => null,
        ]);
    }

    public function test_reversal_and_replacement_links_are_scoped_and_single_use(): void
    {
        $organization = $this->organization();
        $original = $this->confirmedMovement($organization);

        InventoryMovement::query()->create([
            'organization_id' => $organization->id,
            'type' => InventoryMovementType::Reversal,
            'status' => InventoryMovementStatus::Draft,
            'effective_at' => now(),
            'reason' => 'Corrección de prueba',
            'idempotency_key' => 'reversal:'.$original->public_id,
            'reverses_movement_id' => $original->id,
        ]);

        InventoryMovement::query()->create([
            'organization_id' => $organization->id,
            'type' => InventoryMovementType::Receipt,
            'status' => InventoryMovementStatus::Draft,
            'effective_at' => now(),
            'reason' => 'Reemplazo correcto',
            'idempotency_key' => 'replacement:'.$original->public_id,
            'replaces_movement_id' => $original->id,
        ]);

        $this->assertQueryRejected(
            fn () => InventoryMovement::query()->create([
                'organization_id' => $organization->id,
                'type' => InventoryMovementType::Reversal,
                'status' => InventoryMovementStatus::Draft,
                'effective_at' => now(),
                'reason' => 'Segundo reverso indebido',
                'idempotency_key' => 'reversal:duplicate',
                'reverses_movement_id' => $original->id,
            ])
        );

        $foreignOrganization = $this->newOrganization('Tenant ajeno');

        try {
            InventoryMovement::query()->create([
                'organization_id' => $foreignOrganization->id,
                'type' => InventoryMovementType::Reversal,
                'status' => InventoryMovementStatus::Draft,
                'effective_at' => now(),
                'reason' => 'Ataque entre organizaciones',
                'idempotency_key' => 'foreign:reversal',
                'reverses_movement_id' => $original->id,
            ]);
            $this->fail('Se aceptó un reverso contra otro tenant.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_closed_vocabulary_contains_required_types_and_conditions(): void
    {
        $this->assertSame(9, count(InventoryMovementType::cases()));
        $this->assertSame(3, count(InventoryMovementStatus::cases()));
        $this->assertSame(5, count(InventoryCondition::cases()));
        $this->assertTrue(InventoryMovementType::Transfer->requiresSource());
        $this->assertTrue(InventoryMovementType::Transfer->requiresDestination());
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

    private function location(): InventoryLocation
    {
        return InventoryLocation::query()
            ->where('organization_id', $this->organization()->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function product(
        string $name = 'Producto de inventario',
        string $sku = 'INV-FOUNDATION'
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'inventory-foundation'],
                [
                    'name' => 'Inventory Foundation',
                    'active' => true,
                ]
            )
        );

        $existing = CatalogProduct::query()
            ->where('sku', $sku)
            ->first();

        if ($existing) {
            return $existing;
        }

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'active' => true,
            ])
        );
    }

    private function movement(
        Organization $organization,
        InventoryMovementType $type,
        ?string $idempotencyKey = null
    ): InventoryMovement {
        return InventoryMovement::query()->create([
            'organization_id' => $organization->id,
            'type' => $type,
            'status' => InventoryMovementStatus::Draft,
            'effective_at' => now(),
            'reason' => 'Prueba de fundación',
            'idempotency_key' => $idempotencyKey
                ?? 'test:'.Str::uuid(),
        ]);
    }

    private function confirmedMovement(
        Organization $organization
    ): InventoryMovement {
        $movement = $this->movement(
            $organization,
            InventoryMovementType::Receipt
        );

        $movement->forceFill([
            'status' => InventoryMovementStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_by_user_id' => User::factory()->create()->id,
        ])->save();

        return $movement;
    }

    private function assertQueryRejected(callable $query): void
    {
        try {
            $query();
            $this->fail('La base de datos aceptó una operación inválida.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
