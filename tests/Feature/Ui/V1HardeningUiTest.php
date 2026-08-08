<?php

namespace Tests\Feature\Ui;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class V1HardeningUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_active_membership_role_is_displayed_and_product_edit_opens(): void
    {
        $organization = $this->organization();
        $operator = User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);
        $operator->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $operator->id,
                ],
                [
                    'role' => UserRole::Operator,
                    'active' => true,
                ]
            )
        );

        $product = $this->product(
            'Producto editable hardening',
            'EDIT-HARDENING'
        );

        $this->actingAs($operator)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('Editar producto')
            ->assertSee('Operador')
            ->assertDontSee('Administrador')
            ->assertSee('Operadores: solo lectura')
            ->assertDontSee('Actualizar precio ARS');
    }

    public function test_availability_hides_exact_zero_by_default_but_zero_filter_keeps_it_auditable(): void
    {
        $organization = $this->organization();
        $operator = $this->operator($organization);
        $product = $this->product(
            'Producto cero visible por filtro',
            'ZERO-HARDENING'
        );
        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->active()
            ->orderBy('id')
            ->firstOrFail();

        $this->movement(
            $operator,
            $product,
            $location,
            InventoryMovementType::Receipt,
            '1'
        );
        $this->movement(
            $operator,
            $product,
            $location,
            InventoryMovementType::Issue,
            '1'
        );

        $this->actingAs($operator)
            ->get(route('inventory-availability.index'))
            ->assertOk()
            ->assertDontSee($product->name);

        $this->actingAs($operator)
            ->get(route('inventory-availability.index', [
                'status' => 'zero',
            ]))
            ->assertOk()
            ->assertSee($product->name);
    }

    private function movement(
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        InventoryMovementType $type,
        string $quantity
    ): void {
        $line = $type === InventoryMovementType::Receipt
            ? new InventoryMovementLineData(
                catalogProductId: $product->id,
                condition: InventoryCondition::New,
                enteredQuantity: $quantity,
                enteredUnitCode: $product->base_unit_code,
                destinationLocationId: $location->id
            )
            : new InventoryMovementLineData(
                catalogProductId: $product->id,
                condition: InventoryCondition::New,
                enteredQuantity: $quantity,
                enteredUnitCode: $product->base_unit_code,
                sourceLocationId: $location->id
            );

        $movement = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: $type,
                effectiveAt: CarbonImmutable::now(),
                reason: 'Prueba UX hardening.',
                idempotencyKey: 'hardening:ui:'.Str::uuid(),
                lines: [$line]
            ),
            $actor
        );

        app(InventoryMovementConfirmer::class)->confirm(
            $movement,
            $actor
        );
    }

    private function product(string $name, string $sku): CatalogProduct
    {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'hardening-ui-tests'],
                [
                    'name' => 'Hardening UI tests',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'active' => true,
            ])->refresh()
        );
    }

    private function operator(Organization $organization): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Operator,
            'email_verified_at' => now(),
        ]);
        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => UserRole::Operator,
                    'active' => true,
                ]
            )
        );

        return $user;
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }
}
