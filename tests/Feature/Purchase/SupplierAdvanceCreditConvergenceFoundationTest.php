<?php

namespace Tests\Feature\Purchase;

use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Purchase\PurchaseObligationBalanceReader;
use App\Domain\Purchase\PurchaseObligationData;
use App\Domain\Purchase\PurchaseObligationManager;
use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchasePaymentRequestData;
use App\Domain\Purchase\PurchasePaymentRequestManager;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
use App\Domain\Purchase\SupplierAdvanceApplicationData;
use App\Domain\Purchase\SupplierAdvanceApplicationManager;
use App\Domain\Purchase\SupplierAdvanceExecutionData;
use App\Domain\Purchase\SupplierAdvanceManager;
use App\Domain\Purchase\SupplierAdvanceRequestData;
use App\Domain\Purchase\SupplierAdvanceRequestManager;
use App\Domain\Purchase\SupplierCreditApplicationData;
use App\Domain\Purchase\SupplierCreditApplicationManager;
use App\Domain\Purchase\SupplierCreditBalanceReader;
use App\Domain\Purchase\SupplierCreditNoteData;
use App\Domain\Purchase\SupplierCreditNoteManager;
use App\Domain\Purchase\SupplierInvoiceData;
use App\Domain\Purchase\SupplierInvoiceLineData;
use App\Domain\Purchase\SupplierInvoiceManager;
use App\Domain\Tenancy\CurrentOrganization;
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
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierAdvanceCreditConvergenceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_executed_advance_becomes_available_credit_without_automatic_netting(): void
    {
        $context = $this->context('reader');

        $credit = app(
            SupplierCreditBalanceReader::class
        )->read(
            $context['organization']->id,
            $context['supplier']->id,
            'ARS'
        );

        $balance = app(
            PurchaseObligationBalanceReader::class
        )->read($context['obligation']);

        $this->assertSame(
            900,
            $credit['source_minor']
        );
        $this->assertSame(
            0,
            $credit['applied_minor']
        );
        $this->assertSame(
            900,
            $credit['available_minor']
        );
        $this->assertSame(
            900,
            $credit['advance_source_minor']
        );
        $this->assertCount(
            1,
            $credit['advances']
        );
        $this->assertSame(
            2000,
            $balance['remaining_minor']
        );
        $this->assertSame(
            0,
            $balance[
                'supplier_advance_applied_minor'
            ]
        );
        $this->assertDatabaseCount(
            'supplier_advance_applications',
            0
        );
    }

    public function test_admin_applies_partial_advance_idempotently_without_second_money_effect(): void
    {
        $context = $this->context('partial');

        $before = [
            'cash' =>
                DB::table('cash_movements')->count(),
            'external' =>
                DB::table(
                    'financial_external_movements'
                )->count(),
            'executions' =>
                DB::table(
                    'purchase_payment_executions'
                )->count(),
        ];

        $data = new SupplierAdvanceApplicationData(
            supplierAdvanceId:
                $context['advance']->id,
            purchaseObligationId:
                $context['obligation']->id,
            amountMinor: 500,
            idempotencyKey:
                'p97g:partial',
            applicationNote:
                'Imputación parcial'
        );

        $manager = app(
            SupplierAdvanceApplicationManager::class
        );

        $application = $manager->apply(
            $data,
            $context['admin']
        );
        $retry = $manager->apply(
            $data,
            $context['admin']
        );

        $this->assertSame(
            $application->id,
            $retry->id
        );
        $this->assertDatabaseCount(
            'supplier_advance_applications',
            1
        );

        $credit = app(
            SupplierCreditBalanceReader::class
        )->read(
            $context['organization']->id,
            $context['supplier']->id,
            'ARS'
        );

        $this->assertSame(
            900,
            $credit['source_minor']
        );
        $this->assertSame(
            500,
            $credit['applied_minor']
        );
        $this->assertSame(
            400,
            $credit['available_minor']
        );

        $balance = app(
            PurchaseObligationBalanceReader::class
        )->read($context['obligation']);

        $this->assertSame(
            500,
            $balance[
                'supplier_credit_applied_minor'
            ]
        );
        $this->assertSame(
            500,
            $balance[
                'supplier_advance_applied_minor'
            ]
        );
        $this->assertSame(
            1500,
            $balance['remaining_minor']
        );

        $this->assertSame(
            $before['cash'],
            DB::table('cash_movements')->count()
        );
        $this->assertSame(
            $before['external'],
            DB::table(
                'financial_external_movements'
            )->count()
        );
        $this->assertSame(
            $before['executions'],
            DB::table(
                'purchase_payment_executions'
            )->count()
        );
    }

    public function test_note_and_advance_converge_into_one_payable_balance_and_active_request_blocks_more_credit(): void
    {
        $context = $this->context(
            'mixed',
            true
        );

        app(
            SupplierCreditApplicationManager::class
        )->apply(
            new SupplierCreditApplicationData(
                supplierCreditNoteId:
                    $context['creditNote']->id,
                purchaseObligationId:
                    $context['obligation']->id,
                amountMinor: 600,
                idempotencyKey:
                    'p97g:mixed:note'
            ),
            $context['admin']
        );

        app(
            SupplierAdvanceApplicationManager::class
        )->apply(
            new SupplierAdvanceApplicationData(
                supplierAdvanceId:
                    $context['advance']->id,
                purchaseObligationId:
                    $context['obligation']->id,
                amountMinor: 700,
                idempotencyKey:
                    'p97g:mixed:advance'
            ),
            $context['admin']
        );

        $balance = app(
            PurchaseObligationBalanceReader::class
        )->read($context['obligation']);

        $this->assertSame(
            600,
            $balance[
                'supplier_credit_note_applied_minor'
            ]
        );
        $this->assertSame(
            700,
            $balance[
                'supplier_advance_applied_minor'
            ]
        );
        $this->assertSame(
            1300,
            $balance[
                'supplier_credit_applied_minor'
            ]
        );
        $this->assertSame(
            700,
            $balance['remaining_minor']
        );

        $requests = app(
            PurchasePaymentRequestManager::class
        );

        $this->assertDomainFailure(
            fn () => $requests->request(
                new PurchasePaymentRequestData(
                    purchaseObligationId:
                        $context['obligation']->id,
                    originFinancialAccountId:
                        $context['account']->id,
                    amountMinor: 701,
                    requestNote: null,
                    idempotencyKey:
                        'p97g:mixed:too-high'
                ),
                $context['operator']
            )
        );

        $request = $requests->request(
            new PurchasePaymentRequestData(
                purchaseObligationId:
                    $context['obligation']->id,
                originFinancialAccountId:
                    $context['account']->id,
                amountMinor: 700,
                requestNote: null,
                idempotencyKey:
                    'p97g:mixed:exact'
            ),
            $context['operator']
        );

        $this->assertSame(
            700,
            $request->amount_minor
        );

        $this->assertDomainFailure(
            fn () => app(
                SupplierAdvanceApplicationManager::class
            )->apply(
                new SupplierAdvanceApplicationData(
                    supplierAdvanceId:
                        $context['advance']->id,
                    purchaseObligationId:
                        $context['obligation']->id,
                    amountMinor: 100,
                    idempotencyKey:
                        'p97g:mixed:block-after-request'
                ),
                $context['admin']
            )
        );
    }

    public function test_one_advance_can_be_split_across_multiple_obligations_explicitly(): void
    {
        $context = $this->context('split');

        $second = $this->purchase(
            $context,
            'split-second'
        );

        $manager = app(
            SupplierAdvanceApplicationManager::class
        );

        $manager->apply(
            new SupplierAdvanceApplicationData(
                supplierAdvanceId:
                    $context['advance']->id,
                purchaseObligationId:
                    $context['obligation']->id,
                amountMinor: 300,
                idempotencyKey:
                    'p97g:split:first'
            ),
            $context['admin']
        );

        $manager->apply(
            new SupplierAdvanceApplicationData(
                supplierAdvanceId:
                    $context['advance']->id,
                purchaseObligationId:
                    $second['obligation']->id,
                amountMinor: 600,
                idempotencyKey:
                    'p97g:split:second'
            ),
            $context['admin']
        );

        $credit = app(
            SupplierCreditBalanceReader::class
        )->read(
            $context['organization']->id,
            $context['supplier']->id,
            'ARS'
        );

        $this->assertSame(
            900,
            $credit['applied_minor']
        );
        $this->assertSame(
            0,
            $credit['available_minor']
        );
        $this->assertDatabaseCount(
            'supplier_advance_applications',
            2
        );

        $this->assertSame(
            1700,
            app(
                PurchaseObligationBalanceReader::class
            )->read(
                $context['obligation']
            )['remaining_minor']
        );
        $this->assertSame(
            1400,
            app(
                PurchaseObligationBalanceReader::class
            )->read(
                $second['obligation']
            )['remaining_minor']
        );
    }

    public function test_beneficiary_and_source_caps_fail_closed(): void
    {
        $context = $this->context('guards');

        $thirdParty = BusinessParty::query()
            ->create([
                'organization_id' =>
                    $context['organization']->id,
                'party_type' =>
                    BusinessParty::TYPE_ORGANIZATION,
                'name' =>
                    'Logística P9.7g '.Str::uuid(),
            ]);

        $logistics = app(
            PurchaseObligationManager::class
        )->recognize(
            new PurchaseObligationData(
                purchaseReceiptId:
                    $context['receipt']->id,
                kind:
                    PurchaseObligationKind::Logistics,
                beneficiaryBusinessPartyId:
                    $thirdParty->id,
                paymentCondition:
                    PurchaseObligationCondition::OnReceipt
            ),
            $context['admin']
        );

        $manager = app(
            SupplierAdvanceApplicationManager::class
        );

        $this->assertDomainFailure(
            fn () => $manager->apply(
                new SupplierAdvanceApplicationData(
                    supplierAdvanceId:
                        $context['advance']->id,
                    purchaseObligationId:
                        $logistics->id,
                    amountMinor: 100,
                    idempotencyKey:
                        'p97g:third-party'
                ),
                $context['admin']
            )
        );

        $this->assertDomainFailure(
            fn () => $manager->apply(
                new SupplierAdvanceApplicationData(
                    supplierAdvanceId:
                        $context['advance']->id,
                    purchaseObligationId:
                        $context['obligation']->id,
                    amountMinor: 901,
                    idempotencyKey:
                        'p97g:source-overdraw'
                ),
                $context['admin']
            )
        );

        $this->assertDatabaseCount(
            'supplier_advance_applications',
            0
        );
    }

    public function test_application_is_append_only_and_sqlite_guard_rejects_direct_source_overdraw(): void
    {
        $context = $this->context('immutable');

        $application = app(
            SupplierAdvanceApplicationManager::class
        )->apply(
            new SupplierAdvanceApplicationData(
                supplierAdvanceId:
                    $context['advance']->id,
                purchaseObligationId:
                    $context['obligation']->id,
                amountMinor: 800,
                idempotencyKey:
                    'p97g:immutable'
            ),
            $context['admin']
        );

        $this->assertDomainFailure(
            function () use ($application): void {
                $application->amount_minor = 1;
                $application->save();
            }
        );

        $this->assertDomainFailure(
            fn () => $application->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'supplier_advance_applications'
            )->insert([
                'organization_id' =>
                    $context['organization']->id,
                'public_id' =>
                    (string) Str::uuid(),
                'supplier_advance_id' =>
                    $context['advance']->id,
                'purchase_obligation_id' =>
                    $context['obligation']->id,
                'supplier_id' =>
                    $context['supplier']->id,
                'beneficiary_business_party_id' =>
                    $context['supplier']
                        ->business_party_id,
                'currency_code' => 'ARS',
                'amount_minor' => 200,
                'application_note' => null,
                'idempotency_key' =>
                    'p97g:forged-overdraw',
                'fingerprint' =>
                    str_repeat('a', 64),
                'applied_by_user_id' =>
                    $context['admin']->id,
                'applied_at' => now(),
                'created_at' => now(),
            ])
        );

        $this->assertTrue(
            Schema::hasTable(
                'supplier_advance_applications'
            )
        );
        $this->assertSame(
            800,
            SupplierAdvanceApplication::query()
                ->sole()
                ->amount_minor
        );
    }

    private function context(
        string $suffix,
        bool $withCreditNote = false
    ): array {
        $organization = Organization::query()
            ->create([
                'name' =>
                    'Org P9.7g '.$suffix,
                'slug' =>
                    'org-p97g-'.$suffix.'-'
                    .Str::lower(
                        Str::random(6)
                    ),
                'active' => true,
            ]);

        $admin = $this->member(
            $organization,
            UserRole::Admin
        );
        $operator = $this->member(
            $organization,
            UserRole::Operator
        );

        $party = BusinessParty::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'party_type' =>
                    BusinessParty::TYPE_ORGANIZATION,
                'name' =>
                    'Proveedor P9.7g '.$suffix,
            ]);

        $supplier = Supplier::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'business_party_id' =>
                    $party->id,
                'active' => true,
            ]);

        $this->actingAs($admin);

        $account = app(
            FinancialAccountManager::class
        )->create(
            'Banco P9.7g '.$suffix,
            FinancialAccountType::BankAccount,
            'ARS',
            $admin
        );

        $base = compact(
            'organization',
            'admin',
            'operator',
            'supplier',
            'account'
        );

        $purchase = $this->purchase(
            $base,
            $suffix
        );

        $this->actingAs($operator);

        $advanceRequest = app(
            SupplierAdvanceRequestManager::class
        )->request(
            new SupplierAdvanceRequestData(
                supplierId: $supplier->id,
                originFinancialAccountId:
                    $account->id,
                amountMinor: 900,
                idempotencyKey:
                    'p97g:advance-request:'.$suffix
            ),
            $operator
        );

        $this->actingAs($admin);

        app(
            SupplierAdvanceRequestManager::class
        )->approve(
            $advanceRequest,
            'Aprobado P9.7g',
            'p97g:advance-approve:'.$suffix,
            $admin
        );

        $this->actingAs($operator);

        $advance = app(
            SupplierAdvanceManager::class
        )->execute(
            $advanceRequest,
            new SupplierAdvanceExecutionData(
                idempotencyKey:
                    'p97g:advance-execute:'.$suffix,
                executionReference:
                    'TRF-P97G-'
                    .Str::upper(
                        Str::random(8)
                    )
            ),
            $operator
        );

        $creditNote = null;

        if ($withCreditNote) {
            $order = $purchase['order'];

            $invoice = app(
                SupplierInvoiceManager::class
            )->record(
                new SupplierInvoiceData(
                    purchaseOrderId: $order->id,
                    documentNumber:
                        'FAC-P97G-'
                        .Str::upper(
                            Str::random(10)
                        ),
                    issuedOn: '2026-08-17',
                    dueOn: null,
                    logisticsAmountMinor: 100,
                    lines: [
                        new SupplierInvoiceLineData(
                            purchaseOrderLineId:
                                $order->lines
                                    ->first()
                                    ->id,
                            description:
                                'Producto P9.7g',
                            quantity: '2',
                            unitCostMinor: 1000
                        ),
                    ],
                    idempotencyKey:
                        'p97g:invoice:'
                        .$suffix
                        .':'.Str::uuid()
                ),
                $operator
            );

            $creditNote = app(
                SupplierCreditNoteManager::class
            )->record(
                new SupplierCreditNoteData(
                    supplierInvoiceId:
                        $invoice->id,
                    documentNumber:
                        'NC-P97G-'
                        .Str::upper(
                            Str::random(10)
                        ),
                    issuedOn: '2026-08-18',
                    amountMinor: 600,
                    reason:
                        'Bonificación P9.7g',
                    idempotencyKey:
                        'p97g:credit-note:'
                        .$suffix
                        .':'.Str::uuid()
                ),
                $operator
            );
        }

        return array_merge(
            $base,
            $purchase,
            compact(
                'advanceRequest',
                'advance',
                'creditNote'
            )
        );
    }

    private function purchase(
        array $context,
        string $suffix
    ): array {
        $organization =
            $context['organization'];
        $admin = $context['admin'];
        $operator = $context['operator'];
        $supplier = $context['supplier'];

        $category = ProductCategory::query()
            ->firstOrCreate(
                ['slug' => 'p97g-tests'],
                [
                    'name' => 'Pruebas P9.7g',
                    'active' => true,
                ]
            );

        $product = CatalogProduct::query()
            ->create([
                'product_category_id' =>
                    $category->id,
                'sku' =>
                    'P97G-'.Str::upper(
                        Str::random(8)
                    ),
                'name' =>
                    'Producto P9.7g '.$suffix,
                'base_unit_code' => 'unit',
                'quantity_scale' => 0,
                'active' => true,
            ]);

        $location = InventoryLocation::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'name' =>
                    'Recepción P9.7g '
                    .Str::uuid(),
                'type' =>
                    InventoryLocationType::Receiving,
                'active' => true,
            ]);

        $orders = app(
            PurchaseOrderManager::class
        );

        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey:
                    'p97g:order:'
                    .$suffix
                    .':'.Str::uuid(),
                lines: [
                    new PurchaseOrderLineData(
                        $product->id,
                        '2',
                        1000
                    ),
                ],
                expectedLogisticsCostMinor:
                    100
            ),
            $operator
        );

        $order = $orders->issue(
            $order,
            $operator
        );
        $order->load('lines');

        $receipt = app(
            PurchaseReceiptManager::class
        )->receive(
            new PurchaseReceiptData(
                purchaseOrderId: $order->id,
                receivedAt:
                    CarbonImmutable::parse(
                        '2026-08-17 10:00:00',
                        'America/Argentina/Buenos_Aires'
                    ),
                idempotencyKey:
                    'p97g:receipt:'
                    .$suffix
                    .':'.Str::uuid(),
                lines: [
                    new PurchaseReceiptLineData(
                        purchaseOrderLineId:
                            $order->lines
                                ->first()
                                ->id,
                        quantity: '2',
                        inventoryLocationId:
                            $location->id,
                        condition:
                            InventoryCondition::New,
                        actualUnitCostMinor:
                            1000
                    ),
                ],
                logisticsCostMinor: 100,
                documentReference:
                    'REM-P97G-'
                    .Str::upper(
                        Str::random(8)
                    )
            ),
            $operator
        );

        $obligation = app(
            PurchaseObligationManager::class
        )->recognize(
            new PurchaseObligationData(
                purchaseReceiptId:
                    $receipt->id,
                kind:
                    PurchaseObligationKind::Merchandise,
                beneficiaryBusinessPartyId:
                    null,
                paymentCondition:
                    PurchaseObligationCondition::OnReceipt
            ),
            $admin
        );

        return compact(
            'product',
            'location',
            'order',
            'receipt',
            'obligation'
        );
    }

    private function member(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'user_id' => $user->id,
                'role' => $role,
                'active' => true,
            ]);

        $user->forceFill([
            'current_organization_id' =>
                $organization->id,
        ])->saveQuietly();

        app(CurrentOrganization::class)
            ->forget($user);

        return $user->refresh();
    }

    private function assertDomainFailure(
        callable $callback
    ): void {
        try {
            $callback();
            $this->fail(
                'Se esperaba una DomainException.'
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
                'Se esperaba rechazo de base de datos.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
