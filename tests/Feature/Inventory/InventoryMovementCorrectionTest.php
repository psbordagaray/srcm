<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCorrector;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryMovementCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_receipt_correction_is_linked_atomic_and_idempotent(): void
    {
        $product = $this->product();
        $location = $this->locations()->first();
        $original = $this->confirm(
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '10',
            reason: 'Recepción original'
        );
        $replacement = $this->draft(
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '8'
        );

        $corrector = app(InventoryMovementCorrector::class);
        $correction = $corrector->correct(
            $original,
            $replacement,
            $this->admin(),
            'La recepción correcta era de ocho unidades',
            'receipt-eight'
        );

        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $correction->original->status
        );
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $correction->reversal->status
        );
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $correction->replacement->status
        );
        $this->assertSame(
            $original->id,
            $correction->reversal->reverses_movement_id
        );
        $this->assertSame(
            $original->id,
            $correction->replacement->replaces_movement_id
        );
        $this->assertSame(
            'Recepción original',
            $correction->original->reason
        );
        $this->assertSame(
            '8.000000',
            $this->balance($product, $location)->quantity
        );
        $this->assertSame(3, InventoryMovement::query()->count());

        $version = $this->balance($product, $location)->version;
        $repeated = $corrector->correct(
            $original->id,
            $replacement->id,
            $this->admin(),
            'La recepción correcta era de ocho unidades',
            'receipt-eight'
        );

        $this->assertSame(
            $correction->reversal->id,
            $repeated->reversal->id
        );
        $this->assertSame(3, InventoryMovement::query()->count());
        $this->assertSame(
            $version,
            $this->balance($product, $location)->version
        );
    }

    public function test_issue_correction_uses_combined_final_effect(): void
    {
        $product = $this->product();
        $location = $this->locations()->first();

        $this->confirm(
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '10'
        );
        $original = $this->confirm(
            InventoryMovementType::Issue,
            $product,
            source: $location,
            quantity: '6'
        );
        $replacement = $this->draft(
            InventoryMovementType::Issue,
            $product,
            source: $location,
            quantity: '4'
        );

        app(InventoryMovementCorrector::class)->correct(
            $original,
            $replacement,
            $this->admin(),
            'La salida real fue de cuatro unidades',
            'issue-four'
        );

        $this->assertSame(
            '6.000000',
            $this->balance($product, $location)->quantity
        );
    }

    public function test_transfer_correction_moves_only_the_net_effect(): void
    {
        $product = $this->product();
        [$source, $oldDestination, $newDestination] =
            $this->locations();

        $this->confirm(
            InventoryMovementType::Receipt,
            $product,
            destination: $source,
            quantity: '10'
        );
        $original = $this->confirm(
            InventoryMovementType::Transfer,
            $product,
            source: $source,
            destination: $oldDestination,
            quantity: '4'
        );
        $replacement = $this->draft(
            InventoryMovementType::Transfer,
            $product,
            source: $source,
            destination: $newDestination,
            quantity: '3'
        );

        app(InventoryMovementCorrector::class)->correct(
            $original,
            $replacement,
            $this->admin(),
            'El destino y la cantidad estaban equivocados',
            'transfer-corrected'
        );

        $this->assertSame(
            '7.000000',
            $this->balance($product, $source)->quantity
        );
        $this->assertSame(
            '0.000000',
            $this->balance($product, $oldDestination)->quantity
        );
        $this->assertSame(
            '3.000000',
            $this->balance($product, $newDestination)->quantity
        );
    }

    public function test_invalid_final_balance_rolls_back_entire_correction(): void
    {
        $product = $this->product();
        $location = $this->locations()->first();

        $this->confirm(
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '5'
        );
        $original = $this->confirm(
            InventoryMovementType::Issue,
            $product,
            source: $location,
            quantity: '4'
        );
        $replacement = $this->draft(
            InventoryMovementType::Issue,
            $product,
            source: $location,
            quantity: '6'
        );

        try {
            app(InventoryMovementCorrector::class)->correct(
                $original,
                $replacement,
                $this->admin(),
                'Intento que dejaría saldo negativo',
                'negative-final'
            );
            $this->fail('La corrección inválida fue confirmada.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            InventoryMovementStatus::Draft,
            $replacement->refresh()->status
        );
        $this->assertNull($replacement->replaces_movement_id);
        $this->assertFalse(
            InventoryMovement::query()
                ->where('reverses_movement_id', $original->id)
                ->exists()
        );
        $this->assertSame(
            '1.000000',
            $this->balance($product, $location)->quantity
        );
    }

    public function test_only_active_admin_can_correct(): void
    {
        $product = $this->product();
        $location = $this->locations()->first();
        $original = $this->confirm(
            InventoryMovementType::Receipt,
            $product,
            destination: $location
        );
        $replacement = $this->draft(
            InventoryMovementType::Receipt,
            $product,
            destination: $location
        );

        try {
            app(InventoryMovementCorrector::class)->correct(
                $original,
                $replacement,
                $this->user(UserRole::Viewer),
                'Intento sin autorización',
                'viewer-attempt'
            );
            $this->fail('Un usuario de consulta corrigió el libro.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            InventoryMovementStatus::Draft,
            $replacement->refresh()->status
        );
        $this->assertSame(
            '1.000000',
            $this->balance($product, $location)->quantity
        );
    }

    private function confirm(
        InventoryMovementType $type,
        CatalogProduct $product,
        ?InventoryLocation $source = null,
        ?InventoryLocation $destination = null,
        string $quantity = '1',
        string $reason = 'Movimiento original'
    ): InventoryMovement {
        $movement = $this->draft(
            $type,
            $product,
            $source,
            $destination,
            $quantity,
            $reason
        );

        return app(InventoryMovementConfirmer::class)
            ->confirm($movement, $this->admin());
    }

    private function draft(
        InventoryMovementType $type,
        CatalogProduct $product,
        ?InventoryLocation $source = null,
        ?InventoryLocation $destination = null,
        string $quantity = '1',
        string $reason = 'Movimiento de reemplazo'
    ): InventoryMovement {
        $movement = InventoryMovement::query()->create([
            'organization_id' => $this->organization()->id,
            'type' => $type,
            'status' => InventoryMovementStatus::Draft,
            'created_by_user_id' => $this->admin()->id,
            'effective_at' => now(),
            'reason' => $reason,
            'idempotency_key' => 'correction-test:'.Str::uuid(),
        ]);

        InventoryMovementLine::query()->create([
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

        return $movement;
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
                ['slug' => 'movement-correction'],
                [
                    'name' => 'Movement Correction',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->firstOrCreate(
                ['sku' => 'MOVEMENT-CORRECTION'],
                [
                    'product_category_id' => $category->id,
                    'name' => 'Producto corregible',
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
