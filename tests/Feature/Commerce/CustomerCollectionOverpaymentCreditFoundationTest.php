<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Commerce\CustomerAdvanceData;
use App\Domain\Commerce\CustomerAdvanceManager;
use App\Domain\Commerce\CustomerCollectionAllocationData;
use App\Domain\Commerce\CustomerCollectionData;
use App\Domain\Commerce\CustomerCollectionManager;
use App\Domain\Commerce\CustomerCreditBalanceReader;
use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Enums\CashMovementType;
use App\Enums\CommercePaymentMethod;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerCreditConsumptionAllocation;
use App\Models\FinancialAccount;
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

class CustomerCollectionOverpaymentCreditFoundationTest
    extends TestCase
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

    public function test_schema_and_controlled_overpayment_contract_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumn(
                'customer_collections',
                'retain_excess_as_credit'
            )
        );
        $this->assertTrue(
            Schema::hasColumn(
                'customer_credit_consumption_allocations',
                'customer_collection_id'
            )
        );
        $this->assertFalse(
            Schema::hasTable(
                'customer_collection_credits'
            )
        );
        $this->assertTrue(
            UserRole::Admin
                ->canRecordCustomerCollections()
        );
        $this->assertTrue(
            UserRole::Operator
                ->canRecordCustomerCollections()
        );
        $this->assertFalse(
            UserRole::Viewer
                ->canRecordCustomerCollections()
        );
        $this->assertTrue(
            Route::has(
                'customers.collections.store'
            )
        );
    }

    public function test_overpayment_without_confirmation_fails_closed_and_exact_collection_stays_valid(): void
    {
        [
            $organization,
            $admin,
            $customer,
            $receivable,
            $bank,
        ] = $this->debtContext(
            'control',
            100_000
        );

        $this->assertDomainFailure(
            fn () => app(
                CustomerCollectionManager::class
            )->collect(
                $customer,
                $this->collectionData(
                    $receivable->id,
                    $bank,
                    120_000,
                    100_000,
                    'p9.6b:unconfirmed',
                    false
                ),
                $admin
            )
        );

        $this->assertDatabaseCount(
            'customer_collections',
            0
        );
        $this->assertSame(
            0,
            $this->creditBalance(
                $customer,
                'ARS'
            )
        );

        $exact = app(
            CustomerCollectionManager::class
        )->collect(
            $customer,
            $this->collectionData(
                $receivable->id,
                $bank,
                100_000,
                100_000,
                'p9.6b:exact',
                false
            ),
            $admin
        );

        $this->assertFalse(
            $exact->retain_excess_as_credit
        );
        $this->assertSame(
            0,
            $this->creditBalance(
                $customer,
                'ARS'
            )
        );
    }

    public function test_cash_overpayment_records_one_receipt_and_keeps_change_separate_from_credit(): void
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
            'Cliente sobrepago efectivo'
        );
        $receivable = $this->createDebt(
            $organization,
            $admin,
            $customer,
            100_000,
            'cash'
        );
        $cash = $this->account(
            $admin,
            'Caja sobrepago P9.6b',
            FinancialAccountType::CashBox
        );

        $register = app(
            CashRegisterManager::class
        )->create(
            'Caja sobrepago P9.6b',
            $cash,
            $admin
        );

        $session = app(
            CashRegisterSessionManager::class
        )->open(
            $register,
            200_000,
            'p9.6b:cash:open',
            $operator
        );

        $collection = app(
            CustomerCollectionManager::class
        )->collect(
            $customer,
            new CustomerCollectionData(
                currencyCode: 'ARS',
                method:
                    CommercePaymentMethod::Cash,
                amountMinor: 120_000,
                financialAccountId: $cash->id,
                allocations: [
                    new CustomerCollectionAllocationData(
                        $receivable->id,
                        100_000
                    ),
                ],
                idempotencyKey:
                    'p9.6b:cash:collection',
                tenderedAmountMinor: 150_000,
                retainExcessAsCredit: true
            ),
            $operator
        );

        $this->assertTrue(
            $collection->retain_excess_as_credit
        );
        $this->assertSame(
            30_000,
            $collection->change_amount_minor
        );
        $this->assertSame(
            20_000,
            $this->creditBalance(
                $customer,
                'ARS'
            )
        );

        $movement =
            $collection->cashMovement;

        $this->assertNotNull($movement);
        $this->assertSame(
            CashMovementType::CustomerCollection,
            $movement->type
        );
        $this->assertSame(
            120_000,
            $movement->amount_minor
        );
        $this->assertDatabaseCount(
            'cash_movements',
            1
        );
        $this->assertDatabaseCount(
            'customer_advances',
            0
        );
        $this->assertSame(
            320_000,
            app(
                CashLedgerRecorder::class
            )->expectedAmountMinor(
                $session->refresh(),
                $operator
            )
        );
    }

    public function test_non_cash_overpayment_creates_credit_without_fake_cash_or_external_movement(): void
    {
        [
            $organization,
            $admin,
            $customer,
            $receivable,
            $bank,
        ] = $this->debtContext(
            'bank',
            80_000
        );

        $collection = app(
            CustomerCollectionManager::class
        )->collect(
            $customer,
            $this->collectionData(
                $receivable->id,
                $bank,
                100_000,
                80_000,
                'p9.6b:bank:collection',
                true
            ),
            $admin
        );

        $this->assertSame(
            100_000,
            $collection->amount_minor
        );
        $this->assertSame(
            80_000,
            $collection
                ->allocations
                ->sum('amount_minor')
        );
        $this->assertSame(
            20_000,
            $this->creditBalance(
                $customer,
                'ARS'
            )
        );
        $this->assertDatabaseCount(
            'cash_movements',
            0
        );
        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_checkout_consumes_collection_overpayment_as_account_credit(): void
    {
        [
            $organization,
            $admin,
            $customer,
            $receivable,
            $bank,
        ] = $this->debtContext(
            'consume',
            100_000
        );

        $collection = app(
            CustomerCollectionManager::class
        )->collect(
            $customer,
            $this->collectionData(
                $receivable->id,
                $bank,
                150_000,
                100_000,
                'p9.6b:consume:collection',
                true
            ),
            $admin
        );

        [
            $product,
            $location,
        ] = $this->saleProduct(
            $organization,
            $admin,
            'Producto consumo sobrepago',
            30_000
        );

        $sale = app(
            CommerceCheckoutManager::class
        )->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey:
                    'p9.6b:consume:sale',
                payments: [
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::
                                AccountCredit,
                        amountMinor: 30_000
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
                ],
                customerBusinessPartyId:
                    $customer->business_party_id
            ),
            $admin
        );

        $allocation =
            CustomerCreditConsumptionAllocation::
                query()
                ->where(
                    'customer_collection_id',
                    $collection->id
                )
                ->sole();

        $this->assertSame(
            $collection->id,
            $allocation->customer_collection_id
        );
        $this->assertNull(
            $allocation->customer_credit_grant_id
        );
        $this->assertNull(
            $allocation
                ->commerce_post_sale_exchange_credit_grant_id
        );
        $this->assertNull(
            $allocation->customer_advance_id
        );
        $this->assertSame(
            30_000,
            $allocation->amount_minor
        );
        $this->assertSame(
            20_000,
            $this->creditBalance(
                $customer,
                'ARS'
            )
        );
        $this->assertSame(
            CommercePaymentMethod::AccountCredit,
            $sale->payments->sole()->method
        );
    }

    public function test_fifo_consumes_advance_before_later_collection_overpayment(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente FIFO P9.6b'
        );
        $bank = $this->account(
            $admin,
            'Banco FIFO P9.6b',
            FinancialAccountType::BankAccount
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-08-17 09:00:00'
            )
        );

        $advance = app(
            CustomerAdvanceManager::class
        )->receive(
            $customer,
            new CustomerAdvanceData(
                currencyCode: 'ARS',
                method:
                    CommercePaymentMethod::
                        BankTransfer,
                amountMinor: 20_000,
                financialAccountId: $bank->id,
                idempotencyKey:
                    'p9.6b:fifo:advance',
                reference:
                    'ADV-P96B-FIFO'
            ),
            $admin
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-08-17 10:00:00'
            )
        );

        $receivable = $this->createDebt(
            $organization,
            $admin,
            $customer,
            50_000,
            'fifo-debt'
        );

        $collection = app(
            CustomerCollectionManager::class
        )->collect(
            $customer,
            $this->collectionData(
                $receivable->id,
                $bank,
                80_000,
                50_000,
                'p9.6b:fifo:collection',
                true
            ),
            $admin
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-08-17 12:00:00'
            )
        );

        [
            $product,
            $location,
        ] = $this->saleProduct(
            $organization,
            $admin,
            'Producto FIFO P9.6b',
            40_000
        );

        app(
            CommerceCheckoutManager::class
        )->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey:
                    'p9.6b:fifo:sale',
                payments: [
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::
                                AccountCredit,
                        amountMinor: 40_000
                    ),
                ],
                productLines: [
                    new CommerceProductLineData(
                        $product->id,
                        $location->id,
                        InventoryCondition::New,
                        '1',
                        40_000
                    ),
                ],
                customerBusinessPartyId:
                    $customer->business_party_id
            ),
            $admin
        );

        $allocations =
            CustomerCreditConsumptionAllocation::
                query()
                ->orderBy('sequence')
                ->get();

        $this->assertCount(
            2,
            $allocations
        );
        $this->assertSame(
            $advance->id,
            $allocations[0]
                ->customer_advance_id
        );
        $this->assertSame(
            20_000,
            $allocations[0]->amount_minor
        );
        $this->assertSame(
            $collection->id,
            $allocations[1]
                ->customer_collection_id
        );
        $this->assertSame(
            20_000,
            $allocations[1]->amount_minor
        );
        $this->assertSame(
            10_000,
            $this->creditBalance(
                $customer,
                'ARS'
            )
        );
    }

    public function test_database_and_http_require_explicit_overpayment_confirmation(): void
    {
        [
            $organization,
            $admin,
            $customer,
            $receivable,
            $bank,
        ] = $this->debtContext(
            'http',
            100_000
        );

        $payload = [
            'currency_code' => 'ARS',
            'method' =>
                CommercePaymentMethod::
                    BankTransfer->value,
            'financial_account_id' =>
                $bank->id,
            'amount' => '1200.00',
            'reference' => 'P96B-HTTP',
            'allocations' => [
                [
                    'customer_receivable_id' =>
                        $receivable->id,
                    'amount' => '1000.00',
                ],
            ],
            'idempotency_key' =>
                'customer-collection-ui:'
                .Str::uuid(),
            'retain_excess_as_credit' => '0',
        ];

        $this->actingAs($admin)
            ->get(
                route(
                    'customers.account',
                    $customer
                )
            )
            ->assertOk()
            ->assertSee(
                'Dejar cualquier excedente como saldo a favor.'
            )
            ->assertSee(
                'El efectivo entregado que vuelve como cambio no forma parte del saldo a favor.'
            );

        $this->actingAs($admin)
            ->post(
                route(
                    'customers.collections.store',
                    $customer
                ),
                $payload
            )
            ->assertRedirect()
            ->assertSessionHasErrors(
                'collection'
            );

        $this->assertDatabaseCount(
            'customer_collections',
            0
        );

        $payload[
            'retain_excess_as_credit'
        ] = '1';

        $this->actingAs($admin)
            ->post(
                route(
                    'customers.collections.store',
                    $customer
                ),
                $payload
            )
            ->assertRedirect(
                route(
                    'customers.account',
                    $customer
                )
            );

        $collection =
            DB::table('customer_collections')
                ->sole();

        $this->assertSame(
            1,
            (int) $collection
                ->retain_excess_as_credit
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_collections'
            )
                ->where(
                    'id',
                    $collection->id
                )
                ->update([
                    'retain_excess_as_credit'
                        => 0,
                ])
        );
    }

    private function debtContext(
        string $key,
        int $amountMinor
    ): array {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente deuda '.$key
        );
        $receivable = $this->createDebt(
            $organization,
            $admin,
            $customer,
            $amountMinor,
            $key
        );
        $bank = $this->account(
            $admin,
            'Banco '.$key.' P9.6b',
            FinancialAccountType::BankAccount
        );

        return [
            $organization,
            $admin,
            $customer,
            $receivable,
            $bank,
        ];
    }

    private function createDebt(
        Organization $organization,
        User $admin,
        Customer $customer,
        int $amountMinor,
        string $key
    ) {
        [
            $product,
            $location,
        ] = $this->saleProduct(
            $organization,
            $admin,
            'Producto deuda '.$key,
            $amountMinor
        );

        $sale = app(
            CommerceCheckoutManager::class
        )->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey:
                    'p9.6b:debt:'.$key,
                payments: [],
                receivableAmountMinor:
                    $amountMinor,
                receivableDueOn:
                    CarbonImmutable::now()
                        ->addDays(30),
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
                    $customer->business_party_id
            ),
            $admin
        );

        return $sale->receivable;
    }

    private function collectionData(
        int $receivableId,
        FinancialAccount $bank,
        int $receivedMinor,
        int $appliedMinor,
        string $idempotency,
        bool $retain
    ): CustomerCollectionData {
        return new CustomerCollectionData(
            currencyCode: 'ARS',
            method:
                CommercePaymentMethod::
                    BankTransfer,
            amountMinor: $receivedMinor,
            financialAccountId: $bank->id,
            allocations: [
                new CustomerCollectionAllocationData(
                    $receivableId,
                    $appliedMinor
                ),
            ],
            idempotencyKey: $idempotency,
            reference:
                'REF-'.$idempotency,
            retainExcessAsCredit: $retain
        );
    }

    /**
     * @return array{0: CatalogProduct, 1: InventoryLocation}
     */
    private function saleProduct(
        Organization $organization,
        User $actor,
        string $name,
        int $amountMinor
    ): array {
        $product = $this->product($name);
        $location = $this->location(
            $organization
        );

        $this->price(
            $organization,
            $actor,
            $product,
            $amountMinor
        );

        $this->seedStockAt(
            $actor,
            $product,
            $location,
            '1',
            CarbonImmutable::now()
                ->subMinute()
        );

        return [
            $product,
            $location,
        ];
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
            ->forOrganization(
                $organization->id
            )
            ->orderBy('id')
            ->firstOrFail();
    }

    private function customer(
        Organization $organization,
        string $name
    ): Customer {
        $party =
            BusinessParty::query()->create([
                'organization_id' =>
                    $organization->id,
                'party_type' =>
                    BusinessParty::TYPE_PERSON,
                'name' => $name,
            ]);

        return Customer::withoutEvents(
            fn () =>
                Customer::query()->create([
                    'organization_id' =>
                        $organization->id,
                    'business_party_id' =>
                        $party->id,
                    'active' => true,
                ])->load('party')
        );
    }

    private function product(
        string $name
    ): CatalogProduct {
        $this->productSequence++;

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'p9-6b-overpayment-tests',
                            ],
                            [
                                'name' =>
                                    'P9.6b overpayment tests',
                                'active' => true,
                            ]
                        )
            );

        return CatalogProduct::withoutEvents(
            fn () =>
                CatalogProduct::query()->create([
                    'product_category_id' =>
                        $category->id,
                    'sku' =>
                        'P96B-OVERPAY-'
                        .$this->productSequence
                        .'-'
                        .Str::lower(
                            Str::random(6)
                        ),
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
            fn () =>
                OrganizationMembership::query()
                    ->updateOrCreate(
                        [
                            'organization_id' =>
                                $organization->id,
                            'user_id' =>
                                $user->id,
                        ],
                        [
                            'role' => $role,
                            'active' => true,
                        ]
                    )
        );

        return $user;
    }

    private function account(
        User $actor,
        string $name,
        FinancialAccountType $type
    ): FinancialAccount {
        return app(
            FinancialAccountManager::class
        )->create(
            $name,
            $type,
            'ARS',
            $actor
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
                type:
                    InventoryMovementType::Receipt,
                effectiveAt: $effectiveAt,
                reason:
                    'Ingreso P9.6b para prueba de sobrepago.',
                idempotencyKey:
                    'p9.6b:stock:'.Str::uuid(),
                lines: [
                    new InventoryMovementLineData(
                        catalogProductId:
                            $product->id,
                        condition:
                            InventoryCondition::New,
                        enteredQuantity:
                            $quantity,
                        enteredUnitCode:
                            $product
                                ->base_unit_code,
                        destinationLocationId:
                            $location->id
                    ),
                ]
            ),
            $actor
        );

        app(
            InventoryMovementConfirmer::class
        )->confirm(
            $movement,
            $actor
        );
    }

    private function price(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        int $amountMinor
    ): void {
        OrganizationProductPrice::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'catalog_product_id' =>
                    $product->id,
                'currency_code' => 'ARS',
                'amount_minor' =>
                    $amountMinor,
                'valid_from' =>
                    CarbonImmutable::now()
                        ->subMinute(),
                'valid_until' => null,
                'is_current' => true,
                'reason' =>
                    'Precio P9.6b.',
                'created_by_user_id' =>
                    $actor->id,
            ]);
    }

    private function creditBalance(
        Customer $customer,
        string $currency
    ): int {
        return app(
            CustomerCreditBalanceReader::class
        )->balanceMinor(
            (int) $customer->organization_id,
            (int) $customer->business_party_id,
            $currency
        );
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
