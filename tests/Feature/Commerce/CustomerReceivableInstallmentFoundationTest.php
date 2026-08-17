<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Commerce\CustomerCollectionAllocationData;
use App\Domain\Commerce\CustomerCollectionData;
use App\Domain\Commerce\CustomerCollectionManager;
use App\Domain\Commerce\CustomerCreditExposureReader;
use App\Domain\Commerce\CustomerCreditPolicyManager;
use App\Domain\Commerce\CustomerReceivableAgingReader;
use App\Domain\Commerce\CustomerReceivableInstallmentScheduleReader;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Enums\CommercePaymentMethod;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerCreditOverride;
use App\Models\CustomerCreditPolicy;
use App\Models\CustomerReceivableInstallment;
use App\Models\CustomerReceivableInstallmentPlan;
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

class CustomerReceivableInstallmentFoundationTest extends TestCase
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

    public function test_schema_strategy_and_card_installments_are_separate_concepts(): void
    {
        $this->assertTrue(
            Schema::hasTable(
                'customer_receivable_installment_plans'
            )
        );
        $this->assertTrue(
            Schema::hasTable(
                'customer_receivable_installments'
            )
        );

        $views = collect(
            DB::select(
                "SELECT name FROM sqlite_master "
                ."WHERE type = 'view'"
            )
        )->pluck('name');

        $this->assertTrue(
            $views->contains(
                'customer_receivable_collection_totals'
            )
        );
        $this->assertTrue(
            $views->contains(
                'customer_receivable_installment_balances'
            )
        );

        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $product = $this->product(
            'Producto tarjeta en cuotas'
        );
        $location = $this->location($organization);

        $this->seedStockAt(
            $admin,
            $product,
            $location,
            '1',
            CarbonImmutable::now()->subMinute()
        );

        $account = app(FinancialAccountManager::class)
            ->create(
                'Tarjetas P9.5',
                FinancialAccountType::BankAccount,
                'ARS',
                $admin
            );

        $sale = app(CommerceCheckoutManager::class)
            ->checkout(
                new CommerceCheckoutData(
                    currencyCode: 'ARS',
                    idempotencyKey:
                        'p9.5:card-installments',
                    payments: [
                        new CommercePaymentData(
                            method:
                                CommercePaymentMethod::CreditCard,
                            amountMinor: 30_000,
                            reference: 'CARD-P95',
                            cardBrand: 'visa',
                            cardNetwork: 'visa',
                            cardLast4: '4242',
                            installments: 3,
                            processor: 'test',
                            externalOperationId:
                                'p95-card-operation',
                            authorizationCode: 'AUTH95',
                            providerStatus: 'approved',
                            financialAccountId:
                                $account->id
                        ),
                    ],
                    productLines: [
                        new CommerceProductLineData(
                            $product->id,
                            $location->id,
                            InventoryCondition::New,
                            '1',
                            30_000
                        ),
                    ]
                ),
                $admin
            );

        $this->assertSame(
            3,
            $sale->payments->sole()->installments
        );
        $this->assertNull($sale->receivable);
        $this->assertDatabaseCount(
            'customer_receivable_installment_plans',
            0
        );
        $this->assertDatabaseCount(
            'customer_receivable_installments',
            0
        );
    }

    public function test_equal_monthly_schedule_puts_residual_cents_in_last_installment(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente cuotas iguales'
        );
        $product = $this->product(
            'Producto cuotas iguales'
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
            10_001,
            CarbonImmutable::parse('2026-08-31'),
            'p9.5:equal-monthly',
            3
        );

        $plan = $sale->receivable
            ->installmentPlan()
            ->firstOrFail();
        $rows = $sale->receivable
            ->installments()
            ->get();

        $this->assertSame(
            CustomerReceivableInstallmentPlan::
                STRATEGY_EQUAL_MONTHLY_FIFO_V1,
            $plan->strategy
        );
        $this->assertSame(3, $plan->installment_count);
        $this->assertSame(
            ['2026-08-31', '2026-09-30', '2026-10-31'],
            $rows
                ->pluck('due_on')
                ->map(
                    fn ($date): string =>
                        $date->toDateString()
                )
                ->all()
        );
        $this->assertSame(
            [3333, 3333, 3335],
            $rows->pluck('amount_minor')->all()
        );
        $this->assertSame(
            10_001,
            (int) $rows->sum('amount_minor')
        );
        $this->assertSame(
            '2026-08-31',
            $sale->receivable->due_on
                ->toDateString()
        );
    }

    public function test_confirmed_collection_is_distributed_fifo_over_oldest_installments(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente FIFO'
        );
        $product = $this->product('Producto FIFO');
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
            10_001,
            CarbonImmutable::parse('2026-08-31'),
            'p9.5:fifo:sale',
            3
        );

        $bank = app(FinancialAccountManager::class)
            ->create(
                'Banco FIFO P9.5',
                FinancialAccountType::BankAccount,
                'ARS',
                $admin
            );

        app(CustomerCollectionManager::class)->collect(
            $customer,
            new CustomerCollectionData(
                currencyCode: 'ARS',
                method:
                    CommercePaymentMethod::BankTransfer,
                amountMinor: 4_000,
                financialAccountId: $bank->id,
                allocations: [
                    new CustomerCollectionAllocationData(
                        $sale->receivable->id,
                        4_000
                    ),
                ],
                idempotencyKey:
                    'p9.5:fifo:collection',
                reference: 'P95-FIFO'
            ),
            $admin
        );

        $rows = app(
            CustomerReceivableInstallmentScheduleReader::class
        )->rowsForReceivable(
            $sale->receivable
        );

        $this->assertSame(
            [3333, 3333, 3335],
            $rows->pluck('original_minor')->all()
        );
        $this->assertSame(
            [3333, 667, 0],
            $rows->pluck('collected_minor')->all()
        );
        $this->assertSame(
            [0, 2666, 3335],
            $rows->pluck('outstanding_minor')->all()
        );
        $this->assertSame(
            6001,
            (int) $rows->sum('outstanding_minor')
        );
    }

    public function test_aging_splits_only_the_overdue_installment_amount(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente aging por cuota'
        );
        $product = $this->product(
            'Producto aging por cuota'
        );
        $location = $this->location($organization);

        $this->policy(
            $customer,
            $admin,
            2_000_000,
            'p9.5:aging:policy'
        );

        $soldAt = CarbonImmutable::parse(
            '2026-07-01 10:00:00'
        );

        $this->seedStockAt(
            $admin,
            $product,
            $location,
            '1',
            $soldAt->subDay()
        );

        $sale = $this->creditSale(
            $admin,
            $customer,
            $product,
            $location,
            90_000,
            CarbonImmutable::parse('2026-07-31'),
            'p9.5:aging:sale',
            3,
            $soldAt
        );

        $bank = app(FinancialAccountManager::class)
            ->create(
                'Banco aging P9.5',
                FinancialAccountType::BankAccount,
                'ARS',
                $admin
            );

        app(CustomerCollectionManager::class)->collect(
            $customer,
            new CustomerCollectionData(
                currencyCode: 'ARS',
                method:
                    CommercePaymentMethod::BankTransfer,
                amountMinor: 10_000,
                financialAccountId: $bank->id,
                allocations: [
                    new CustomerCollectionAllocationData(
                        $sale->receivable->id,
                        10_000
                    ),
                ],
                idempotencyKey:
                    'p9.5:aging:collection',
                reference: 'P95-AGING'
            ),
            $admin
        );

        $reader = app(
            CustomerReceivableAgingReader::class
        );

        $accountRows = $reader->rowsForCustomer(
            $customer,
            $admin,
            CarbonImmutable::today()
        );

        $aggregate = $accountRows->sole();

        $this->assertSame(
            80_000,
            $aggregate['outstanding_minor']
        );
        $this->assertSame(
            20_000,
            $aggregate['overdue_minor']
        );
        $this->assertTrue($aggregate['overdue']);
        $this->assertSame(
            3,
            $aggregate['installment_count']
        );
        $this->assertCount(
            3,
            $aggregate['installments']
        );

        $report = $reader->report(
            $admin,
            CarbonImmutable::today()
        );

        $ars = $report['totals']['ARS'];

        $this->assertSame(
            80_000,
            $ars['outstanding_minor']
        );
        $this->assertSame(
            20_000,
            $ars['overdue_minor']
        );
        $this->assertSame(1, $ars['receivable_count']);
        $this->assertSame(
            20_000,
            $ars['buckets'][
                CustomerReceivableAgingReader::
                    BUCKET_1_30
            ]
        );
        $this->assertSame(
            60_000,
            $ars['buckets'][
                CustomerReceivableAgingReader::
                    BUCKET_CURRENT
            ]
        );
        $this->assertCount(
            3,
            $report['receivables']
        );
    }

    public function test_credit_policy_blocks_on_overdue_installment_and_admin_override_uses_exact_overdue_amount(): void
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
            'Cliente política por cuota'
        );
        $location = $this->location($organization);
        $oldProduct = $this->product(
            'Producto crédito previo por cuota'
        );
        $newProduct = $this->product(
            'Producto nuevo crédito por cuota'
        );

        $this->policy(
            $customer,
            $admin,
            2_000_000,
            'p9.5:policy:limit'
        );

        $oldSoldAt = CarbonImmutable::parse(
            '2026-07-01 10:00:00'
        );

        $this->seedStockAt(
            $admin,
            $oldProduct,
            $location,
            '1',
            $oldSoldAt->subDay()
        );

        $oldSale = $this->creditSale(
            $admin,
            $customer,
            $oldProduct,
            $location,
            90_000,
            CarbonImmutable::parse('2026-07-31'),
            'p9.5:policy:old',
            3,
            $oldSoldAt
        );

        $bank = app(FinancialAccountManager::class)
            ->create(
                'Banco política cuotas P9.5',
                FinancialAccountType::BankAccount,
                'ARS',
                $admin
            );

        app(CustomerCollectionManager::class)->collect(
            $customer,
            new CustomerCollectionData(
                currencyCode: 'ARS',
                method:
                    CommercePaymentMethod::BankTransfer,
                amountMinor: 10_000,
                financialAccountId: $bank->id,
                allocations: [
                    new CustomerCollectionAllocationData(
                        $oldSale->receivable->id,
                        10_000
                    ),
                ],
                idempotencyKey:
                    'p9.5:policy:collection',
                reference: 'P95-POLICY'
            ),
            $admin
        );

        $snapshot = app(
            CustomerCreditExposureReader::class
        )->snapshot(
            $customer,
            'ARS',
            $operator
        );

        $this->assertSame(
            80_000,
            $snapshot['exposure_minor']
        );
        $this->assertSame(
            20_000,
            $snapshot['overdue_minor']
        );

        $this->seedStockAt(
            $admin,
            $newProduct,
            $location,
            '1',
            CarbonImmutable::now()->subMinute()
        );

        $this->assertDomainFailure(
            fn () => $this->creditSale(
                $operator,
                $customer,
                $newProduct,
                $location,
                100_000,
                CarbonImmutable::now()->addDays(30),
                'p9.5:policy:operator',
                1
            )
        );

        $sale = $this->creditSale(
            $admin,
            $customer,
            $newProduct,
            $location,
            100_000,
            CarbonImmutable::now()->addDays(30),
            'p9.5:policy:admin',
            1,
            null,
            'Autorizo nuevo crédito con una cuota previa vencida.'
        );

        $override = CustomerCreditOverride::query()
            ->where(
                'commerce_sale_id',
                $sale->id
            )
            ->sole();

        $this->assertTrue($override->overdue);
        $this->assertFalse($override->over_limit);
        $this->assertSame(
            20_000,
            $override->overdue_minor
        );
    }

    public function test_single_due_receivable_keeps_legacy_one_line_schedule(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente deuda simple'
        );
        $product = $this->product(
            'Producto deuda simple'
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
            50_000,
            CarbonImmutable::parse('2026-08-31'),
            'p9.5:single',
            null
        );

        $rows = app(
            CustomerReceivableInstallmentScheduleReader::class
        )->rowsForReceivable(
            $sale->receivable
        );

        $this->assertCount(1, $rows);
        $this->assertFalse($rows->first()['planned']);
        $this->assertSame(
            1,
            $rows->first()['installment_count']
        );
        $this->assertSame(
            50_000,
            $rows->first()['outstanding_minor']
        );
        $this->assertDatabaseCount(
            'customer_receivable_installment_plans',
            0
        );
    }

    public function test_plan_and_installments_are_immutable_and_db_rejects_post_confirmation_forgery(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente guardas cuotas'
        );
        $product = $this->product(
            'Producto guardas cuotas'
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
            30_000,
            CarbonImmutable::parse('2026-08-31'),
            'p9.5:guards',
            3
        );

        $plan = $sale->receivable
            ->installmentPlan()
            ->firstOrFail();
        $installment = $sale->receivable
            ->installments()
            ->firstOrFail();

        $this->assertDomainFailure(
            fn () => $plan->update([
                'installment_count' => 4,
            ])
        );

        $this->assertDomainFailure(
            fn () => $installment->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_receivable_installment_plans'
            )
                ->where('id', $plan->id)
                ->update([
                    'strategy' => 'forged',
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_receivable_installments'
            )
                ->where('id', $installment->id)
                ->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_receivable_installments'
            )->insert([
                'organization_id' =>
                    $organization->id,
                'public_id' => (string) Str::uuid(),
                'customer_receivable_id' =>
                    $sale->receivable->id,
                'sequence' => 4,
                'due_on' => '2026-11-30',
                'amount_minor' => 1,
                'fingerprint' => str_repeat('f', 64),
                'created_at' => now(),
            ])
        );
    }

    public function test_http_checkout_exposes_and_creates_own_installment_schedule(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente HTTP cuotas'
        );
        $product = $this->product(
            'Producto HTTP cuotas'
        );
        $location = $this->location($organization);

        $this->seedStockAt(
            $admin,
            $product,
            $location,
            '1',
            CarbonImmutable::now()->subMinute()
        );

        $this->price(
            $organization,
            $admin,
            $product,
            100_001
        );

        $this->actingAs($admin)
            ->get(route('commerce-sales.create'))
            ->assertOk()
            ->assertSee('Cuotas propias')
            ->assertSee(
                'name="receivable_installment_count"',
                false
            )
            ->assertSee('FIFO');

        $this->actingAs($admin)
            ->post(
                route('commerce-sales.store'),
                [
                    'currency_code' => 'ARS',
                    'customer_business_party_id' =>
                        $customer->business_party_id,
                    'product_lines' => [[
                        'catalog_product_id' =>
                            $product->id,
                        'source_location_id' =>
                            $location->id,
                        'condition' =>
                            InventoryCondition::New->value,
                        'quantity' => '1',
                    ]],
                    'payments' => [],
                    'receivable_amount' => '1000.01',
                    'receivable_due_on' =>
                        '2026-08-31',
                    'receivable_installment_count' =>
                        '3',
                    'idempotency_key' =>
                        'service-ui:commerce-sale:'
                        .Str::uuid(),
                ]
            )
            ->assertRedirect();

        $this->assertDatabaseCount(
            'customer_receivable_installment_plans',
            1
        );
        $this->assertDatabaseCount(
            'customer_receivable_installments',
            3
        );

        $plan =
            CustomerReceivableInstallmentPlan::query()
                ->sole();

        $this->assertSame(3, $plan->installment_count);
        $this->assertSame(
            [33333, 33333, 33335],
            CustomerReceivableInstallment::query()
                ->orderBy('sequence')
                ->pluck('amount_minor')
                ->all()
        );

        $this->actingAs($admin)
            ->get(route('customers.account', $customer))
            ->assertOk()
            ->assertSee('Cronograma de 3 cuotas')
            ->assertSee('Cuota 1/3')
            ->assertSee(
                'más antigua primero',
                false
            );

        $this->actingAs($admin)
            ->get(route('customers.aging'))
            ->assertOk()
            ->assertSee('Cuota 1/3');
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
                    ['slug' => 'p9-5-installment-tests'],
                    [
                        'name' => 'P9.5 Installment tests',
                        'active' => true,
                    ]
                )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'P95-INSTALLMENT-'
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
            'Política P9.5 de prueba.',
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
                    'Ingreso P9.5 para cuotas propias.',
                idempotencyKey:
                    'p9.5:stock:'.Str::uuid(),
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
        ?int $installmentCount = null,
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
                    $overrideReason,
                receivableInstallmentCount:
                    $installmentCount
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
            'reason' => 'Precio P9.5.',
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
