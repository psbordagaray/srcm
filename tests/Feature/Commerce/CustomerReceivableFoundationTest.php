<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Enums\CommercePaymentMethod;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerReceivable;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationProductPrice;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerReceivableFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_authority_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns(
            'customer_receivables',
            [
                'organization_id',
                'public_id',
                'business_party_id',
                'commerce_sale_id',
                'currency_code',
                'amount_minor',
                'due_on',
                'idempotency_key',
                'fingerprint',
                'recognized_by_user_id',
                'recognized_at',
            ]
        ));

        $this->assertTrue(
            UserRole::Admin->canCreateCustomerReceivable()
        );
        $this->assertTrue(
            UserRole::Operator->canCreateCustomerReceivable()
        );
        $this->assertFalse(
            UserRole::Viewer->canCreateCustomerReceivable()
        );
    }

    public function test_admin_confirms_full_credit_sale_idempotently(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $customer = $this->customer(
            $organization,
            'Cliente cuenta completa'
        );
        $location = $this->location($organization);
        $product = $this->product(
            'Producto cuenta completa',
            'CXC-FULL'
        );

        $this->seedStock(
            $admin,
            $product,
            $location,
            '2'
        );

        $data = new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey: 'commerce:cxc:full',
            payments: [],
            receivableAmountMinor: 1_000_000,
            receivableDueOn: CarbonImmutable::now()
                ->addDays(30),
            productLines: [new CommerceProductLineData(
                $product->id,
                $location->id,
                InventoryCondition::New,
                '1',
                1_000_000
            )],
            customerBusinessPartyId: $customer->id
        );

        $sale = app(CommerceCheckoutManager::class)
            ->checkout($data, $admin);
        $retry = app(CommerceCheckoutManager::class)
            ->checkout($data, $admin);

        $this->assertSame($sale->id, $retry->id);
        $this->assertCount(0, $sale->payments);
        $this->assertNotNull($sale->receivable);
        $this->assertSame(
            1_000_000,
            $sale->receivable->amount_minor
        );
        $this->assertSame(
            $customer->id,
            $sale->receivable->business_party_id
        );
        $this->assertSame(
            $sale->id,
            $sale->receivable->commerce_sale_id
        );
        $this->assertDatabaseCount('customer_receivables', 1);
        $this->assertDatabaseCount('commerce_sales', 1);
    }

    public function test_split_payment_and_receivable_cancel_exact_total(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $customer = $this->customer(
            $organization,
            'Cliente cuenta parcial'
        );
        $location = $this->location($organization);
        $product = $this->product(
            'Producto cuenta parcial',
            'CXC-SPLIT'
        );

        $this->seedStock(
            $admin,
            $product,
            $location,
            '1'
        );

        $sale = app(CommerceCheckoutManager::class)->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey: 'commerce:cxc:split',
                payments: [new CommercePaymentData(
                    CommercePaymentMethod::BankTransfer,
                    400_000,
                    'TRANSFER-CXC-SPLIT'
                )],
                receivableAmountMinor: 600_000,
                productLines: [new CommerceProductLineData(
                    $product->id,
                    $location->id,
                    InventoryCondition::New,
                    '1',
                    1_000_000
                )],
                customerBusinessPartyId: $customer->id
            ),
            $admin
        );

        $this->assertSame(
            400_000,
            $sale->payments->sum('amount_minor')
        );
        $this->assertSame(
            600_000,
            $sale->receivable->amount_minor
        );
        $this->assertSame(
            1_000_000,
            $sale->payments->sum('amount_minor')
                + $sale->receivable->amount_minor
        );
    }

    public function test_operator_credit_sale_fails_before_stock_effect(): void
    {
        $organization = $this->organization();
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $customer = $this->customer(
            $organization,
            'Cliente sin autorización'
        );
        $location = $this->location($organization);
        $product = $this->product(
            'Producto sin autorización',
            'CXC-OPERATOR'
        );

        $this->seedStock(
            $operator,
            $product,
            $location,
            '1'
        );

        $movementCount = DB::table('inventory_movements')->count();

        $this->assertDomainFailure(
            fn () => app(CommerceCheckoutManager::class)->checkout(
                new CommerceCheckoutData(
                    currencyCode: 'ARS',
                    idempotencyKey: 'commerce:cxc:operator',
                    payments: [],
                    receivableAmountMinor: 1_000_000,
                    productLines: [new CommerceProductLineData(
                        $product->id,
                        $location->id,
                        InventoryCondition::New,
                        '1',
                        1_000_000
                    )],
                    customerBusinessPartyId: $customer->id
                ),
                $operator
            )
        );

        $this->assertDatabaseCount('commerce_sales', 0);
        $this->assertDatabaseCount('customer_receivables', 0);
        $this->assertSame(
            $movementCount,
            DB::table('inventory_movements')->count()
        );
    }

    public function test_database_rejects_forgery_and_receivable_is_immutable(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $customer = $this->customer(
            $organization,
            'Cliente guardas'
        );
        $location = $this->location($organization);
        $product = $this->product('Producto guardas', 'CXC-DB');

        $this->seedStock($admin, $product, $location, '1');

        $sale = app(CommerceCheckoutManager::class)->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey: 'commerce:cxc:db',
                payments: [],
                receivableAmountMinor: 1_000_000,
                productLines: [new CommerceProductLineData(
                    $product->id,
                    $location->id,
                    InventoryCondition::New,
                    '1',
                    1_000_000
                )],
                customerBusinessPartyId: $customer->id
            ),
            $admin
        );

        $receivable = $sale->receivable;

        $this->assertDomainFailure(
            fn () => $receivable->update([
                'amount_minor' => 999_999,
            ])
        );

        $this->assertDomainFailure(
            fn () => $receivable->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table('customer_receivables')->insert([
                'organization_id' => $organization->id,
                'public_id' => (string) Str::uuid(),
                'business_party_id' => $customer->id,
                'commerce_sale_id' => $sale->id,
                'currency_code' => 'ARS',
                'amount_minor' => 1,
                'due_on' => null,
                'idempotency_key' => 'forged:'.Str::uuid(),
                'fingerprint' => str_repeat('a', 64),
                'recognized_by_user_id' => $admin->id,
                'recognized_at' => now(),
                'created_at' => now(),
            ])
        );
    }

    public function test_http_exposes_admin_credit_sale_and_rejects_operator(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $customer = $this->customer(
            $organization,
            'Cliente HTTP'
        );
        $location = $this->location($organization);
        $product = $this->product('Producto HTTP', 'CXC-HTTP');

        $this->seedStock($admin, $product, $location, '2');
        $this->price(
            $organization,
            $admin,
            $product,
            1_000_000
        );

        $this->actingAs($admin)
            ->get(route('commerce-sales.create'))
            ->assertOk()
            ->assertSee('Saldo pendiente / cuenta corriente')
            ->assertSee('name="receivable_amount"', false);

        $this->actingAs($operator)
            ->get(route('commerce-sales.create'))
            ->assertOk()
            ->assertSee('name="receivable_amount"', false);

        $payload = [
            'currency_code' => 'ARS',
            'customer_business_party_id' => $customer->id,
            'product_lines' => [[
                'catalog_product_id' => $product->id,
                'source_location_id' => $location->id,
                'condition' => InventoryCondition::New->value,
                'quantity' => '1',
            ]],
            'payments' => [],
            'receivable_amount' => '10000.00',
            'receivable_due_on' => CarbonImmutable::now()
                ->addDays(30)
                ->toDateString(),
            'idempotency_key' =>
                'service-ui:commerce-sale:'.Str::uuid(),
        ];

        $this->actingAs($admin)
            ->post(route('commerce-sales.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('commerce_sales', 1);
        $this->assertDatabaseCount('commerce_payments', 0);
        $this->assertDatabaseCount('customer_receivables', 1);

        $operatorPayload = $payload;
        $operatorPayload['idempotency_key'] =
            'service-ui:commerce-sale:'.Str::uuid();

        $this->actingAs($operator)
            ->from(route('commerce-sales.create'))
            ->post(
                route('commerce-sales.store'),
                $operatorPayload
            )
            ->assertRedirect(route('commerce-sales.create'))
            ->assertSessionHasErrors('commerce');

        $this->assertDatabaseCount('commerce_sales', 1);
        $this->assertDatabaseCount('customer_receivables', 1);
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

    private function customer(
        Organization $organization,
        string $name
    ): BusinessParty {
        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_PERSON,
            'name' => $name,
        ]);

        Customer::withoutEvents(
            fn () => Customer::query()->create([
                'organization_id' => $organization->id,
                'business_party_id' => $party->id,
                'active' => true,
            ])
        );

        return $party->refresh();
    }

    private function product(
        string $name,
        string $sku
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'p9-cxc-tests'],
                ['name' => 'P9 CxC tests', 'active' => true]
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
                [
                    'role' => $role,
                    'active' => true,
                ]
            )
        );

        return $user;
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
                reason: 'Ingreso P9.1 para prueba CxC.',
                idempotencyKey: 'p9:cxc:stock:'.$product->id,
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

        app(InventoryMovementConfirmer::class)
            ->confirm($movement, $actor);
    }

    private function price(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        int $amountMinor
    ): void {
        OrganizationProductPrice::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'currency_code' => 'ARS',
            'amount_minor' => $amountMinor,
            'valid_from' => CarbonImmutable::now()
                ->subMinute(),
            'valid_until' => null,
            'is_current' => true,
            'reason' => 'Precio P9.1.',
            'created_by_user_id' => $actor->id,
        ]);
    }

    private function assertDomainFailure(
        callable $callback
    ): void {
        try {
            $callback();
            $this->fail(
                'Se esperaba una excepción de dominio.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertQueryRejected(
        callable $callback
    ): void {
        try {
            $callback();
            $this->fail(
                'La base de datos aceptó una operación inválida.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
