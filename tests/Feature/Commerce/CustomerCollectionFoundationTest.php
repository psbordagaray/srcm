<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Commerce\CustomerCollectionAllocationData;
use App\Domain\Commerce\CustomerCollectionData;
use App\Domain\Commerce\CustomerCollectionManager;
use App\Domain\Commerce\CustomerReceivableBalanceReader;
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
use App\Enums\CustomerCollectionStatus;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerCollection;
use App\Models\CustomerCollectionAllocation;
use App\Models\CustomerReceivable;
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

class CustomerCollectionFoundationTest extends TestCase
{
    use RefreshDatabase;

    private int $productSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_routes_and_authority_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns(
            'customer_collections',
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
                'received_by_user_id',
                'collected_at',
                'idempotency_key',
                'fingerprint',
            ]
        ));

        $this->assertTrue(Schema::hasColumns(
            'customer_collection_allocations',
            [
                'organization_id',
                'public_id',
                'customer_collection_id',
                'customer_receivable_id',
                'sequence',
                'amount_minor',
                'fingerprint',
            ]
        ));

        $this->assertTrue(
            Schema::hasColumn(
                'cash_movements',
                'customer_collection_id'
            )
        );

        $this->assertTrue(
            UserRole::Admin->canRecordCustomerCollections()
        );
        $this->assertTrue(
            UserRole::Operator->canRecordCustomerCollections()
        );
        $this->assertFalse(
            UserRole::Viewer->canRecordCustomerCollections()
        );
        $this->assertTrue(
            UserRole::Viewer->canViewCustomerAccount()
        );

        $this->assertTrue(Route::has('customers.account'));
        $this->assertTrue(
            Route::has('customers.collections.store')
        );
    }

    public function test_partial_bank_collection_is_idempotent_and_balance_is_derived(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $customer = $this->customer(
            $organization,
            'Cliente cobranza parcial'
        );
        $receivable = $this->receivable(
            $organization,
            $admin,
            $customer,
            100_000
        );
        $bank = $this->account(
            $admin,
            'Banco CxC parcial',
            FinancialAccountType::BankAccount
        );

        $data = new CustomerCollectionData(
            currencyCode: 'ARS',
            method: CommercePaymentMethod::BankTransfer,
            amountMinor: 40_000,
            financialAccountId: $bank->id,
            allocations: [
                new CustomerCollectionAllocationData(
                    $receivable->id,
                    40_000
                ),
            ],
            idempotencyKey: 'p9.2:bank:partial',
            reference: 'TRX-P92-PARTIAL'
        );

        $collection = app(
            CustomerCollectionManager::class
        )->collect($customer, $data, $admin);

        $retry = app(
            CustomerCollectionManager::class
        )->collect($customer, $data, $admin);

        $this->assertSame(
            $collection->id,
            $retry->id
        );
        $this->assertSame(
            CustomerCollectionStatus::Confirmed,
            $collection->status
        );
        $this->assertSame(
            CommercePaymentMethod::BankTransfer,
            $collection->method
        );
        $this->assertSame(40_000, $collection->amount_minor);
        $this->assertCount(1, $collection->allocations);
        $this->assertDatabaseCount('customer_collections', 1);
        $this->assertDatabaseCount(
            'customer_collection_allocations',
            1
        );
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
        $this->assertDatabaseCount('commerce_payments', 0);

        $this->assertSame(
            60_000,
            app(CustomerReceivableBalanceReader::class)
                ->outstandingMinor($receivable)
        );
    }

    public function test_one_collection_can_apply_to_multiple_debts(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $customer = $this->customer(
            $organization,
            'Cliente cobranza múltiple'
        );

        $first = $this->receivable(
            $organization,
            $admin,
            $customer,
            70_000
        );
        $second = $this->receivable(
            $organization,
            $admin,
            $customer,
            80_000
        );

        $bank = $this->account(
            $admin,
            'Banco CxC múltiple',
            FinancialAccountType::BankAccount
        );

        $collection = app(
            CustomerCollectionManager::class
        )->collect(
            $customer,
            new CustomerCollectionData(
                currencyCode: 'ARS',
                method: CommercePaymentMethod::BankTransfer,
                amountMinor: 100_000,
                financialAccountId: $bank->id,
                allocations: [
                    new CustomerCollectionAllocationData(
                        $first->id,
                        60_000
                    ),
                    new CustomerCollectionAllocationData(
                        $second->id,
                        40_000
                    ),
                ],
                idempotencyKey: 'p9.2:bank:multi',
                reference: 'TRX-P92-MULTI'
            ),
            $admin
        );

        $this->assertCount(2, $collection->allocations);
        $this->assertSame(
            10_000,
            app(CustomerReceivableBalanceReader::class)
                ->outstandingMinor($first)
        );
        $this->assertSame(
            40_000,
            app(CustomerReceivableBalanceReader::class)
                ->outstandingMinor($second)
        );

        $this->assertSame(
            100_000,
            $collection->allocations->sum('amount_minor')
        );
    }

    public function test_overallocation_foreign_debt_and_total_mismatch_fail_closed(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $customer = $this->customer(
            $organization,
            'Cliente validaciones'
        );
        $otherCustomer = $this->customer(
            $organization,
            'Otro cliente'
        );

        $receivable = $this->receivable(
            $organization,
            $admin,
            $customer,
            100_000
        );
        $foreign = $this->receivable(
            $organization,
            $admin,
            $otherCustomer,
            100_000
        );

        $bank = $this->account(
            $admin,
            'Banco validaciones CxC',
            FinancialAccountType::BankAccount
        );

        $this->assertDomainFailure(
            fn () => app(CustomerCollectionManager::class)
                ->collect(
                    $customer,
                    new CustomerCollectionData(
                        currencyCode: 'ARS',
                        method:
                            CommercePaymentMethod::BankTransfer,
                        amountMinor: 110_000,
                        financialAccountId: $bank->id,
                        allocations: [
                            new CustomerCollectionAllocationData(
                                $receivable->id,
                                110_000
                            ),
                        ],
                        idempotencyKey:
                            'p9.2:invalid:over',
                        reference: 'OVER'
                    ),
                    $admin
                )
        );

        $this->assertDomainFailure(
            fn () => app(CustomerCollectionManager::class)
                ->collect(
                    $customer,
                    new CustomerCollectionData(
                        currencyCode: 'ARS',
                        method:
                            CommercePaymentMethod::BankTransfer,
                        amountMinor: 50_000,
                        financialAccountId: $bank->id,
                        allocations: [
                            new CustomerCollectionAllocationData(
                                $foreign->id,
                                50_000
                            ),
                        ],
                        idempotencyKey:
                            'p9.2:invalid:foreign',
                        reference: 'FOREIGN'
                    ),
                    $admin
                )
        );

        $this->assertDomainFailure(
            fn () => app(CustomerCollectionManager::class)
                ->collect(
                    $customer,
                    new CustomerCollectionData(
                        currencyCode: 'ARS',
                        method:
                            CommercePaymentMethod::BankTransfer,
                        amountMinor: 50_000,
                        financialAccountId: $bank->id,
                        allocations: [
                            new CustomerCollectionAllocationData(
                                $receivable->id,
                                40_000
                            ),
                        ],
                        idempotencyKey:
                            'p9.2:invalid:sum',
                        reference: 'SUM'
                    ),
                    $admin
                )
        );

        $this->assertDatabaseCount('customer_collections', 0);
        $this->assertDatabaseCount(
            'customer_collection_allocations',
            0
        );
    }

    public function test_cash_collection_requires_own_shift_and_enters_cash_ledger_once(): void
    {
        $organization = $this->organization();
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente efectivo'
        );

        $receivable = $this->receivable(
            $organization,
            $admin,
            $customer,
            200_000
        );

        $cash = $this->account(
            $admin,
            'Caja CxC',
            FinancialAccountType::CashBox
        );

        $register = app(CashRegisterManager::class)
            ->create(
                'Caja CxC',
                $cash,
                $admin
            );

        $this->assertDomainFailure(
            fn () => app(CustomerCollectionManager::class)
                ->collect(
                    $customer,
                    new CustomerCollectionData(
                        currencyCode: 'ARS',
                        method: CommercePaymentMethod::Cash,
                        amountMinor: 100_000,
                        financialAccountId: $cash->id,
                        allocations: [
                            new CustomerCollectionAllocationData(
                                $receivable->id,
                                100_000
                            ),
                        ],
                        idempotencyKey:
                            'p9.2:cash:no-shift'
                    ),
                    $operator
                )
        );

        $session = app(
            CashRegisterSessionManager::class
        )->open(
            $register,
            500_000,
            'p9.2:cash:open',
            $operator
        );

        $collection = app(
            CustomerCollectionManager::class
        )->collect(
            $customer,
            new CustomerCollectionData(
                currencyCode: 'ARS',
                method: CommercePaymentMethod::Cash,
                amountMinor: 100_000,
                financialAccountId: $cash->id,
                allocations: [
                    new CustomerCollectionAllocationData(
                        $receivable->id,
                        100_000
                    ),
                ],
                idempotencyKey:
                    'p9.2:cash:valid',
                tenderedAmountMinor: 150_000
            ),
            $operator
        );

        $movement = $collection->cashMovement;

        $this->assertNotNull($movement);
        $this->assertSame(
            CashMovementDirection::In,
            $movement->direction
        );
        $this->assertSame(
            CashMovementType::CustomerCollection,
            $movement->type
        );
        $this->assertSame(
            $collection->id,
            $movement->customer_collection_id
        );
        $this->assertSame(100_000, $movement->amount_minor);
        $this->assertSame(50_000, $collection->change_amount_minor);
        $this->assertDatabaseCount('cash_movements', 1);

        $this->assertSame(
            600_000,
            app(CashLedgerRecorder::class)
                ->expectedAmountMinor(
                    $session->refresh(),
                    $operator
                )
        );

        $retry = app(
            CustomerCollectionManager::class
        )->collect(
            $customer,
            new CustomerCollectionData(
                currencyCode: 'ARS',
                method: CommercePaymentMethod::Cash,
                amountMinor: 100_000,
                financialAccountId: $cash->id,
                allocations: [
                    new CustomerCollectionAllocationData(
                        $receivable->id,
                        100_000
                    ),
                ],
                idempotencyKey:
                    'p9.2:cash:valid',
                tenderedAmountMinor: 150_000
            ),
            $operator
        );

        $this->assertSame($collection->id, $retry->id);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_collection_and_allocations_are_immutable_and_database_guarded(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $customer = $this->customer(
            $organization,
            'Cliente inmutable'
        );
        $receivable = $this->receivable(
            $organization,
            $admin,
            $customer,
            100_000
        );
        $bank = $this->account(
            $admin,
            'Banco inmutable CxC',
            FinancialAccountType::BankAccount
        );

        $collection = app(
            CustomerCollectionManager::class
        )->collect(
            $customer,
            new CustomerCollectionData(
                currencyCode: 'ARS',
                method: CommercePaymentMethod::BankTransfer,
                amountMinor: 50_000,
                financialAccountId: $bank->id,
                allocations: [
                    new CustomerCollectionAllocationData(
                        $receivable->id,
                        50_000
                    ),
                ],
                idempotencyKey: 'p9.2:immutable',
                reference: 'IMMUTABLE'
            ),
            $admin
        );

        /** @var CustomerCollectionAllocation $allocation */
        $allocation = $collection->allocations->sole();

        $this->assertDomainFailure(
            fn () => $collection->update([
                'amount_minor' => 1,
            ])
        );

        $this->assertDomainFailure(
            fn () => $allocation->update([
                'amount_minor' => 1,
            ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'customer_collection_allocations'
            )->insert([
                'organization_id' => $organization->id,
                'public_id' => (string) Str::uuid(),
                'customer_collection_id' => $collection->id,
                'customer_receivable_id' => $receivable->id,
                'sequence' => 2,
                'amount_minor' => 1,
                'fingerprint' => str_repeat('a', 64),
                'created_at' => now(),
            ])
        );

        $this->assertQueryRejected(
            fn () => DB::table('customer_collections')
                ->where('id', $collection->id)
                ->update(['amount_minor' => 1])
        );
    }

    public function test_http_account_is_readable_and_operator_can_collect_but_viewer_cannot(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
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
            'Cliente HTTP cobranza'
        );
        $receivable = $this->receivable(
            $organization,
            $admin,
            $customer,
            100_000
        );
        $bank = $this->account(
            $admin,
            'Banco HTTP CxC',
            FinancialAccountType::BankAccount
        );

        $this->actingAs($operator)
            ->get(route('customers.account', $customer))
            ->assertOk()
            ->assertSee('Cuenta corriente · CxC')
            ->assertSee('Registrar cobranza')
            ->assertSee('Venta #'.$receivable->sale->sale_number);

        $payload = [
            'currency_code' => 'ARS',
            'method' =>
                CommercePaymentMethod::BankTransfer->value,
            'financial_account_id' => $bank->id,
            'amount' => '400.00',
            'reference' => 'HTTP-P92',
            'allocations' => [[
                'customer_receivable_id' =>
                    $receivable->id,
                'amount' => '400.00',
            ]],
            'idempotency_key' =>
                'customer-collection-ui:'.Str::uuid(),
        ];

        $this->actingAs($operator)
            ->post(
                route(
                    'customers.collections.store',
                    $customer
                ),
                $payload
            )
            ->assertRedirect(
                route('customers.account', $customer)
            );

        $this->assertDatabaseCount('customer_collections', 1);
        $this->assertSame(
            60_000,
            app(CustomerReceivableBalanceReader::class)
                ->outstandingMinor($receivable)
        );

        $this->actingAs($viewer)
            ->get(route('customers.account', $customer))
            ->assertOk()
            ->assertSee('Cuenta corriente · CxC')
            ->assertDontSee('Registrar cobranza');

        $payload['idempotency_key'] =
            'customer-collection-ui:'.Str::uuid();

        $this->actingAs($viewer)
            ->post(
                route(
                    'customers.collections.store',
                    $customer
                ),
                $payload
            )
            ->assertForbidden();

        $this->assertDatabaseCount('customer_collections', 1);
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function customer(
        Organization $organization,
        string $name
    ): Customer {
        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_PERSON,
            'name' => $name,
        ]);

        return Customer::withoutEvents(
            fn () => Customer::query()->create([
                'organization_id' => $organization->id,
                'business_party_id' => $party->id,
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
            'current_organization_id' => $organization->id,
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

    private function account(
        User $actor,
        string $name,
        FinancialAccountType $type
    ): FinancialAccount {
        return app(FinancialAccountManager::class)
            ->create(
                $name,
                $type,
                'ARS',
                $actor
            );
    }

    private function receivable(
        Organization $organization,
        User $admin,
        Customer $customer,
        int $amountMinor
    ): CustomerReceivable {
        $this->productSequence++;

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()
                ->firstOrCreate(
                    ['slug' => 'p9-2-cxc-tests'],
                    [
                        'name' => 'P9.2 CxC tests',
                        'active' => true,
                    ]
                )
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'P92-CXC-'.$this->productSequence
                    .'-'.Str::lower(
                        Str::random(6)
                    ),
                'name' => 'Producto CxC P9.2 '
                    .$this->productSequence,
                'active' => true,
            ])->refresh()
        );

        $location = InventoryLocation::query()
            ->forOrganization($organization->id)
            ->orderBy('id')
            ->firstOrFail();

        $movement = app(
            InventoryMovementCreator::class
        )->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: CarbonImmutable::now(),
                reason: 'Ingreso P9.2 para prueba CxC.',
                idempotencyKey:
                    'p9.2:stock:'.Str::uuid(),
                lines: [
                    new InventoryMovementLineData(
                        catalogProductId: $product->id,
                        condition: InventoryCondition::New,
                        enteredQuantity: '1',
                        enteredUnitCode:
                            $product->base_unit_code,
                        destinationLocationId:
                            $location->id
                    ),
                ]
            ),
            $admin
        );

        app(InventoryMovementConfirmer::class)
            ->confirm($movement, $admin);

        $sale = app(CommerceCheckoutManager::class)
            ->checkout(
                new CommerceCheckoutData(
                    currencyCode: 'ARS',
                    idempotencyKey:
                        'p9.2:sale:'.Str::uuid(),
                    payments: [],
                    receivableAmountMinor: $amountMinor,
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
