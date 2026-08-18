<?php

namespace Tests\Feature\Purchase;

use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Purchase\PurchaseObligationBalanceReader;
use App\Domain\Purchase\PurchaseObligationData;
use App\Domain\Purchase\PurchaseObligationManager;
use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchasePaymentDisbursementManager;
use App\Domain\Purchase\PurchasePaymentRequestData;
use App\Domain\Purchase\PurchasePaymentRequestManager;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
use App\Domain\Purchase\SupplierPayableAgingReader;
use App\Domain\Purchase\SupplierPayableStatementReader;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierPayableExposureAgingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-08-17 12:00:00',
                'UTC'
            )
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_contract_is_read_only_routes_and_buckets_are_explicit(): void
    {
        $this->assertFalse(
            Schema::hasTable('supplier_payable_aging')
        );
        $this->assertFalse(
            Schema::hasTable(
                'supplier_payable_aging_snapshots'
            )
        );
        $this->assertTrue(
            Route::has('supplier-payables.aging')
        );
        $this->assertTrue(
            Route::has('suppliers.account')
        );

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
            array_keys(
                app(
                    SupplierPayableAgingReader::class
                )->bucketLabels()
            )
        );
    }

    public function test_due_date_on_receipt_and_other_are_classified_deterministically(): void
    {
        $dueContext = $this->context(
            'due-date',
            receivedAt: '2026-08-01 12:00:00'
        );
        $due = $this->obligation(
            $dueContext,
            PurchaseObligationCondition::DueDate,
            dueOn: '2026-08-07'
        );
        $receiptContext = $this->context(
            'on-receipt',
            receivedAt: '2026-06-01 12:00:00'
        );
        $onReceipt = $this->obligation(
            $receiptContext,
            PurchaseObligationCondition::OnReceipt
        );
        $otherContext = $this->context('other');
        $other = $this->obligation(
            $otherContext,
            PurchaseObligationCondition::Other,
            conditionNote: 'Pago sujeto a condición documentada.'
        );

        $rows = app(SupplierPayableAgingReader::class)
            ->rowsForOrganization($dueContext['admin'])
            ->keyBy('obligation.id');

        $this->assertSame(
            'overdue_1_30',
            $rows[$due->id]['aging_bucket']
        );
        $this->assertSame(
            10,
            $rows[$due->id]['days_overdue']
        );
        $this->assertSame(
            'due_date',
            $rows[$due->id]['due_source']
        );
        $this->assertSame(
            '2026-08-07',
            $rows[$due->id]['effective_due_on']
                ->format('Y-m-d')
        );
        $this->assertSame(
            'overdue_61_90',
            $rows[$onReceipt->id]['aging_bucket']
        );
        $this->assertSame(
            77,
            $rows[$onReceipt->id]['days_overdue']
        );
        $this->assertSame(
            'on_receipt',
            $rows[$onReceipt->id]['due_source']
        );
        $this->assertSame(
            'undated',
            $rows[$other->id]['aging_bucket']
        );
        $this->assertNull(
            $rows[$other->id]['effective_due_on']
        );
    }

    public function test_report_separates_supplier_beneficiary_and_currency(): void
    {
        $ars = $this->context(
            'dimensions-ars',
            logisticsCostMinor: 500
        );
        $this->obligation(
            $ars,
            PurchaseObligationCondition::OnReceipt
        );
        $carrier = BusinessParty::query()->create([
            'organization_id' => $ars['organization']->id,
            'party_type' => BusinessParty::TYPE_ORGANIZATION,
            'name' => 'Transportista P9.8',
        ]);
        $this->obligation(
            $ars,
            PurchaseObligationCondition::DueDate,
            PurchaseObligationKind::Logistics,
            $carrier->id,
            '2026-09-01'
        );
        $usd = $this->context(
            'dimensions-usd',
            currencyCode: 'USD'
        );
        $this->obligation(
            $usd,
            PurchaseObligationCondition::OnReceipt
        );

        $report = app(SupplierPayableAgingReader::class)
            ->report($ars['admin']);

        $this->assertSame(
            2500,
            $report['totals']['ARS'][
                'outstanding_minor'
            ]
        );
        $this->assertSame(
            2000,
            $report['totals']['USD'][
                'outstanding_minor'
            ]
        );
        $this->assertCount(3, $report['suppliers']);
        $this->assertCount(
            2,
            $report['suppliers']->where(
                'currency_code',
                'ARS'
            )
        );
        $this->assertSame(
            2,
            $report['totals']['ARS'][
                'obligation_count'
            ]
        );
    }

    public function test_partial_canonical_disbursement_reduces_exposure_and_creates_statement_credit(): void
    {
        $context = $this->context('partial');
        $obligation = $this->obligation(
            $context,
            PurchaseObligationCondition::OnReceipt
        );

        $this->executeDisbursement(
            $context,
            $obligation,
            800,
            'partial'
        );

        $row = app(SupplierPayableAgingReader::class)
            ->rowsForSupplier(
                $context['supplier'],
                $context['admin']
            )
            ->sole();
        $statement = app(
            SupplierPayableStatementReader::class
        )->read(
            $context['supplier'],
            $context['admin']
        );

        $this->assertSame(
            800,
            $row['disbursement_execution_minor']
        );
        $this->assertSame(
            1200,
            $row['outstanding_minor']
        );
        $this->assertSame(
            [
                'obligation',
                'disbursement_allocation',
            ],
            $statement['entries']->pluck('type')->all()
        );
        $this->assertSame(
            1200,
            $statement['entries']->last()[
                'running_balance_minor'
            ]
        );
        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_settled_obligation_leaves_open_report_but_remains_statement_history(): void
    {
        $context = $this->context('settled');
        $obligation = $this->obligation(
            $context,
            PurchaseObligationCondition::OnReceipt
        );
        $this->executeDisbursement(
            $context,
            $obligation,
            2000,
            'settled'
        );

        $rows = app(SupplierPayableAgingReader::class)
            ->rowsForSupplier(
                $context['supplier'],
                $context['admin']
            );
        $report = app(SupplierPayableAgingReader::class)
            ->report($context['admin']);
        $statement = app(
            SupplierPayableStatementReader::class
        )->read(
            $context['supplier'],
            $context['admin']
        );

        $this->assertSame(
            'settled',
            $rows->sole()['aging_bucket']
        );
        $this->assertTrue(
            $report['obligations']->isEmpty()
        );
        $this->assertCount(
            1,
            $statement['obligations']
        );
        $this->assertCount(
            2,
            $statement['entries']
        );
        $this->assertSame(
            0,
            $statement['totals']['ARS'][
                'outstanding_minor'
            ]
        );
    }

    public function test_viewer_reads_global_and_supplier_account_without_mutation(): void
    {
        $context = $this->context('http');
        $this->obligation(
            $context,
            PurchaseObligationCondition::OnReceipt
        );
        $viewer = $this->member(
            $context['organization'],
            UserRole::Viewer,
            'http-viewer'
        );

        $this->actingAs($viewer)
            ->get(route('supplier-payables.aging'))
            ->assertOk()
            ->assertSee('Exposición y aging de proveedores')
            ->assertSee(
                $context['supplier']->party->name
            );

        $this->actingAs($viewer)
            ->get(route(
                'suppliers.account',
                $context['supplier']
            ))
            ->assertOk()
            ->assertSee('Estado de cuenta')
            ->assertSee('Obligación reconocida');

        $other = Organization::query()->create([
            'name' => 'Otra organización P9.8',
            'slug' => 'otra-p98-'.Str::lower(
                Str::random(8)
            ),
            'active' => true,
        ]);
        $foreignParty = BusinessParty::query()->create([
            'organization_id' => $other->id,
            'party_type' => BusinessParty::TYPE_ORGANIZATION,
            'name' => 'Proveedor ajeno P9.8',
        ]);
        $foreignSupplier = Supplier::query()->create([
            'organization_id' => $other->id,
            'business_party_id' => $foreignParty->id,
            'active' => true,
        ]);

        $this->get(route(
            'suppliers.account',
            $foreignSupplier
        ))->assertNotFound();
        $this->assertDatabaseCount(
            'purchase_obligations',
            1
        );
        $this->assertDatabaseCount(
            'purchase_payment_disbursements',
            0
        );
    }

    public function test_authorization_without_disbursement_does_not_change_exposure(): void
    {
        $context = $this->context('authorization');
        $obligation = $this->obligation(
            $context,
            PurchaseObligationCondition::OnReceipt
        );
        $account = $this->bank($context, 'authorization');

        $this->actingAs($context['operator']);
        $request = app(PurchasePaymentRequestManager::class)
            ->request(
                new PurchasePaymentRequestData(
                    purchaseObligationId: $obligation->id,
                    originFinancialAccountId: $account->id,
                    amountMinor: 1000,
                    requestNote: 'Autorización sin pago',
                    idempotencyKey:
                        'p98:request:authorization'
                ),
                $context['operator']
            );
        $this->actingAs($context['admin']);
        app(PurchasePaymentRequestManager::class)
            ->approve(
                $request,
                'Autorizada, aún no ejecutada',
                'p98:approve:authorization',
                $context['admin']
            );

        $row = app(SupplierPayableAgingReader::class)
            ->rowsForSupplier(
                $context['supplier'],
                $context['admin']
            )
            ->sole();
        $statement = app(
            SupplierPayableStatementReader::class
        )->read(
            $context['supplier'],
            $context['admin']
        );

        $this->assertSame(
            2000,
            $row['outstanding_minor']
        );
        $this->assertSame(
            ['obligation'],
            $statement['entries']->pluck('type')->all()
        );
        $this->assertDatabaseCount(
            'purchase_payment_disbursements',
            0
        );
        $this->assertSame(
            2000,
            app(PurchaseObligationBalanceReader::class)
                ->read($obligation)['remaining_minor']
        );
    }

    /** @return array<string,mixed> */
    private function context(
        string $suffix,
        int $logisticsCostMinor = 0,
        string $currencyCode = 'ARS',
        string $receivedAt = '2026-08-10 12:00:00'
    ): array {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
        $admin = $this->member(
            $organization,
            UserRole::Admin,
            $suffix.'-admin'
        );
        $operator = $this->member(
            $organization,
            UserRole::Operator,
            $suffix.'-operator'
        );
        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_ORGANIZATION,
            'name' => 'Proveedor P9.8 '.$suffix,
        ]);
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'business_party_id' => $party->id,
            'active' => true,
        ])->load('party');
        $category = ProductCategory::query()->firstOrCreate(
            ['slug' => 'p98-aging-tests'],
            [
                'name' => 'Pruebas P9.8',
                'active' => true,
            ]
        );
        $product = CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'sku' => 'P98-'.Str::upper(
                Str::random(10)
            ),
            'name' => 'Producto P9.8 '.$suffix,
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
            'active' => true,
        ]);
        $location = InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Recepción P9.8 '.Str::uuid(),
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        $this->actingAs($operator);
        $orders = app(PurchaseOrderManager::class);
        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: $currencyCode,
                idempotencyKey:
                    'p98:order:'.$suffix,
                lines: [
                    new PurchaseOrderLineData(
                        $product->id,
                        '2',
                        1000
                    ),
                ]
            ),
            $operator
        );
        $order = $orders->issue($order, $operator);
        $receipt = app(PurchaseReceiptManager::class)
            ->receive(
                new PurchaseReceiptData(
                    purchaseOrderId: $order->id,
                    receivedAt: CarbonImmutable::parse(
                        $receivedAt,
                        'UTC'
                    ),
                    idempotencyKey:
                        'p98:receipt:'.$suffix,
                    lines: [
                        new PurchaseReceiptLineData(
                            purchaseOrderLineId:
                                $order->lines->first()->id,
                            quantity: '2',
                            inventoryLocationId: $location->id,
                            condition: InventoryCondition::New,
                            actualUnitCostMinor: 1000
                        ),
                    ],
                    logisticsCostMinor: $logisticsCostMinor,
                    documentReference:
                        'REC-P98-'.Str::upper(
                            Str::random(10)
                        )
                ),
                $operator
            );

        return compact(
            'organization',
            'admin',
            'operator',
            'supplier',
            'product',
            'location',
            'order',
            'receipt',
            'currencyCode'
        );
    }

    private function obligation(
        array $context,
        PurchaseObligationCondition $condition,
        PurchaseObligationKind $kind =
            PurchaseObligationKind::Merchandise,
        ?int $beneficiaryId = null,
        ?string $dueOn = null,
        ?string $conditionNote = null
    ) {
        $this->actingAs($context['admin']);

        return app(PurchaseObligationManager::class)
            ->recognize(
                new PurchaseObligationData(
                    purchaseReceiptId:
                        $context['receipt']->id,
                    kind: $kind,
                    beneficiaryBusinessPartyId:
                        $beneficiaryId,
                    paymentCondition: $condition,
                    dueOn: $dueOn,
                    conditionNote: $conditionNote
                ),
                $context['admin']
            );
    }

    private function executeDisbursement(
        array $context,
        $obligation,
        int $amountMinor,
        string $suffix
    ): void {
        $account = $this->bank($context, $suffix);
        $this->actingAs($context['operator']);
        $request = app(PurchasePaymentRequestManager::class)
            ->request(
                new PurchasePaymentRequestData(
                    purchaseObligationId: $obligation->id,
                    originFinancialAccountId: $account->id,
                    amountMinor: $amountMinor,
                    requestNote: null,
                    idempotencyKey: 'p98:request:'.$suffix
                ),
                $context['operator']
            );
        $this->actingAs($context['admin']);
        $request = app(PurchasePaymentRequestManager::class)
            ->approve(
                $request,
                'Aprobación P9.8',
                'p98:approve:'.$suffix,
                $context['admin']
            );
        $this->actingAs($context['operator']);
        app(PurchasePaymentDisbursementManager::class)
            ->executeIndividual(
                $request,
                'TRF-P98-'.Str::upper($suffix),
                'Desembolso P9.8',
                'p98:execute:'.$suffix,
                $context['operator']
            );
    }

    private function bank(array $context, string $suffix)
    {
        $this->actingAs($context['admin']);

        return app(FinancialAccountManager::class)
            ->create(
                'Banco P9.8 '.$suffix,
                FinancialAccountType::BankAccount,
                $context['currencyCode'],
                $context['admin']
            );
    }

    private function member(
        Organization $organization,
        UserRole $role,
        string $suffix
    ): User {
        $user = User::query()->create([
            'name' => $role->label().' '.$suffix,
            'email' => Str::uuid().'@p98.test',
            'password' => Hash::make('password'),
        ]);
        $user->forceFill([
            'role' => $role,
            'current_organization_id' => $organization->id,
            'email_verified_at' => now(),
        ])->saveQuietly();
        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            [
                'role' => $role->value,
                'active' => true,
            ]
        );

        return $user->refresh();
    }
}
