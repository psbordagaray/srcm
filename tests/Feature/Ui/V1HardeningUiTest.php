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

    public function test_sale_pos_uses_single_product_composer_compact_cart_and_collapsible_context(): void
    {
        $organization = $this->organization();
        $operator = $this->operator($organization);

        $response = $this->actingAs($operator)
            ->get(route('commerce-sales.create'))
            ->assertOk()
            ->assertSee('Orden a liquidar · Moneda')
            ->assertSee('Cliente y referencia')
            ->assertSee('Productos de la venta')
            ->assertSee('Artículo → condición disponible → ubicación disponible → cantidad.')
            ->assertSee('Carrito')
            ->assertSee('Scroll interno para ventas extensas')
            ->assertSee('Abrir cobro');

        $content = $response->getContent();

        $this->assertSame(
            1,
            substr_count($content, 'data-sale-product-composer')
        );
        $this->assertSame(
            1,
            substr_count($content, 'data-sale-product-funnel')
        );

        foreach ([
            'addOrUpdateProduct()',
            'editProduct(index)',
            'removeProduct(index)',
            'max-h-[22rem] overflow-auto',
            'product_lines[${index}][catalog_product_id]',
            'productSearchIndex:',
            'conditionOptions()',
            'locationOptions()',
            'companyAvailableAllConditions(',
            'cartCommittedAt(',
            'resolveConditionSelection()',
            'resolveLocationSelection()',
        ] as $marker) {
            $this->assertStringContainsString(
                $marker,
                $content
            );
        }

        foreach ([
            'Productos agregados',
            '@click="addProduct()"',
            'refreshLocationOptions($el, draftProduct)',
            'data-location-id="',
            'data-location-name="',
        ] as $legacyMarker) {
            $this->assertStringNotContainsString(
                $legacyMarker,
                $content
            );
        }
    }
    public function test_sale_lookup_index_exposes_registered_identifiers_and_guided_funnel(): void
    {
        $organization = $this->organization();
        $operator = $this->operator($organization);
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'lookup-ui-tests'],
                [
                    'name' => 'Lookup UI tests',
                    'active' => true,
                ]
            )
        );

        $product = app(
            \App\Domain\Knowledge\CatalogProductKnowledgeManager::class
        )->create([
            'product_category_id' => $category->id,
            'brand_id' => null,
            'manufacturer_id' => null,
            'sku' => 'LOOKUP-USB-C',
            'name' => 'Cable buscable por código interno',
            'description' => 'Cable de prueba para búsqueda rápida.',
            'active' => true,
        ]);

        $identifierType = \App\Models\IdentifierType::query()
            ->firstOrCreate(
                ['slug' => 'internal-code'],
                [
                    'name' => 'Código interno',
                    'description' =>
                        'Código rápido usado en mostrador.',
                    'is_unique' => true,
                    'active' => true,
                ]
            );

        $product->knowledgeEntity
            ->identifiers()
            ->create([
                'identifier_type_id' => $identifierType->id,
                'value' => '1104',
                'is_primary' => false,
                'active' => true,
            ]);

        $response = $this->actingAs($operator)
            ->get(route('commerce-sales.create'))
            ->assertOk()
            ->assertSee('SKU, código interno, descripción, marca, modelo…')
            ->assertSee('Artículo → condición disponible → ubicación disponible → cantidad.')
            ->assertSee('1104')
            ->assertSee('Código interno');

        $content = $response->getContent();

        foreach ([
            'productSearchIndex:',
            'commitArticleSearch()',
            'conditionOptions()',
            'locationOptions()',
            'companyAvailableAllConditions(product.id)',
            'cartCommittedAt(',
            'resolveConditionSelection()',
            'resolveLocationSelection()',
            'data-sale-product-funnel',
        ] as $marker) {
            $this->assertStringContainsString(
                $marker,
                $content
            );
        }

        $this->assertStringContainsString(
            "condition: '',",
            $content
        );
        $this->assertStringContainsString(
            "quantity: ''",
            $content
        );
        $this->assertStringNotContainsString(
            "condition: 'new',",
            $content
        );
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
