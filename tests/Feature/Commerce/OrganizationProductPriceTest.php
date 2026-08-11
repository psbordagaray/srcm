<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\OrganizationProductPriceManager;
use App\Domain\Commerce\OrganizationProductPriceReader;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Enums\CommercePaymentMethod;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\FinancialAccount;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationProductPrice;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationProductPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_sets_private_versioned_price_and_operator_cannot_change_it(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product('Cable USB-C precio', 'PRICE-USB-C');
        $manager = app(OrganizationProductPriceManager::class);

        $first = $manager->set(
            $product,
            'ARS',
            100000,
            'Precio inicial',
            $admin
        );

        $second = $manager->set(
            $product,
            'ARS',
            125000,
            'Ajuste de lista',
            $admin
        );

        $this->assertNull($first->fresh()->is_current);
        $this->assertNotNull($first->fresh()->valid_until);
        $this->assertTrue($second->is_current);
        $this->assertSame(125000, $second->amount_minor);
        $this->assertDatabaseCount('organization_product_prices', 2);

        $this->expectException(\DomainException::class);

        $manager->set(
            $product,
            'ARS',
            90000,
            'Intento de operador',
            $operator
        );
    }

    public function test_price_http_route_is_admin_only(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product('Precio HTTP', 'PRICE-HTTP');

        $this->actingAs($operator)
            ->put(route('organization-product-prices.update', $product), [
                'currency_code' => 'ARS',
                'amount' => '1234,50',
                'reason' => 'Intento sin autoridad',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('organization_product_prices', 0);

        $this->actingAs($admin)
            ->put(route('organization-product-prices.update', $product), [
                'currency_code' => 'ARS',
                'amount' => '1234,50',
                'reason' => 'Lista inicial',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('organization_product_prices', [
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'currency_code' => 'ARS',
            'amount_minor' => 123450,
            'is_current' => true,
        ]);
    }

    public function test_price_is_tenant_private(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Cargador privado', 'PRICE-PRIVATE');
        $foreign = Organization::query()->create([
            'name' => 'Comercio extranjero precio',
            'slug' => 'comercio-extranjero-precio',
            'active' => true,
        ]);
        $foreignAdmin = $this->user($foreign, UserRole::Admin);

        $manager = app(OrganizationProductPriceManager::class);
        $manager->set($product, 'ARS', 500000, null, $admin);
        $manager->set($product, 'ARS', 650000, null, $foreignAdmin);

        $reader = app(OrganizationProductPriceReader::class);

        $this->assertSame(
            500000,
            $reader->amountAt(
                $organization->id,
                $product->id,
                'ARS',
                CarbonImmutable::now()
            )
        );
        $this->assertSame(
            650000,
            $reader->amountAt(
                $foreign->id,
                $product->id,
                'ARS',
                CarbonImmutable::now()
            )
        );
    }

    public function test_http_sale_ignores_forged_unit_price_and_uses_server_price(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product('Cable blindado', 'PRICE-FORGE');
        $location = $this->location($organization);
        $account = $this->financialAccount(
            $organization,
            $admin
        );

        app(OrganizationProductPriceManager::class)->set(
            $product,
            'ARS',
            1430000,
            'Precio de lista',
            $admin
        );

        $this->seedStock($operator, $product, $location, '2');

        $this->actingAs($operator)
            ->post(route('commerce-sales.store'), [
                'currency_code' => 'ARS',
                'service_order_id' => null,
                'customer_business_party_id' => null,
                'customer_name' => 'Consumidor final',
                'product_lines' => [[
                    'catalog_product_id' => $product->id,
                    'source_location_id' => $location->id,
                    'condition' => InventoryCondition::New->value,
                    'quantity' => '1',
                    // Debe ser completamente ignorado.
                    'unit_price' => '1,00',
                ]],
                'payments' => [[
                    'method' => CommercePaymentMethod::Cash->value,
                    'financial_account_id' => $account->id,
                    'amount' => '14300,00',
                    'reference' => null,
                    'notes' => null,
                    'paid_at' => null,
                ]],
                'idempotency_key' =>
                    'service-ui:commerce-sale:'.Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $line = \App\Models\CommerceSaleLine::query()->sole();

        $this->assertSame($product->id, $line->catalog_product_id);
        $this->assertSame(1430000, $line->unit_price_minor);
        $this->assertSame(1430000, $line->line_total_minor);
        $this->assertNotNull($line->organization_product_price_id);
        $this->assertDatabaseHas('organization_product_prices', [
            'id' => $line->organization_product_price_id,
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'currency_code' => 'ARS',
            'amount_minor' => 1430000,
        ]);
    }

    public function test_product_sale_without_private_price_is_blocked(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product('Sin precio', 'NO-PRICE');
        $location = $this->location($organization);
        $account = $this->financialAccount(
            $organization,
            $admin
        );

        $this->seedStock($operator, $product, $location, '1');

        $this->actingAs($operator)
            ->post(route('commerce-sales.store'), [
                'currency_code' => 'ARS',
                'product_lines' => [[
                    'catalog_product_id' => $product->id,
                    'source_location_id' => $location->id,
                    'condition' => InventoryCondition::New->value,
                    'quantity' => '1',
                    'unit_price' => '99999',
                ]],
                'payments' => [[
                    'method' => CommercePaymentMethod::Cash->value,
                    'financial_account_id' => $account->id,
                    'amount' => '99999',
                    'reference' => null,
                ]],
                'idempotency_key' =>
                    'service-ui:commerce-sale:'.Str::uuid(),
            ])
            ->assertSessionHasErrors('commerce');

        $this->assertDatabaseCount('commerce_sales', 0);
    }

    private function seedStock(
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity
    ): void {
        $movement = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: CarbonImmutable::now(),
                reason: 'Stock para prueba de precio.',
                idempotencyKey: 'price:test:stock:'.$product->id.':'.Str::uuid(),
                lines: [new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: InventoryCondition::New,
                    enteredQuantity: $quantity,
                    enteredUnitCode: $product->base_unit_code,
                    destinationLocationId: $location->id
                )]
            ),
            $actor
        );

        app(InventoryMovementConfirmer::class)->confirm(
            $movement,
            $actor
        );
    }

    private function product(
        string $name,
        string $sku
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'organization-price-tests'],
                [
                    'name' => 'Precios privados de prueba',
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

    private function financialAccount(
        Organization $organization,
        User $creator
    ): FinancialAccount {
        $suffix = Str::lower(Str::random(8));

        return FinancialAccount::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Caja de precios '.$suffix,
            'normalized_name' => 'cajadeprecios'.$suffix,
            'type' => FinancialAccountType::CashBox,
            'currency_code' => 'ARS',
            'active' => true,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);
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
            ->forOrganization($organization->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role,
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                ['role' => $role, 'active' => true]
            )
        );

        return $user;
    }
}
