<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Commerce\CustomerCollectionAllocationData;
use App\Domain\Commerce\CustomerCollectionData;
use App\Domain\Commerce\CustomerCollectionManager;
use App\Domain\Commerce\CustomerReceivableAgingReader;
use App\Domain\Commerce\CustomerReceivableBalanceReader;
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
use App\Models\CustomerReceivable;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerReceivableAgingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private int $productSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-16 12:00:00')
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_aging_contract_is_read_only_and_route_is_explicit(): void
    {
        $this->assertTrue(
            Schema::hasTable('customer_receivables')
        );
        $this->assertFalse(
            Schema::hasTable('customer_receivable_aging')
        );
        $this->assertFalse(
            Schema::hasTable('customer_aging_snapshots')
        );

        $this->assertTrue(Route::has('customers.aging'));

        $labels = app(
            CustomerReceivableAgingReader::class
        )->bucketLabels();

        $this->assertSame(
            [
                'current',
                'overdue_1_30',
                'overdue_31_60',
                'overdue_61_90',
                'overdue_91_plus',
                'undated',
                'settled',
            ],
            array_keys($labels)
        );
    }

    public function test_reader_classifies_standard_aging_buckets_deterministically(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente buckets aging'
        );

        $soldAt = CarbonImmutable::parse('2026-01-01 10:00:00');

        $receivables = [
            'current' => $this->receivable(
                $organization,
                $admin,
                $customer,
                10_000,
                CarbonImmutable::parse('2026-08-16'),
                $soldAt
            ),
            'overdue_1_30' => $this->receivable(
                $organization,
                $admin,
                $customer,
                20_000,
                CarbonImmutable::parse('2026-08-06'),
                $soldAt
            ),
            'overdue_31_60' => $this->receivable(
                $organization,
                $admin,
                $customer,
                30_000,
                CarbonImmutable::parse('2026-07-02'),
                $soldAt
            ),
            'overdue_61_90' => $this->receivable(
                $organization,
                $admin,
                $customer,
                40_000,
                CarbonImmutable::parse('2026-06-02'),
                $soldAt
            ),
            'overdue_91_plus' => $this->receivable(
                $organization,
                $admin,
                $customer,
                50_000,
                CarbonImmutable::parse('2026-04-18'),
                $soldAt
            ),
            'undated' => $this->receivable(
                $organization,
                $admin,
                $customer,
                60_000,
                null,
                $soldAt
            ),
        ];

        $rows = app(
            CustomerReceivableAgingReader::class
        )
            ->rowsForCustomer($customer, $admin)
            ->keyBy(
                fn (array $row): int =>
                    $row['receivable']->id
            );

        foreach ($receivables as $bucket => $receivable) {
            $this->assertSame(
                $bucket,
                $rows[$receivable->id]['aging_bucket']
            );
        }

        $this->assertSame(
            10,
            $rows[
                $receivables['overdue_1_30']->id
            ]['days_overdue']
        );
        $this->assertSame(
            45,
            $rows[
                $receivables['overdue_31_60']->id
            ]['days_overdue']
        );
        $this->assertNull(
            $rows[
                $receivables['undated']->id
            ]['days_overdue']
        );
    }

    public function test_partial_collection_changes_exposure_without_rewriting_debt(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente aging parcial'
        );

        $receivable = $this->receivable(
            $organization,
            $admin,
            $customer,
            100_000,
            CarbonImmutable::parse('2026-08-06'),
            CarbonImmutable::parse('2026-07-01 10:00:00')
        );

        $bank = app(FinancialAccountManager::class)
            ->create(
                'Banco aging',
                FinancialAccountType::BankAccount,
                'ARS',
                $admin
            );

        app(CustomerCollectionManager::class)->collect(
            $customer,
            new CustomerCollectionData(
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
                idempotencyKey: 'p9.3:aging:partial',
                reference: 'AGING-PARTIAL'
            ),
            $admin
        );

        $report = app(
            CustomerReceivableAgingReader::class
        )->report($admin);

        $this->assertSame(
            100_000,
            $receivable->refresh()->amount_minor
        );
        $this->assertSame(
            60_000,
            $report['totals']['ARS']['outstanding_minor']
        );
        $this->assertSame(
            60_000,
            $report['totals']['ARS']['overdue_minor']
        );
        $this->assertSame(
            60_000,
            $report['totals']['ARS']['buckets'][
                'overdue_1_30'
            ]
        );
        $this->assertDatabaseCount(
            'customer_receivables',
            1
        );
    }

    public function test_report_aggregates_exposure_by_customer_and_currency(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );

        $firstCustomer = $this->customer(
            $organization,
            'Cliente aging A'
        );
        $secondCustomer = $this->customer(
            $organization,
            'Cliente aging B'
        );

        $this->receivable(
            $organization,
            $admin,
            $firstCustomer,
            80_000,
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-06-01 10:00:00')
        );

        $this->receivable(
            $organization,
            $admin,
            $secondCustomer,
            120_000,
            CarbonImmutable::parse('2026-08-20'),
            CarbonImmutable::parse('2026-06-01 10:00:00')
        );

        $report = app(
            CustomerReceivableAgingReader::class
        )->report($admin);

        $this->assertSame(
            200_000,
            $report['totals']['ARS']['outstanding_minor']
        );
        $this->assertSame(
            80_000,
            $report['totals']['ARS']['overdue_minor']
        );
        $this->assertCount(2, $report['customers']);
        $this->assertSame(
            'Cliente aging A',
            $report['customers']->first()['party']->name
        );
        $this->assertSame(
            46,
            $report['customers']->first()[
                'oldest_days_overdue'
            ]
        );
    }

    public function test_fully_settled_debt_leaves_open_aging_but_stays_auditable(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $customer = $this->customer(
            $organization,
            'Cliente aging cancelado'
        );

        $receivable = $this->receivable(
            $organization,
            $admin,
            $customer,
            50_000,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-07-01 10:00:00')
        );

        $bank = app(FinancialAccountManager::class)
            ->create(
                'Banco aging cancelado',
                FinancialAccountType::BankAccount,
                'ARS',
                $admin
            );

        app(CustomerCollectionManager::class)->collect(
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
                idempotencyKey: 'p9.3:aging:settled',
                reference: 'AGING-SETTLED'
            ),
            $admin
        );

        $report = app(
            CustomerReceivableAgingReader::class
        )->report($admin);

        $account = app(
            CustomerReceivableBalanceReader::class
        )->read($customer, $admin);

        $this->assertCount(0, $report['receivables']);
        $this->assertCount(0, $report['customers']);
        $this->assertCount(0, $report['totals']);

        $row = $account['receivables']->sole();

        $this->assertSame(0, $row['outstanding_minor']);
        $this->assertSame(
            'settled',
            $row['aging_bucket']
        );
        $this->assertSame(
            'Cancelado',
            $row['aging_label']
        );
    }

    public function test_viewer_reads_global_and_customer_aging_without_mutation(): void
    {
        $organization = $this->organization();
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $viewer = $this->user(
            $organization,
            UserRole::Viewer
        );
        $customer = $this->customer(
            $organization,
            'Cliente aging HTTP'
        );

        $this->receivable(
            $organization,
            $admin,
            $customer,
            70_000,
            CarbonImmutable::parse('2026-08-06'),
            CarbonImmutable::parse('2026-07-01 10:00:00')
        );

        $this->actingAs($viewer)
            ->get(route('customers.aging'))
            ->assertOk()
            ->assertSee('Antigüedad de cuentas por cobrar')
            ->assertSee('Vencido 1–30 días')
            ->assertSee('Cliente aging HTTP');

        $this->actingAs($viewer)
            ->get(route('customers.account', $customer))
            ->assertOk()
            ->assertSee('Reporte de aging')
            ->assertSee('Vencido 1–30 días');

        $this->assertDatabaseCount(
            'customer_receivables',
            1
        );
        $this->assertDatabaseCount(
            'customer_collections',
            0
        );
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

    private function receivable(
        Organization $organization,
        User $admin,
        Customer $customer,
        int $amountMinor,
        ?CarbonImmutable $dueOn,
        CarbonImmutable $soldAt
    ): CustomerReceivable {
        $this->productSequence++;

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()
                ->firstOrCreate(
                    ['slug' => 'p9-3-aging-tests'],
                    [
                        'name' => 'P9.3 Aging tests',
                        'active' => true,
                    ]
                )
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'P93-AGING-'
                    .$this->productSequence
                    .'-'
                    .Str::lower(Str::random(6)),
                'name' => 'Producto aging P9.3 '
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
                effectiveAt: $soldAt->subDay(),
                reason: 'Ingreso P9.3 para prueba aging.',
                idempotencyKey:
                    'p9.3:aging:stock:'.Str::uuid(),
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
                        'p9.3:aging:sale:'.Str::uuid(),
                    payments: [],
                    receivableAmountMinor: $amountMinor,
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
                    soldAt: $soldAt
                ),
                $admin
            );

        return $sale->receivable;
    }
}
