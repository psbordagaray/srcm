<?php

namespace Tests\Feature\Purchase;

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
use App\Domain\Purchase\SupplierCreditApplicationData;
use App\Domain\Purchase\SupplierCreditApplicationManager;
use App\Domain\Purchase\SupplierCreditBalanceReader;
use App\Domain\Purchase\SupplierCreditNoteData;
use App\Domain\Purchase\SupplierCreditNoteManager;
use App\Domain\Purchase\SupplierInvoiceData;
use App\Domain\Purchase\SupplierInvoiceLineData;
use App\Domain\Purchase\SupplierInvoiceManager;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\FinancialAccount;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\SupplierCreditApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierCreditApplicationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_authority_and_derived_balance_contract_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasTable('supplier_credit_applications')
        );
        $this->assertFalse(
            Schema::hasColumn(
                'supplier_credit_notes',
                'balance_minor'
            )
        );

        foreach ([
            'supplier_credit_note_id',
            'purchase_obligation_id',
            'supplier_id',
            'beneficiary_business_party_id',
            'currency_code',
            'amount_minor',
            'idempotency_key',
            'fingerprint',
            'applied_by_user_id',
            'applied_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn(
                    'supplier_credit_applications',
                    $column
                )
            );
        }

        $context = $this->context('authority');

        $this->assertDomainFailure(
            fn () => app(
                SupplierCreditApplicationManager::class
            )->apply(
                new SupplierCreditApplicationData(
                    supplierCreditNoteId:
                        $context['creditNote']->id,
                    purchaseObligationId:
                        $context['obligation']->id,
                    amountMinor: 100,
                    idempotencyKey:
                        'p97e:operator-block'
                ),
                $context['operator']
            )
        );
    }

    public function test_admin_applies_partial_credit_idempotently_without_money_or_inventory_effect(): void
    {
        $context = $this->context('partial');

        $before = [
            'inventory' =>
                DB::table('inventory_movements')->count(),
            'executions' =>
                DB::table(
                    'purchase_payment_executions'
                )->count(),
            'cash' =>
                DB::table('cash_movements')->count(),
            'external' =>
                DB::table(
                    'financial_external_movements'
                )->count(),
        ];

        $data = new SupplierCreditApplicationData(
            supplierCreditNoteId:
                $context['creditNote']->id,
            purchaseObligationId:
                $context['obligation']->id,
            amountMinor: 500,
            idempotencyKey: 'p97e:partial',
            applicationNote: 'Aplicación parcial'
        );

        $manager = app(
            SupplierCreditApplicationManager::class
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
            'supplier_credit_applications',
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
            1000,
            $credit['source_minor']
        );
        $this->assertSame(
            500,
            $credit['applied_minor']
        );
        $this->assertSame(
            500,
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
            1500,
            $balance['remaining_minor']
        );

        $this->assertSame(
            $before['inventory'],
            DB::table('inventory_movements')->count()
        );
        $this->assertSame(
            $before['executions'],
            DB::table(
                'purchase_payment_executions'
            )->count()
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
    }

    public function test_payment_request_uses_credit_aware_remaining_and_active_request_blocks_new_credit(): void
    {
        $context = $this->context('request');

        app(
            SupplierCreditApplicationManager::class
        )->apply(
            new SupplierCreditApplicationData(
                supplierCreditNoteId:
                    $context['creditNote']->id,
                purchaseObligationId:
                    $context['obligation']->id,
                amountMinor: 500,
                idempotencyKey:
                    'p97e:request:credit'
            ),
            $context['admin']
        );

        $origin = FinancialAccount::query()
            ->create([
                'organization_id' =>
                    $context['organization']->id,
                'name' =>
                    'Banco P9.7e '.Str::uuid(),
                'normalized_name' =>
                    'banco-p97e-'.Str::lower(
                        Str::random(8)
                    ),
                'type' =>
                    FinancialAccountType::BankAccount,
                'provider' => null,
                'currency_code' => 'ARS',
                'external_label' => null,
                'active' => true,
                'created_by_user_id' =>
                    $context['admin']->id,
                'updated_by_user_id' =>
                    $context['admin']->id,
            ]);

        $requests = app(
            PurchasePaymentRequestManager::class
        );

        $this->assertDomainFailure(
            fn () => $requests->request(
                new PurchasePaymentRequestData(
                    purchaseObligationId:
                        $context['obligation']->id,
                    originFinancialAccountId:
                        $origin->id,
                    amountMinor: 1600,
                    requestNote: null,
                    idempotencyKey:
                        'p97e:request:too-high'
                ),
                $context['operator']
            )
        );

        $request = $requests->request(
            new PurchasePaymentRequestData(
                purchaseObligationId:
                    $context['obligation']->id,
                originFinancialAccountId:
                    $origin->id,
                amountMinor: 1500,
                requestNote: null,
                idempotencyKey:
                    'p97e:request:exact'
            ),
            $context['operator']
        );

        $this->assertSame(
            1500,
            $request->amount_minor
        );

        $this->assertDomainFailure(
            fn () => app(
                SupplierCreditApplicationManager::class
            )->apply(
                new SupplierCreditApplicationData(
                    supplierCreditNoteId:
                        $context['creditNote']->id,
                    purchaseObligationId:
                        $context['obligation']->id,
                    amountMinor: 100,
                    idempotencyKey:
                        'p97e:request:block'
                ),
                $context['admin']
            )
        );
    }

    public function test_supplier_currency_beneficiary_and_source_caps_fail_closed(): void
    {
        $context = $this->context('guards');

        $thirdParty = BusinessParty::query()
            ->create([
                'organization_id' =>
                    $context['organization']->id,
                'party_type' =>
                    BusinessParty::TYPE_ORGANIZATION,
                'name' =>
                    'Fletero '.Str::uuid(),
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

        $this->assertDomainFailure(
            fn () => app(
                SupplierCreditApplicationManager::class
            )->apply(
                new SupplierCreditApplicationData(
                    supplierCreditNoteId:
                        $context['creditNote']->id,
                    purchaseObligationId:
                        $logistics->id,
                    amountMinor: 100,
                    idempotencyKey:
                        'p97e:third-party'
                ),
                $context['admin']
            )
        );

        $this->assertDomainFailure(
            fn () => app(
                SupplierCreditApplicationManager::class
            )->apply(
                new SupplierCreditApplicationData(
                    supplierCreditNoteId:
                        $context['creditNote']->id,
                    purchaseObligationId:
                        $context['obligation']->id,
                    amountMinor: 1100,
                    idempotencyKey:
                        'p97e:source-overdraw'
                ),
                $context['admin']
            )
        );
    }

    public function test_application_is_append_only_and_sqlite_guard_rejects_direct_overdraw(): void
    {
        $context = $this->context('immutable');

        $application = app(
            SupplierCreditApplicationManager::class
        )->apply(
            new SupplierCreditApplicationData(
                supplierCreditNoteId:
                    $context['creditNote']->id,
                purchaseObligationId:
                    $context['obligation']->id,
                amountMinor: 400,
                idempotencyKey:
                    'p97e:immutable'
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
                'supplier_credit_applications'
            )->insert([
                'organization_id' =>
                    $context['organization']->id,
                'public_id' =>
                    (string) Str::uuid(),
                'supplier_credit_note_id' =>
                    $context['creditNote']->id,
                'purchase_obligation_id' =>
                    $context['obligation']->id,
                'supplier_id' =>
                    $context['supplier']->id,
                'beneficiary_business_party_id' =>
                    $context['supplier']
                        ->business_party_id,
                'currency_code' => 'ARS',
                'amount_minor' => 700,
                'application_note' => null,
                'idempotency_key' =>
                    'p97e:forged-overdraw',
                'fingerprint' =>
                    str_repeat('a', 64),
                'applied_by_user_id' =>
                    $context['admin']->id,
                'applied_at' => now(),
                'created_at' => now(),
            ])
        );
    }

    private function context(
        string $suffix
    ): array {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $operator = $this->user(
            $organization,
            UserRole::Operator,
            $suffix.'-operator'
        );
        $admin = $this->user(
            $organization,
            UserRole::Admin,
            $suffix.'-admin'
        );
        $supplier = $this->supplier(
            $organization,
            $suffix
        );
        $product = $this->product($suffix);

        $location = InventoryLocation::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'name' =>
                    'Recepción P9.7e '.Str::uuid(),
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
                    'p97e:order:'.$suffix,
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
                    'p97e:receipt:'.$suffix,
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
                    'REM-P97E-'
                    .Str::upper(
                        Str::random(8)
                    )
            ),
            $operator
        );

        $invoice = app(
            SupplierInvoiceManager::class
        )->record(
            new SupplierInvoiceData(
                purchaseOrderId: $order->id,
                documentNumber:
                    'FAC-P97E-'
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
                            'Producto P9.7e',
                        quantity: '2',
                        unitCostMinor: 1000
                    ),
                ],
                idempotencyKey:
                    'p97e:invoice:'
                    .$suffix
                    .':'.Str::uuid()
            ),
            $operator
        );

        $creditNote = app(
            SupplierCreditNoteManager::class
        )->record(
            new SupplierCreditNoteData(
                supplierInvoiceId: $invoice->id,
                documentNumber:
                    'NC-P97E-'
                    .Str::upper(
                        Str::random(10)
                    ),
                issuedOn: '2026-08-18',
                amountMinor: 1000,
                reason:
                    'Bonificación P9.7e',
                idempotencyKey:
                    'p97e:credit-note:'
                    .$suffix
                    .':'.Str::uuid()
            ),
            $operator
        );

        $obligation = app(
            PurchaseObligationManager::class
        )->recognize(
            new PurchaseObligationData(
                purchaseReceiptId: $receipt->id,
                kind:
                    PurchaseObligationKind::Merchandise,
                beneficiaryBusinessPartyId: null,
                paymentCondition:
                    PurchaseObligationCondition::OnReceipt
            ),
            $admin
        );

        return compact(
            'organization',
            'operator',
            'admin',
            'supplier',
            'product',
            'location',
            'order',
            'receipt',
            'invoice',
            'creditNote',
            'obligation'
        );
    }

    private function user(
        Organization $organization,
        UserRole $role,
        string $suffix
    ): User {
        $user = User::query()->create([
            'name' =>
                $role->label().' '.$suffix,
            'email' =>
                Str::uuid().'@p97e.test',
            'password' =>
                Hash::make('password'),
        ]);

        $user->forceFill([
            'role' => $role,
            'current_organization_id' =>
                $organization->id,
            'email_verified_at' => now(),
        ])->saveQuietly();

        OrganizationMembership::query()
            ->updateOrCreate(
                [
                    'organization_id' =>
                        $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role->value,
                    'active' => true,
                ]
            );

        return $user->refresh();
    }

    private function supplier(
        Organization $organization,
        string $suffix
    ): Supplier {
        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' =>
                BusinessParty::TYPE_ORGANIZATION,
            'name' =>
                'Proveedor P9.7e '
                .$suffix.' '.Str::uuid(),
        ]);

        return Supplier::query()->create([
            'organization_id' => $organization->id,
            'business_party_id' => $party->id,
            'active' => true,
        ]);
    }

    private function product(
        string $suffix
    ): CatalogProduct {
        $category = ProductCategory::query()
            ->firstOrCreate(
                ['slug' => 'p97e-tests'],
                [
                    'name' => 'Pruebas P9.7e',
                    'active' => true,
                ]
            );

        return CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'sku' =>
                'P97E-'.Str::upper(
                    Str::random(8)
                ),
            'name' =>
                'Producto crédito proveedor '
                .$suffix,
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
            'active' => true,
        ]);
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
