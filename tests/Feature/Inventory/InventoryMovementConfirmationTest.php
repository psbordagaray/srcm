<?php

namespace Tests\Feature\Inventory;

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

class InventoryMovementConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_receipt_projects_once_under_repeated_confirmation(): void
    {
        $organization = $this->organization();
        $actor = $this->actor($organization);
        $product = $this->product();
        $location = $this->locations()->first();
        $movement = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Receipt
        );

        $this->line(
            $movement,
            $product,
            destination: $location,
            baseQuantity: '5'
        );

        $confirmer = app(InventoryMovementConfirmer::class);
        $first = $confirmer->confirm($movement, $actor);
        $balance = $this->balance($product, $location);

        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $first->status
        );
        $this->assertSame($actor->id, $first->confirmed_by_user_id);
        $this->assertSame('5.000000', $balance->quantity);
        $this->assertSame(1, $balance->version);

        $second = $confirmer->confirm($movement->id, $actor);
        $balance->refresh();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('5.000000', $balance->quantity);
        $this->assertSame(1, $balance->version);
    }

    public function test_transfer_is_atomic_and_moves_the_projected_balance(): void
    {
        $organization = $this->organization();
        $actor = $this->actor($organization);
        $product = $this->product();
        [$source, $destination] = $this->locations()->take(2)->values();
        $confirmer = app(InventoryMovementConfirmer::class);

        $receipt = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Receipt
        );
        $this->line(
            $receipt,
            $product,
            destination: $source,
            baseQuantity: '10'
        );
        $confirmer->confirm($receipt, $actor);

        $transfer = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Transfer
        );
        $this->line(
            $transfer,
            $product,
            source: $source,
            destination: $destination,
            baseQuantity: '4'
        );
        $confirmer->confirm($transfer, $actor);

        $this->assertSame(
            '6.000000',
            $this->balance($product, $source)->quantity
        );
        $this->assertSame(
            '4.000000',
            $this->balance($product, $destination)->quantity
        );
    }

    public function test_insufficient_issue_rolls_back_and_remains_draft(): void
    {
        $organization = $this->organization();
        $actor = $this->actor($organization);
        $product = $this->product();
        $location = $this->locations()->first();
        $confirmer = app(InventoryMovementConfirmer::class);

        $receipt = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Receipt
        );
        $this->line(
            $receipt,
            $product,
            destination: $location,
            baseQuantity: '2'
        );
        $confirmer->confirm($receipt, $actor);

        $issue = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Issue
        );
        $this->line(
            $issue,
            $product,
            source: $location,
            baseQuantity: '3'
        );

        try {
            $confirmer->confirm($issue, $actor);
            $this->fail('Se confirmó una salida sin saldo suficiente.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            InventoryMovementStatus::Draft,
            $issue->refresh()->status
        );
        $this->assertSame(
            '2.000000',
            $this->balance($product, $location)->quantity
        );
        $this->assertSame(
            1,
            $this->balance($product, $location)->version
        );
    }

    public function test_confirmation_rejects_tampered_conversion_atomically(): void
    {
        $organization = $this->organization();
        $actor = $this->actor($organization);
        $product = $this->product('Aceite', 'OIL-CONFIRM');
        $product->forceFill([
            'base_unit_code' => 'l',
            'quantity_scale' => 3,
        ])->saveQuietly();
        $location = $this->locations()->first();
        $movement = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Receipt
        );

        DB::table('inventory_movement_lines')->insert([
            'organization_id' => $organization->id,
            'inventory_movement_id' => $movement->id,
            'sequence' => 1,
            'catalog_product_id' => $product->id,
            'condition' => InventoryCondition::New->value,
            'source_location_id' => null,
            'destination_location_id' => $location->id,
            'entered_quantity' => '1.000000',
            'entered_unit_code' => 'drum',
            'conversion_factor' => '200.00000000',
            'base_quantity' => '199.000000',
            'base_unit_code' => 'l',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(InventoryMovementConfirmer::class)
                ->confirm($movement, $actor);
            $this->fail('Se confirmó una conversión adulterada.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseMissing('inventory_balances', [
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
        ]);
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $movement->refresh()->status
        );
    }

    public function test_draft_line_rejects_precision_that_would_be_rounded(): void
    {
        $organization = $this->organization();
        $actor = $this->actor($organization);
        $product = $this->product('Cable', 'CABLE-PRECISION');
        $product->forceFill([
            'base_unit_code' => 'm',
            'quantity_scale' => 6,
        ])->saveQuietly();
        $movement = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Receipt
        );

        $this->expectException(DomainException::class);

        $this->line(
            $movement,
            $product,
            destination: $this->locations()->first(),
            enteredQuantity: '0.0000009',
            baseQuantity: '0.0000009',
            enteredUnit: 'm',
            baseUnit: 'm'
        );
    }

    public function test_foreign_actor_cannot_confirm_another_organization(): void
    {
        $first = $this->organization();
        $second = $this->newOrganization('Organización ajena');
        $actor = $this->actor($first);
        $movement = $this->movement(
            $second,
            null,
            InventoryMovementType::Receipt
        );

        $this->expectException(DomainException::class);

        app(InventoryMovementConfirmer::class)
            ->confirm($movement, $actor);
    }

    public function test_fractional_pool_reaches_two_hundred_fifteen_liters(): void
    {
        $organization = $this->organization();
        $actor = $this->actor($organization);
        $product = $this->product('Lubricante a granel', 'OIL-POOL');
        $product->forceFill([
            'base_unit_code' => 'l',
            'quantity_scale' => 3,
        ])->saveQuietly();
        [$sealed, $fractional] = $this->locations()->take(2)->values();
        $confirmer = app(InventoryMovementConfirmer::class);

        $receipt = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Receipt
        );
        $this->line(
            $receipt,
            $product,
            destination: $sealed,
            enteredQuantity: '2',
            conversionFactor: '200',
            baseQuantity: '400',
            enteredUnit: 'drum',
            baseUnit: 'l'
        );
        $confirmer->confirm($receipt, $actor);

        $firstRelease = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Transfer
        );
        $this->line(
            $firstRelease,
            $product,
            source: $sealed,
            destination: $fractional,
            enteredQuantity: '1',
            conversionFactor: '200',
            baseQuantity: '200',
            enteredUnit: 'drum',
            baseUnit: 'l'
        );
        $confirmer->confirm($firstRelease, $actor);

        $sales = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Issue
        );
        $this->line(
            $sales,
            $product,
            source: $fractional,
            enteredQuantity: '185',
            baseQuantity: '185',
            enteredUnit: 'l',
            baseUnit: 'l'
        );
        $confirmer->confirm($sales, $actor);

        $secondRelease = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Transfer
        );
        $this->line(
            $secondRelease,
            $product,
            source: $sealed,
            destination: $fractional,
            enteredQuantity: '1',
            conversionFactor: '200',
            baseQuantity: '200',
            enteredUnit: 'drum',
            baseUnit: 'l'
        );
        $confirmer->confirm($secondRelease, $actor);

        $this->assertSame(
            '0.000000',
            $this->balance($product, $sealed)->quantity
        );
        $this->assertSame(
            '215.000000',
            $this->balance($product, $fractional)->quantity
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

    private function product(
        string $name = 'Producto confirmable',
        string $sku = 'INV-CONFIRM'
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'inventory-confirmation'],
                [
                    'name' => 'Inventory Confirmation',
                    'active' => true,
                ]
            )
        );
        $existing = CatalogProduct::query()
            ->where('sku', $sku)
            ->first();

        if ($existing) {
            return $existing->refresh();
        }

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'active' => true,
            ])->refresh()
        );
    }

    private function movement(
        Organization $organization,
        ?User $actor,
        InventoryMovementType $type
    ): InventoryMovement {
        return InventoryMovement::query()->create([
            'organization_id' => $organization->id,
            'type' => $type,
            'status' => InventoryMovementStatus::Draft,
            'created_by_user_id' => $actor?->id,
            'effective_at' => now(),
            'reason' => 'Prueba de confirmación',
            'idempotency_key' => 'confirm:'.Str::uuid(),
        ]);
    }

    private function line(
        InventoryMovement $movement,
        CatalogProduct $product,
        ?InventoryLocation $source = null,
        ?InventoryLocation $destination = null,
        ?string $enteredQuantity = null,
        string $conversionFactor = '1',
        string $baseQuantity = '1',
        string $enteredUnit = 'unit',
        string $baseUnit = 'unit'
    ): InventoryMovementLine {
        return InventoryMovementLine::query()->create([
            'organization_id' => $movement->organization_id,
            'inventory_movement_id' => $movement->id,
            'sequence' => $movement->lines()->count() + 1,
            'catalog_product_id' => $product->id,
            'condition' => InventoryCondition::New,
            'source_location_id' => $source?->id,
            'destination_location_id' => $destination?->id,
            'entered_quantity' => $enteredQuantity ?? $baseQuantity,
            'entered_unit_code' => $enteredUnit,
            'conversion_factor' => $conversionFactor,
            'base_quantity' => $baseQuantity,
            'base_unit_code' => $baseUnit,
        ]);
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
