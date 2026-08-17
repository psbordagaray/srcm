<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Commerce\CustomerAdvanceData;
use App\Domain\Commerce\CustomerAdvanceManager;
use App\Domain\Commerce\CustomerCreditBalanceReader;
use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CommercePaymentMethod;
use App\Enums\CustomerAdvanceStatus;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerAdvance;
use App\Models\CustomerCreditConsumptionAllocation;
use App\Models\FinancialAccount;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
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

class CustomerAdvanceCreditConvergenceFoundationTest
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

    public function test_schema_route_authority_and_non_reservation_contract_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'customer_advances',
                [
                    'organization_id',
                    'public_id',
                    'business_party_id',
                    'financial_account_id',
                    'cash_register_session_id',
                    'cash_register_id',
                    'status',
                    'method',
                    'currency_code',
                    'amount_minor',
                    'tendered_amount_minor',
                    'change_amount_minor',
                    'reference',
                    'notes',
                    'received_by_user_id',
                    'received_at',
                    'idempotency_key',
                    'fingerprint',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumn(
                'cash_movements',
                'customer_advance_id'
            )
        );

        $this->assertTrue(
            Schema::hasColumn(
                'customer_credit_consumption_allocations',
                'customer_advance_id'
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
            UserRole::Viewer
                ->canViewCustomerAccount()
        );

        $this->assertTrue(
            Route::has(
                'customers.advances.store'
            )
        );

        $this->assertFalse(
            Schema::hasTable(
                'customer_reservations'
            )
        );
        $this->assertFalse(
            Schema::hasTable(
                'customer_reservation_holds'
            )
        );
    }

    public function test_bank_advance_is_idempotent_becomes_credit_and_creates_no_fake_sale_or_external_movement(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente anticipo bancario'
        );
        $bank = $this->account(
            $admin,
            'Banco anticipos P9.6a',
            FinancialAccountType::BankAccount
        );

        $inventoryBefore =
            DB::table(
                'inventory_movements'
            )->count();

        $data = new CustomerAdvanceData(
            currencyCode: 'ARS',
            method:
                CommercePaymentMethod::BankTransfer,
            amountMinor: 125_000,
            financialAccountId: $bank->id,
            idempotencyKey:
                'p9.6a:bank:advance',
            reference: 'ADV-P96-BANK',
            notes:
                'Anticipo libre para compra futura.'
        );

        $advance = app(
            CustomerAdvanceManager::class
        )->receive(
            $customer,
            $data,
            $admin
        );

        $retry = app(
            CustomerAdvanceManager::class
        )->receive(
            $customer,
            $data,
            $admin
        );

        $this->assertSame(
            $advance->id,
            $retry->id
        );
        $this->assertSame(
            CustomerAdvanceStatus::Confirmed,
            $advance->status
        );
        $this->assertSame(
            CommercePaymentMethod::BankTransfer,
            $advance->method
        );
        $this->assertSame(
            125_000,
            $advance->amount_minor
        );
        $this->assertSame(
            125_000,
            $this->creditBalance(
                $customer,
                'ARS'
            )
        );

        $this->assertDatabaseCount(
            'customer_advances',
            1
        );
        $this->assertDatabaseCount(
            'cash_movements',
            0
        );
        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
        $this->assertDatabaseCount(
            'commerce_sales',
            0
        );
        $this->assertDatabaseCount(
            'commerce_payments',
            0
        );
        $this->assertDatabaseCount(
            'customer_receivables',
            0
        );
        $this->assertDatabaseCount(
            'customer_collections',
            0
        );
        $this->assertSame(
            $inventoryBefore,
            DB::table(
                'inventory_movements'
            )->count()
        );
    }

    public function test_cash_advance_requires_own_open_shift_and_enters_cash_ledger_once(): void
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
            'Cliente anticipo efectivo'
        );
        $cash = $this->account(
            $admin,
            'Caja anticipos P9.6a',
            FinancialAccountType::CashBox
        );

        $register = app(
            CashRegisterManager::class
        )->create(
            'Caja anticipos P9.6a',
            $cash,
            $admin
        );

        $this->assertDomainFailure(
            fn () => app(
                CustomerAdvanceManager::class
            )->receive(
                $customer,
                new CustomerAdvanceData(
                    currencyCode: 'ARS',
                    method:
                        CommercePaymentMethod::Cash,
                    amountMinor: 50_000,
                    financialAccountId:
                        $cash->id,
                    idempotencyKey:
                        'p9.6a:cash:no-shift'
                ),
                $operator
            )
        );

        $session = app(
            CashRegisterSessionManager::class
        )->open(
            $register,
            200_000,
            'p9.6a:cash:open',
            $operator
        );

        $data = new CustomerAdvanceData(
            currencyCode: 'ARS',
            method: CommercePaymentMethod::Cash,
            amountMinor: 50_000,
            financialAccountId: $cash->id,
            idempotencyKey:
                'p9.6a:cash:valid',
            tenderedAmountMinor: 70_000
        );

        $advance = app(
            CustomerAdvanceManager::class
        )->receive(
            $customer,
            $data,
            $operator
        );

        $movement = $advance->cashMovement;

        $this->assertNotNull($movement);
        $this->assertSame(
            CashMovementDirection::In,
            $movement->direction
        );
        $this->assertSame(
            CashMovementType::CustomerAdvance,
            $movement->type
        );
        $this->assertSame(
            $advance->id,
            $movement->customer_advance_id
        );
        $this->assertSame(
            50_000,
            $movement->amount_minor
        );
        $this->assertSame(
            20_000,
            $advance->change_amount_minor
        );
        $this->assertSame(
            50_000,
            $this->creditBalance(
                $customer,
                'ARS'
            )
        );
        $this->assertSame(
            250_000,
            app(
                CashLedgerRecorder::class
            )->expectedAmountMinor(
                $session->refresh(),
                $operator
            )
        );

        $retry = app(
            CustomerAdvanceManager::class
        )->receive(
            $customer,
            $data,
            $operator
        );

        $this->assertSame(
            $advance->id,
            $retry->id
        );
        $this->assertDatabaseCount(
            'customer_advances',
            1
        );
        $this->assertDatabaseCount(
            'cash_movements',
            1
        );
    }

    public function test_checkout_consumes_advance_as_account_credit_without_new_money_movement(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente consumo anticipo'
        );
        $bank = $this->account(
            $admin,
            'Banco consumo anticipo',
            FinancialAccountType::BankAccount
        );

        $advance = $this->bankAdvance(
            $customer,
            $admin,
            $bank,
            100_000,
            'p9.6a:consume:advance'
        );

        [
            $product,
            $location,
        ] = $this->stockedProduct(
            $organization,
            $admin,
            'Producto pagado con anticipo'
        );

        $sale = app(
            CommerceCheckoutManager::class
        )->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey:
                    'p9.6a:consume:sale',
                payments: [
                    new CommercePaymentData(
                        method:
                            CommercePaymentMethod::
                                AccountCredit,
                        amountMinor: 70_000
                    ),
                ],
                productLines: [
                    new CommerceProductLineData(
                        $product->id,
                        $location->id,
                        InventoryCondition::New,
                        '1',
                        70_000
                    ),
                ],
                customerBusinessPartyId:
                    $customer->business_party_id
            ),
            $admin
        );

        $payment = $sale->payments->sole();
        $allocation =
            CustomerCreditConsumptionAllocation::
                query()
                ->where(
                    'customer_advance_id',
                    $advance->id
                )
                ->sole();

        $this->assertSame(
            CommercePaymentMethod::AccountCredit,
            $payment->method
        );
        $this->assertNull(
            $payment->financial_account_id
        );
        $this->assertSame(
            70_000,
            $payment->amount_minor
        );
        $this->assertSame(
            $advance->id,
            $allocation->customer_advance_id
        );
        $this->assertNull(
            $allocation
                ->customer_credit_grant_id
        );
        $this->assertNull(
            $allocation
                ->commerce_post_sale_exchange_credit_grant_id
        );
        $this->assertSame(
            70_000,
            $allocation->amount_minor
        );
        $this->assertSame(
            30_000,
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

    public function test_customer_credit_consumer_uses_customer_advances_fifo(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente FIFO anticipos'
        );
        $bank = $this->account(
            $admin,
            'Banco FIFO anticipos',
            FinancialAccountType::BankAccount
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-08-17 09:00:00'
            )
        );

        $first = $this->bankAdvance(
            $customer,
            $admin,
            $bank,
            30_000,
            'p9.6a:fifo:first'
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-08-17 10:00:00'
            )
        );

        $second = $this->bankAdvance(
            $customer,
            $admin,
            $bank,
            50_000,
            'p9.6a:fifo:second'
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-08-17 12:00:00'
            )
        );

        [
            $product,
            $location,
        ] = $this->stockedProduct(
            $organization,
            $admin,
            'Producto FIFO anticipos'
        );

        app(
            CommerceCheckoutManager::class
        )->checkout(
            new CommerceCheckoutData(
                currencyCode: 'ARS',
                idempotencyKey:
                    'p9.6a:fifo:sale',
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
            [
                $first->id,
                $second->id,
            ],
            $allocations
                ->pluck('customer_advance_id')
                ->all()
        );
        $this->assertSame(
            [30_000, 10_000],
            $allocations
                ->pluck('amount_minor')
                ->all()
        );
        $this->assertSame(
            40_000,
            $this->creditBalance(
                $customer,
                'ARS'
            )
        );
    }

    public function test_advance_and_credit_source_are_immutable_and_fail_closed(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente guardas anticipo'
        );
        $bank = $this->account(
            $admin,
            'Banco guardas anticipo',
            FinancialAccountType::BankAccount
        );

        $advance = $this->bankAdvance(
            $customer,
            $admin,
            $bank,
            60_000,
            'p9.6a:guards'
        );

        $this->assertDomainFailure(
            fn () => $advance->update([
                'amount_minor' => 1,
            ])
        );

        $this->assertDomainFailure(
            fn () => $advance->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_advances'
            )
                ->where(
                    'id',
                    $advance->id
                )
                ->update([
                    'amount_minor' => 1,
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_advances'
            )
                ->where(
                    'id',
                    $advance->id
                )
                ->delete()
        );

        $this->assertDomainFailure(
            fn () => app(
                CustomerAdvanceManager::class
            )->receive(
                $customer,
                new CustomerAdvanceData(
                    currencyCode: 'ARS',
                    method:
                        CommercePaymentMethod::
                            AccountCredit,
                    amountMinor: 10_000,
                    financialAccountId:
                        $bank->id,
                    idempotencyKey:
                        'p9.6a:invalid:credit'
                ),
                $admin
            )
        );

        $this->assertSame(
            60_000,
            $this->creditBalance(
                $customer,
                'ARS'
            )
        );
    }

    public function test_http_account_registers_advance_shows_credit_and_viewer_is_read_only(): void
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
        $viewer = $this->user(
            $organization,
            UserRole::Viewer
        );
        $customer = $this->customer(
            $organization,
            'Cliente HTTP anticipo'
        );
        $bank = $this->account(
            $admin,
            'Banco HTTP anticipo',
            FinancialAccountType::BankAccount
        );

        $this->actingAs($operator)
            ->get(
                route(
                    'customers.account',
                    $customer
                )
            )
            ->assertOk()
            ->assertSee(
                'Registrar anticipo a cuenta'
            )
            ->assertSee(
                'no reserva mercadería',
                false
            );

        $payload = [
            'currency_code' => 'ARS',
            'method' =>
                CommercePaymentMethod::
                    BankTransfer->value,
            'financial_account_id' =>
                $bank->id,
            'amount' => '500.00',
            'reference' => 'HTTP-P96A',
            'notes' =>
                'Anticipo web sin reserva.',
            'idempotency_key' =>
                'customer-advance-ui:'
                .Str::uuid(),
        ];

        $this->actingAs($operator)
            ->post(
                route(
                    'customers.advances.store',
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

        $this->assertDatabaseCount(
            'customer_advances',
            1
        );

        $this->actingAs($operator)
            ->get(
                route(
                    'customers.account',
                    $customer
                )
            )
            ->assertOk()
            ->assertSee(
                'Saldo a favor disponible'
            )
            ->assertSee('500,00')
            ->assertSee('HTTP-P96A');

        $this->actingAs($viewer)
            ->get(
                route(
                    'customers.account',
                    $customer
                )
            )
            ->assertOk()
            ->assertSee(
                'Saldo a favor disponible'
            )
            ->assertDontSee(
                'Registrar anticipo a cuenta'
            );

        $payload['idempotency_key'] =
            'customer-advance-ui:'
            .Str::uuid();

        $this->actingAs($viewer)
            ->post(
                route(
                    'customers.advances.store',
                    $customer
                ),
                $payload
            )
            ->assertForbidden();

        $this->assertDatabaseCount(
            'customer_advances',
            1
        );
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where(
                'slug',
                'sulu-tv'
            )
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

    private function bankAdvance(
        Customer $customer,
        User $actor,
        FinancialAccount $bank,
        int $amountMinor,
        string $idempotency
    ): CustomerAdvance {
        return app(
            CustomerAdvanceManager::class
        )->receive(
            $customer,
            new CustomerAdvanceData(
                currencyCode: 'ARS',
                method:
                    CommercePaymentMethod::
                        BankTransfer,
                amountMinor: $amountMinor,
                financialAccountId:
                    $bank->id,
                idempotencyKey:
                    $idempotency,
                reference:
                    'REF-'.$idempotency
            ),
            $actor
        );
    }

    /**
     * @return array{0: CatalogProduct, 1: InventoryLocation}
     */
    private function stockedProduct(
        Organization $organization,
        User $actor,
        string $name
    ): array {
        $this->productSequence++;

        $category =
            ProductCategory::withoutEvents(
                fn () =>
                    ProductCategory::query()
                        ->firstOrCreate(
                            [
                                'slug' =>
                                    'p9-6a-advance-tests',
                            ],
                            [
                                'name' =>
                                    'P9.6a advance tests',
                                'active' => true,
                            ]
                        )
            );

        $product =
            CatalogProduct::withoutEvents(
                fn () =>
                    CatalogProduct::query()
                        ->create([
                            'product_category_id' =>
                                $category->id,
                            'sku' =>
                                'P96A-ADV-'
                                .$this->productSequence
                                .'-'
                                .Str::lower(
                                    Str::random(6)
                                ),
                            'name' => $name,
                            'active' => true,
                        ])->refresh()
            );

        $location =
            InventoryLocation::query()
                ->forOrganization(
                    $organization->id
                )
                ->orderBy('id')
                ->firstOrFail();

        $movement = app(
            InventoryMovementCreator::class
        )->create(
            new InventoryMovementDraftData(
                type:
                    InventoryMovementType::Receipt,
                effectiveAt:
                    CarbonImmutable::now()
                        ->subMinute(),
                reason:
                    'Ingreso P9.6a para consumo de anticipo.',
                idempotencyKey:
                    'p9.6a:stock:'
                    .Str::uuid(),
                lines: [
                    new InventoryMovementLineData(
                        catalogProductId:
                            $product->id,
                        condition:
                            InventoryCondition::New,
                        enteredQuantity: '1',
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

        return [
            $product,
            $location,
        ];
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
