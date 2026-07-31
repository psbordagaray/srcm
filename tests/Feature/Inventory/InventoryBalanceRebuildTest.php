<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryBalanceRebuilder;
use App\Domain\Inventory\InventoryBalanceVerifier;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryBalanceRebuildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_verifier_detects_and_rebuild_repairs_drift(): void
    {
        $organization = $this->organization();
        $actor = $this->admin();
        $product = $this->product();
        [$first, $second, $unexpected] = $this->locations();

        $this->confirm(
            InventoryMovementType::Receipt,
            $product,
            destination: $first,
            quantity: '10'
        );
        $this->confirm(
            InventoryMovementType::Transfer,
            $product,
            source: $first,
            destination: $second,
            quantity: '4'
        );
        $this->confirm(
            InventoryMovementType::Issue,
            $product,
            source: $second,
            quantity: '1'
        );

        $verifier = app(InventoryBalanceVerifier::class);
        $this->assertTrue(
            $verifier->verify($organization->id)->isConsistent()
        );

        DB::table('inventory_balances')
            ->where('inventory_location_id', $first->id)
            ->update(['quantity' => '999.000000']);
        DB::table('inventory_balances')
            ->where('inventory_location_id', $second->id)
            ->delete();
        DB::table('inventory_balances')->insert([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $unexpected->id,
            'condition' => InventoryCondition::Used->value,
            'quantity' => '7.000000',
            'base_unit_code' => 'unit',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $drift = $verifier->verify($organization->id);

        $this->assertFalse($drift->isConsistent());
        $this->assertSame(3, $drift->differenceCount());
        $this->assertEqualsCanonicalizing(
            [
                'quantity_mismatch',
                'missing_balance',
                'unexpected_balance',
            ],
            array_column($drift->differences, 'type')
        );

        $rebuilt = app(InventoryBalanceRebuilder::class)
            ->rebuild($organization, $actor);

        $this->assertTrue($rebuilt->isConsistent());
        $this->assertSame(
            '6.000000',
            $this->balance($product, $first)->quantity
        );
        $this->assertSame(
            '3.000000',
            $this->balance($product, $second)->quantity
        );
        $this->assertDatabaseMissing('inventory_balances', [
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $unexpected->id,
            'condition' => InventoryCondition::Used->value,
        ]);
        $this->assertSame(
            3,
            InventoryMovement::query()
                ->where('status', InventoryMovementStatus::Confirmed->value)
                ->count()
        );
        $this->assertSame(3, InventoryMovementLine::query()->count());
    }

    public function test_drafts_do_not_enter_rebuilt_projection(): void
    {
        $organization = $this->organization();
        $product = $this->product();
        $location = $this->locations()->first();

        $this->confirm(
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '5'
        );

        $draft = $this->movement(InventoryMovementType::Receipt);
        $this->line(
            $draft,
            $product,
            destination: $location,
            quantity: '100'
        );

        $result = app(InventoryBalanceRebuilder::class)
            ->rebuild($organization, $this->admin());

        $this->assertTrue($result->isConsistent());
        $this->assertSame(
            '5.000000',
            $this->balance($product, $location)->quantity
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $draft->refresh()->status
        );
    }

    public function test_rebuild_is_idempotent_and_admin_only(): void
    {
        $organization = $this->organization();
        $product = $this->product();
        $location = $this->locations()->first();

        $this->confirm(
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '2'
        );

        $balance = $this->balance($product, $location);
        $version = $balance->version;
        $rebuilder = app(InventoryBalanceRebuilder::class);

        $rebuilder->rebuild($organization, $this->admin());
        $rebuilder->rebuild($organization, $this->admin());

        $this->assertSame($version, $balance->refresh()->version);

        $viewer = $this->user(UserRole::Viewer);

        try {
            $rebuilder->rebuild($organization, $viewer);
            $this->fail('Un usuario de consulta reconstruyó saldos.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            '2.000000',
            $this->balance($product, $location)->quantity
        );
    }

    private function confirm(
        InventoryMovementType $type,
        CatalogProduct $product,
        ?InventoryLocation $source = null,
        ?InventoryLocation $destination = null,
        string $quantity = '1'
    ): InventoryMovement {
        $movement = $this->movement($type);
        $this->line(
            $movement,
            $product,
            $source,
            $destination,
            $quantity
        );

        return app(InventoryMovementConfirmer::class)
            ->confirm($movement, $this->admin());
    }

    private function movement(
        InventoryMovementType $type
    ): InventoryMovement {
        return InventoryMovement::query()->create([
            'organization_id' => $this->organization()->id,
            'type' => $type,
            'status' => InventoryMovementStatus::Draft,
            'created_by_user_id' => $this->admin()->id,
            'effective_at' => now(),
            'reason' => 'Prueba de reconstrucción',
            'idempotency_key' => 'rebuild:'.Str::uuid(),
        ]);
    }

    private function line(
        InventoryMovement $movement,
        CatalogProduct $product,
        ?InventoryLocation $source = null,
        ?InventoryLocation $destination = null,
        string $quantity = '1'
    ): InventoryMovementLine {
        return InventoryMovementLine::query()->create([
            'organization_id' => $movement->organization_id,
            'inventory_movement_id' => $movement->id,
            'sequence' => 1,
            'catalog_product_id' => $product->id,
            'condition' => InventoryCondition::New,
            'source_location_id' => $source?->id,
            'destination_location_id' => $destination?->id,
            'entered_quantity' => $quantity,
            'entered_unit_code' => 'unit',
            'conversion_factor' => '1',
            'base_quantity' => $quantity,
            'base_unit_code' => 'unit',
        ]);
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function admin(): User
    {
        return User::query()
            ->where('email', 'test@example.com')
            ->firstOrFail();
    }

    private function user(UserRole $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $this->organization()->id,
                'user_id' => $user->id,
            ],
            [
                'role' => $role->value,
                'active' => true,
            ]
        );

        $user->forceFill([
            'current_organization_id' => $this->organization()->id,
        ])->saveQuietly();

        return $user->refresh();
    }

    /**
     * @return \Illuminate\Support\Collection<int, InventoryLocation>
     */
    private function locations()
    {
        return InventoryLocation::query()
            ->where('organization_id', $this->organization()->id)
            ->orderBy('id')
            ->get();
    }

    private function product(): CatalogProduct
    {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'balance-rebuild'],
                [
                    'name' => 'Balance Rebuild',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->firstOrCreate(
                ['sku' => 'BALANCE-REBUILD'],
                [
                    'product_category_id' => $category->id,
                    'name' => 'Producto reconstruible',
                    'active' => true,
                ]
            )->refresh()
        );
    }

    private function balance(
        CatalogProduct $product,
        InventoryLocation $location
    ): InventoryBalance {
        return InventoryBalance::query()
            ->where('organization_id', $this->organization()->id)
            ->where('catalog_product_id', $product->id)
            ->where('inventory_location_id', $location->id)
            ->where('condition', InventoryCondition::New->value)
            ->firstOrFail();
    }
}
