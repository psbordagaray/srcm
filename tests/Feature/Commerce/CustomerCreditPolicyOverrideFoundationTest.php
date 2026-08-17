<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Commerce\CustomerCollectionAllocationData;
use App\Domain\Commerce\CustomerCollectionData;
use App\Domain\Commerce\CustomerCollectionManager;
use App\Domain\Commerce\CustomerCreditExposureReader;
use App\Domain\Commerce\CustomerCreditPolicyManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Enums\CommercePaymentMethod;
use App\Enums\CustomerCreditDecisionType;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerCreditOverride;
use App\Models\CustomerCreditPolicy;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerCreditPolicyOverrideFoundationTest extends TestCase
{
    use RefreshDatabase;

    private int $productSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-08-17 12:00:00'
            )
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_schema_roles_and_routes_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasTable('customer_credit_policies')
        );
        $this->assertTrue(
            Schema::hasTable('customer_credit_overrides')
        );
        $this->assertTrue(
            Schema::hasColumns(
                'customer_receivables',
                [
                    'customer_credit_policy_id',
                    'customer_credit_override_id',
                    'credit_decision',
                    'credit_limit_minor',
                    'credit_exposure_before_minor',
                    'credit_projected_exposure_minor',
                    'credit_overdue_minor',
                    'credit_oldest_days_overdue',
                    'credit_snapshot_fingerprint',
                ]
            )
        );

        $this->assertTrue(
            UserRole::Admin
                ->canCreateCustomerReceivable()
        );
        $this->assertTrue(
            UserRole::Operator
                ->canCreateCustomerReceivable()
        );
        $this->assertFalse(
            UserRole::Viewer
                ->canCreateCustomerReceivable()
        );

        $this->assertTrue(
            UserRole::Admin
                ->canManageCustomerCreditPolicy()
        );
        $this->assertFalse(
            UserRole::Operator
                ->canManageCustomerCreditPolicy()
        );
        $this->assertTrue(
            UserRole::Admin
                ->canOverrideCustomerCredit()
        );
        $this->assertFalse(
            UserRole::Operator
                ->canOverrideCustomerCredit()
        );

        $this->assertTrue(
            Route::has('customers.credit-policies.store')
        );
    }

    public function test_admin_versions_policy_idempotently_and_facts_are_immutable(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente política versionada'
        );

        $manager = app(
            CustomerCreditPolicyManager::class
        );

        $first = $manager->setLimit(
            $customer,
            'ARS',
            1_000_000,
            'Alta inicial de cupo.',
            'p9.4:policy:first',
            $admin
        );

        $retry = $manager->setLimit(
            $customer,
            'ARS',
            1_000_000,
            'Alta inicial de cupo.',
            'p9.4:policy:first',
            $admin
        );

        $second = $manager->setLimit(
            $customer,
            'ARS',
            1_500_000,
            'Aumento por historial comercial.',
            'p9.4:policy:second',
            $admin
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame(
            1_500_000,
            $second->limit_minor
        );
        $this->assertDatabaseCount(
            'customer_credit_policies',
            2
        );

        $snapshot = app(
            CustomerCreditExposureReader::class
        )->snapshot(
            $customer,
            'ARS',
            $admin
        );

        $this->assertSame(
            $second->id,
            $snapshot['policy']->id
        );
        $this->assertSame(
            1_500_000,
            $snapshot['limit_minor']
        );

        $this->assertDomainFailure(
            fn () => $second->update([
                'limit_minor' => 1,
            ])
        );

        $this->assertDomainFailure(
            fn () => $second->delete()
        );
    }

    public function test_operator_can_sell_credit_inside_configured_policy(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $customer = $this->customer(
            $organization,
            'Cliente dentro de política'
        );
        $product = $this->product(
            'Producto dentro de política'
        );
        $location = $this->location($organization);

        $this->policy(
            $customer,
            $admin,
            2_000_000,
            'p9.4:within:policy'
        );

        $this->seedStockAt(
            $operator,
            $product,
            $location,
            '1',
            CarbonImmutable::now()->subMinute()
        );

        $sale = $this->creditSale(
            $operator,
            $customer,
            $product,
            $location,
            1_000_000,
            CarbonImmutable::now()->addDays(30),
            'p9.4:within:sale'
        );

        $receivable = $sale->receivable;

        $this->assertSame(
            CustomerCreditDecisionType::WithinPolicy,
            $receivable->credit_decision
        );
        $this->assertNotNull(
            $receivable->customer_credit_policy_id
        );
        $this->assertNull(
            $receivable->customer_credit_override_id
        );
        $this->assertSame(
            2_000_000,
            $receivable->credit_limit_minor
        );
        $this->assertSame(
            0,
            $receivable->credit_exposure_before_minor
        );
        $this->assertSame(
            1_000_000,
            $receivable->credit_projected_exposure_minor
        );
        $this->assertSame(
            0,
            $receivable->credit_overdue_minor
        );
        $this->assertDatabaseCount(
            'customer_credit_overrides',
            0
        );
    }

    public function test_over_limit_blocks_operator_and_admin_requires_reasoned_override(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $customer = $this->customer(
            $organization,
            'Cliente sobre límite'
        );
        $product = $this->product(
            'Producto sobre límite'
        );
        $location = $this->location($organization);

        $this->policy(
            $customer,
            $admin,
            500_000,
            'p9.4:over:policy'
        );

        $this->seedStockAt(
            $admin,
            $product,
            $location,
            '1',
            CarbonImmutable::now()->subMinute()
        );

        $movementCount = DB::table(
            'inventory_movements'
        )->count();

        $this->assertDomainFailure(
            fn () => $this->creditSale(
                $operator,
                $customer,
                $product,
                $location,
                600_000,
                CarbonImmutable::now()->addDays(30),
                'p9.4:over:operator'
            )
        );

        $this->assertSame(
            $movementCount,
            DB::table('inventory_movements')->count()
        );

        $this->assertDomainFailure(
            fn () => $this->creditSale(
                $admin,
                $customer,
                $product,
                $location,
                600_000,
                CarbonImmutable::now()->addDays(30),
                'p9.4:over:admin:no-reason'
            )
        );

        $sale = $this->creditSale(
            $admin,
            $customer,
            $product,
            $location,
            600_000,
            CarbonImmutable::now()->addDays(30),
            'p9.4:over:admin:override',
            null,
            'Autorizo por operación comercial excepcional.'
        );

        $override = CustomerCreditOverride::query()
            ->sole();

        $this->assertSame(
            $sale->id,
            $override->commerce_sale_id
        );
        $this->assertTrue($override->over_limit);
        $this->assertFalse($override->overdue);
        $this->assertSame(
            'Autorizo por operación comercial excepcional.',
            $override->reason
        );
        $this->assertSame(
            $admin->id,
            $override->approved_by_user_id
        );
        $this->assertSame(
            $override->id,
            $sale->receivable
                ->customer_credit_override_id
        );
        $this->assertSame(
            CustomerCreditDecisionType::AdminOverride,
            $sale->receivable->credit_decision
        );
    }

    public function test_overdue_debt_blocks_operator_even_with_available_limit_and_admin_may_override(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $customer = $this->customer(
            $organization,
            'Cliente deuda vencida'
        );
        $location = $this->location($organization);
        $firstProduct = $this->product(
            'Producto deuda vencida'
        );
        $secondProduct = $this->product(
            'Producto nuevo crédito'
        );

        $this->policy(
            $customer,
            $admin,
            2_000_000,
            'p9.4:overdue:policy'
        );

        $historicalSaleAt = CarbonImmutable::parse(
            '2026-07-01 10:00:00'
        );

        $this->seedStockAt(
            $admin,
            $firstProduct,
            $location,
            '1',
            $historicalSaleAt->subDay()
        );

        $this->creditSale(
            $admin,
            $customer,
            $firstProduct,
            $location,
            500_000,
            CarbonImmutable::parse('2026-07-15'),
            'p9.4:overdue:old',
            $historicalSaleAt
        );

        $this->seedStockAt(
            $admin,
            $secondProduct,
            $location,
            '1',
            CarbonImmutable::now()->subMinute()
        );

        $this->assertDomainFailure(
            fn () => $this->creditSale(
                $operator,
                $customer,
                $secondProduct,
                $location,
                100_000,
                CarbonImmutable::now()->addDays(30),
                'p9.4:overdue:operator'
            )
        );

        $this->assertDomainFailure(
            fn () => $this->creditSale(
                $admin,
                $customer,
                $secondProduct,
                $location,
                100_000,
                CarbonImmutable::now()->addDays(30),
                'p9.4:overdue:admin:no-reason'
            )
        );

        $sale = $this->creditSale(
            $admin,
            $customer,
            $secondProduct,
            $location,
            100_000,
            CarbonImmutable::now()->addDays(30),
            'p9.4:overdue:admin:override',
            null,
            'Autorizo nuevo crédito pese a deuda vencida.'
        );

        $override = $sale->receivable
            ->creditOverride;

        $this->assertNotNull($override);
        $this->assertFalse($override->over_limit);
        $this->assertTrue($override->overdue);
        $this->assertSame(
            500_000,
            $override->overdue_minor
        );
        $this->assertGreaterThan(
            0,
            $override->oldest_days_overdue
        );
    }

    public function test_collection_reduces_exposure_and_restores_operator_credit_path(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $customer = $this->customer(
            $organization,
            'Cliente rehabilitado por cobranza'
        );
        $location = $this->location($organization);
        $firstProduct = $this->product(
            'Producto exposición previa'
        );
        $secondProduct = $this->product(
            'Producto exposición posterior'
        );

        $this->policy(
            $customer,
            $admin,
            1_000_000,
            'p9.4:collection:policy'
        );

        $oldSaleAt = CarbonImmutable::parse(
            '2026-07-01 10:00:00'
        );

        $this->seedStockAt(
            $admin,
            $firstProduct,
            $location,
            '1',
            $oldSaleAt->subDay()
        );

        $oldSale = $this->creditSale(
            $admin,
            $customer,
            $firstProduct,
            $location,
            700_000,
            CarbonImmutable::parse('2026-07-15'),
            'p9.4:collection:old',
            $oldSaleAt
        );

        $bank = app(FinancialAccountManager::class)
            ->create(
                'Banco P9.4',
                FinancialAccountType::BankAccount,
                'ARS',
                $admin
            );

        app(CustomerCollectionManager::class)->collect(
            $customer,
            new CustomerCollectionData(
                currencyCode: 'ARS',
                method: CommercePaymentMethod::BankTransfer,
                amountMinor: 700_000,
                financialAccountId: $bank->id,
                allocations: [
                    new CustomerCollectionAllocationData(
                        $oldSale->receivable->id,
                        700_000
                    ),
                ],
                idempotencyKey:
                    'p9.4:collection:settle',
                reference: 'P94-COLLECTION'
            ),
            $operator
        );

        $snapshot = app(
            CustomerCreditExposureReader::class
        )->snapshot(
            $customer,
            'ARS',
            $operator
        );

        $this->assertSame(
            0,
            $snapshot['exposure_minor']
        );
        $this->assertSame(
            0,
            $snapshot['overdue_minor']
        );
        $this->assertSame(
            1_000_000,
            $snapshot['available_minor']
        );

        $this->seedStockAt(
            $admin,
            $secondProduct,
            $location,
            '1',
            CarbonImmutable::now()->subMinute()
        );

        $sale = $this->creditSale(
            $operator,
            $customer,
            $secondProduct,
            $location,
            500_000,
            CarbonImmutable::now()->addDays(30),
            'p9.4:collection:new'
        );

        $this->assertSame(
            CustomerCreditDecisionType::WithinPolicy,
            $sale->receivable->credit_decision
        );
    }

    public function test_database_and_models_reject_forged_policy_override_and_credit_evidence(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente guardas P9.4'
        );

        $policy = $this->policy(
            $customer,
            $admin,
            500_000,
            'p9.4:guards:policy'
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_credit_policies'
            )->insert([
                'organization_id' => $organization->id,
                'public_id' => (string) Str::uuid(),
                'business_party_id' =>
                    $customer->business_party_id,
                'currency_code' => 'ARS',
                'version' => 99,
                'limit_minor' => 1,
                'reason' => 'Forjado.',
                'idempotency_key' =>
                    'p9.4:guards:forged-policy',
                'fingerprint' => str_repeat('a', 64),
                'set_by_user_id' => $admin->id,
                'set_at' => now(),
                'created_at' => now(),
            ])
        );

        $product = $this->product(
            'Producto guardas P9.4'
        );
        $location = $this->location($organization);

        $this->seedStockAt(
            $admin,
            $product,
            $location,
            '1',
            CarbonImmutable::now()->subMinute()
        );

        $sale = $this->creditSale(
            $admin,
            $customer,
            $product,
            $location,
            600_000,
            CarbonImmutable::now()->addDays(30),
            'p9.4:guards:override',
            null,
            'Autorización de prueba para guardas.'
        );

        $override = CustomerCreditOverride::query()
            ->sole();

        $this->assertDomainFailure(
            fn () => $override->update([
                'reason' => 'Manipulado',
            ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_credit_overrides'
            )
                ->where('id', $override->id)
                ->update([
                    'reason' => 'Manipulado',
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_receivables'
            )->insert([
                'organization_id' => $organization->id,
                'public_id' => (string) Str::uuid(),
                'business_party_id' =>
                    $customer->business_party_id,
                'commerce_sale_id' => $sale->id,
                'currency_code' => 'ARS',
                'amount_minor' => 1,
                'due_on' => null,
                'idempotency_key' =>
                    'forged:'.Str::uuid(),
                'fingerprint' => str_repeat('b', 64),
                'recognized_by_user_id' => $admin->id,
                'recognized_at' => now(),
                'created_at' => now(),
                'customer_credit_policy_id' =>
                    $policy->id,
                'customer_credit_override_id' => null,
                'credit_decision' => null,
                'credit_limit_minor' =>
                    $policy->limit_minor,
                'credit_exposure_before_minor' => 0,
                'credit_projected_exposure_minor' => 1,
                'credit_overdue_minor' => 0,
                'credit_oldest_days_overdue' => 0,
                'credit_snapshot_fingerprint' =>
                    str_repeat('c', 64),
            ])
        );
    }

    public function test_http_configures_policy_and_operator_credit_is_controlled(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $customer = $this->customer(
            $organization,
            'Cliente HTTP política'
        );
        $product = $this->product(
            'Producto HTTP política'
        );
        $location = $this->location($organization);

        $this->seedStockAt(
            $admin,
            $product,
            $location,
            '2',
            CarbonImmutable::now()->subMinute()
        );

        $this->price(
            $organization,
            $admin,
            $product,
            1_000_000
        );

        $this->actingAs($admin)
            ->get(
                route('customers.account', $customer)
            )
            ->assertOk()
            ->assertSee('Política de crédito')
            ->assertSee('Modo transitorio');

        $this->actingAs($admin)
            ->post(
                route(
                    'customers.credit-policies.store',
                    $customer
                ),
                [
                    'currency_code' => 'ARS',
                    'limit' => '20000.00',
                    'reason' =>
                        'Cupo comercial inicial.',
                    'idempotency_key' =>
                        'customer-credit-policy-ui:'
                        .Str::uuid(),
                ]
            )
            ->assertRedirect(
                route('customers.account', $customer)
            );

        $this->assertDatabaseCount(
            'customer_credit_policies',
            1
        );

        $this->actingAs($operator)
            ->get(route('commerce-sales.create'))
            ->assertOk()
            ->assertSee(
                'Saldo pendiente / cuenta corriente'
            )
            ->assertSee(
                'name="receivable_amount"',
                false
            )
            ->assertDontSee(
                'name="customer_credit_override_reason"',
                false
            );

        $payload = [
            'currency_code' => 'ARS',
            'customer_business_party_id' =>
                $customer->business_party_id,
            'product_lines' => [[
                'catalog_product_id' => $product->id,
                'source_location_id' => $location->id,
                'condition' =>
                    InventoryCondition::New->value,
                'quantity' => '1',
            ]],
            'payments' => [],
            'receivable_amount' => '10000.00',
            'receivable_due_on' =>
                CarbonImmutable::now()
                    ->addDays(30)
                    ->toDateString(),
            'idempotency_key' =>
                'service-ui:commerce-sale:'
                .Str::uuid(),
        ];

        $this->actingAs($operator)
            ->post(
                route('commerce-sales.store'),
                $payload
            )
            ->assertRedirect();

        $this->assertDatabaseCount(
            'customer_receivables',
            1
        );

        $this->actingAs($admin)
            ->get(route('commerce-sales.create'))
            ->assertOk()
            ->assertSee(
                'name="customer_credit_override_reason"',
                false
            )
            ->assertSee(
                'Excepción de crédito'
            );
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
    ): Customer {
        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' =>
                BusinessParty::TYPE_PERSON,
            'name' => $name,
        ]);

        return Customer::withoutEvents(
            fn () => Customer::query()->create([
                'organization_id' =>
                    $organization->id,
                'business_party_id' => $party->id,
                'active' => true,
            ])->load('party')
        );
    }

    private function product(
        string $name
    ): CatalogProduct {
        $this->productSequence++;

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()
                ->firstOrCreate(
                    ['slug' => 'p9-4-credit-policy-tests'],
                    [
                        'name' => 'P9.4 Credit policy tests',
                        'active' => true,
                    ]
                )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'P94-CREDIT-'
                    .$this->productSequence
                    .'-'
                    .Str::lower(Str::random(6)),
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
            'current_organization_id' =>
                $organization->id,
        ])->saveQuietly();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()
                ->updateOrCreate(
                    [
                        'organization_id' =>
                            $organization->id,
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

    private function policy(
        Customer $customer,
        User $admin,
        int $limitMinor,
        string $idempotency
    ): CustomerCreditPolicy {
        return app(
            CustomerCreditPolicyManager::class
        )->setLimit(
            $customer,
            'ARS',
            $limitMinor,
            'Política P9.4 de prueba.',
            $idempotency,
            $admin
        );
    }

    private function seedStockAt(
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity,
        CarbonImmutable $effectiveAt
    ): void {
        $movement = app(
            InventoryMovementCreator::class
        )->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: $effectiveAt,
                reason:
                    'Ingreso P9.4 para política de crédito.',
                idempotencyKey:
                    'p9.4:stock:'.Str::uuid(),
                lines: [
                    new InventoryMovementLineData(
                        catalogProductId: $product->id,
                        condition:
                            InventoryCondition::New,
                        enteredQuantity: $quantity,
                        enteredUnitCode:
                            $product->base_unit_code,
                        destinationLocationId:
                            $location->id
                    ),
                ]
            ),
            $actor
        );

        app(InventoryMovementConfirmer::class)
            ->confirm($movement, $actor);
    }

    private function creditSale(
        User $actor,
        Customer $customer,
        CatalogProduct $product,
        InventoryLocation $location,
        int $amountMinor,
        CarbonImmutable $dueOn,
        string $idempotency,
        ?CarbonImmutable $soldAt = null,
        ?string $overrideReason = null
    ) {
        return app(
            CommerceCheckoutManager::class
        )->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey: $idempotency,
                payments: [],
                receivableAmountMinor:
                    $amountMinor,
                receivableDueOn: $dueOn,
                productLines: [
                    new CommerceProductLineData(
                        $product->id,
                        $location->id,
                        InventoryCondition::New,
                        '1',
                        $amountMinor
                    ),
                ],
                customerBusinessPartyId:
                    $customer->business_party_id,
                soldAt: $soldAt,
                customerCreditOverrideReason:
                    $overrideReason
            ),
            $actor
        );
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
            'reason' => 'Precio P9.4.',
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
