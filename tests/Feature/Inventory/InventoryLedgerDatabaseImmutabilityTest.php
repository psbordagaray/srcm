<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Closure;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryLedgerDatabaseImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_database_rejects_confirmed_ledger_tampering(): void
    {
        [$movement, $line] = $this->confirmedMovement();
        $now = now();

        $this->assertQueryRejected(
            fn () => DB::table('inventory_movements')
                ->where('id', $movement->id)
                ->update(['reason' => 'Manipulado'])
        );

        $this->assertQueryRejected(
            fn () => DB::table('inventory_movement_lines')
                ->where('id', $line->id)
                ->update(['notes' => 'Manipulada'])
        );

        $this->assertQueryRejected(
            fn () => DB::table('inventory_movement_lines')
                ->where('id', $line->id)
                ->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table('inventory_movement_lines')->insert([
                'organization_id' => $movement->organization_id,
                'inventory_movement_id' => $movement->id,
                'sequence' => 2,
                'catalog_product_id' => $line->catalog_product_id,
                'condition' => InventoryCondition::New->value,
                'source_location_id' => null,
                'destination_location_id' =>
                    $line->destination_location_id,
                'entered_quantity' => '1.000000',
                'entered_unit_code' => 'unit',
                'conversion_factor' => '1.00000000',
                'base_quantity' => '1.000000',
                'base_unit_code' => 'unit',
                'created_at' => $now,
                'updated_at' => $now,
            ])
        );

        $this->assertQueryRejected(
            fn () => DB::table('inventory_movements')
                ->where('id', $movement->id)
                ->delete()
        );

        $this->assertDatabaseHas('inventory_movements', [
            'id' => $movement->id,
            'status' => InventoryMovementStatus::Confirmed->value,
            'reason' => 'Prueba de inmutabilidad',
        ]);

        $this->assertDatabaseHas('inventory_movement_lines', [
            'id' => $line->id,
            'notes' => null,
        ]);
    }

    public function test_database_keeps_draft_lines_editable(): void
    {
        [$movement, $line] = $this->draftMovement();

        $this->assertSame(
            1,
            DB::table('inventory_movement_lines')
                ->where('id', $line->id)
                ->update(['notes' => 'Borrador editable'])
        );

        $this->assertSame(
            1,
            DB::table('inventory_movement_lines')
                ->where('id', $line->id)
                ->delete()
        );

        $this->assertSame(
            1,
            DB::table('inventory_movements')
                ->where('id', $movement->id)
                ->delete()
        );
    }

    /**
     * @return array{InventoryMovement, InventoryMovementLine}
     */
    private function confirmedMovement(): array
    {
        [$movement, $line, $actor] = $this->draftMovementWithActor();

        app(InventoryMovementConfirmer::class)
            ->confirm($movement, $actor);

        return [$movement->refresh(), $line->refresh()];
    }

    /**
     * @return array{InventoryMovement, InventoryMovementLine}
     */
    private function draftMovement(): array
    {
        [$movement, $line] = $this->draftMovementWithActor();

        return [$movement, $line];
    }

    /**
     * @return array{InventoryMovement, InventoryMovementLine, User}
     */
    private function draftMovementWithActor(): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $actor = $this->actor($organization);
        $location = InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->firstOrFail();
        $product = $this->product();

        $movement = InventoryMovement::query()->create([
            'organization_id' => $organization->id,
            'type' => InventoryMovementType::Receipt,
            'status' => InventoryMovementStatus::Draft,
            'created_by_user_id' => $actor->id,
            'effective_at' => now(),
            'reason' => 'Prueba de inmutabilidad',
            'idempotency_key' => 'immutable:'.Str::uuid(),
        ]);

        $line = InventoryMovementLine::query()->create([
            'organization_id' => $organization->id,
            'inventory_movement_id' => $movement->id,
            'sequence' => 1,
            'catalog_product_id' => $product->id,
            'condition' => InventoryCondition::New,
            'destination_location_id' => $location->id,
            'entered_quantity' => '1',
            'entered_unit_code' => 'unit',
            'conversion_factor' => '1',
            'base_quantity' => '1',
            'base_unit_code' => 'unit',
        ]);

        return [$movement, $line, $actor];
    }

    private function actor(Organization $organization): User
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
                    'role' => UserRole::Admin->value,
                    'active' => true,
                ]
            )
        );

        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        return $user->refresh();
    }

    private function product(): CatalogProduct
    {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'ledger-immutability'],
                [
                    'name' => 'Ledger Immutability',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->firstOrCreate(
                ['sku' => 'LEDGER-IMMUTABLE'],
                [
                    'product_category_id' => $category->id,
                    'name' => 'Producto inmutable',
                    'active' => true,
                ]
            )->refresh()
        );
    }

    private function assertQueryRejected(Closure $query): void
    {
        try {
            $query();
            $this->fail('La base aceptó manipular el libro confirmado.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
