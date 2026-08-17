<?php

namespace Tests\Feature\Purchase;

use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\SupplierInvoiceData;
use App\Domain\Purchase\SupplierInvoiceLineData;
use App\Domain\Purchase\SupplierInvoiceManager;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierInvoiceFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_route_and_non_effect_contract_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasTable('supplier_invoices')
        );
        $this->assertTrue(
            Schema::hasTable('supplier_invoice_lines')
        );
        $this->assertTrue(
            Route::has(
                'purchase-orders.supplier-invoices.store'
            )
        );
        $this->assertFalse(
            Schema::hasTable(
                'purchase_three_way_matches'
            )
        );

        foreach ([
            'purchase_order_id',
            'supplier_id',
            'document_number',
            'normalized_document_number',
            'issued_on',
            'due_on',
            'currency_code',
            'merchandise_total_minor',
            'logistics_amount_minor',
            'total_minor',
            'idempotency_key',
            'fingerprint',
            'recorded_by_user_id',
            'recorded_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn(
                    'supplier_invoices',
                    $column
                )
            );
        }
    }

    public function test_operator_records_exact_invoice_idempotently_without_money_stock_or_obligation_effect(): void
    {
        $context = $this->context('exact');

        $before = [
            'receipts' =>
                DB::table('purchase_receipts')->count(),
            'movements' =>
                DB::table('inventory_movements')->count(),
            'obligations' =>
                DB::table('purchase_obligations')->count(),
            'cash' =>
                DB::table('cash_movements')->count(),
            'external' =>
                DB::table(
                    'financial_external_movements'
                )->count(),
        ];

        $data = $this->invoiceData(
            $context,
            'FAC-A-0001-00000001',
            '2',
            1000,
            150
        );

        $invoice = app(SupplierInvoiceManager::class)
            ->record(
                $data,
                $context['operator']
            );

        $this->assertSame(
            2000,
            $invoice->merchandise_total_minor
        );
        $this->assertSame(
            150,
            $invoice->logistics_amount_minor
        );
        $this->assertSame(
            2150,
            $invoice->total_minor
        );
        $this->assertSame(
            'ARS',
            $invoice->currency_code
        );
        $this->assertCount(
            1,
            $invoice->lines
        );

        $retry = app(SupplierInvoiceManager::class)
            ->record(
                $data,
                $context['operator']
            );

        $this->assertSame(
            $invoice->id,
            $retry->id
        );
        $this->assertDatabaseCount(
            'supplier_invoices',
            1
        );
        $this->assertDatabaseCount(
            'supplier_invoice_lines',
            1
        );

        $this->assertSame(
            $before['receipts'],
            DB::table('purchase_receipts')->count()
        );
        $this->assertSame(
            $before['movements'],
            DB::table('inventory_movements')->count()
        );
        $this->assertSame(
            $before['obligations'],
            DB::table('purchase_obligations')->count()
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

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' =>
                $context['organization']->id,
            'event' => 'supplier_invoice.recorded',
            'auditable_id' => (string) $invoice->id,
        ]);
    }

    public function test_document_preserves_order_differences_and_unmatched_lines_for_future_match(): void
    {
        $context = $this->context('difference');

        $invoice = app(SupplierInvoiceManager::class)
            ->record(
                new SupplierInvoiceData(
                    purchaseOrderId:
                        $context['order']->id,
                    documentNumber:
                        'FAC-A-0001-00000002',
                    issuedOn: '2026-08-17',
                    dueOn: '2026-09-16',
                    logisticsAmountMinor: 275,
                    lines: [
                        new SupplierInvoiceLineData(
                            purchaseOrderLineId:
                                $context['order']
                                    ->lines
                                    ->first()
                                    ->id,
                            description:
                                'Producto facturado con diferencia',
                            quantity: '3',
                            unitCostMinor: 1250,
                            supplierCode: 'SUP-DIFF'
                        ),
                        new SupplierInvoiceLineData(
                            purchaseOrderLineId: null,
                            description:
                                'Cargo adicional no vinculado',
                            quantity: '1',
                            unitCostMinor: 400
                        ),
                    ],
                    idempotencyKey:
                        'p97a:difference'
                ),
                $context['operator']
            );

        $this->assertSame(
            4150,
            $invoice->merchandise_total_minor
        );
        $this->assertSame(
            4425,
            $invoice->total_minor
        );
        $this->assertCount(2, $invoice->lines);
        $this->assertSame(
            '3.000000',
            $invoice->lines[0]->quantity
        );
        $this->assertSame(
            1250,
            $invoice->lines[0]->unit_cost_minor
        );
        $this->assertNull(
            $invoice->lines[1]
                ->purchase_order_line_id
        );
        $this->assertNull(
            $invoice->lines[1]
                ->catalog_product_id
        );
        $this->assertFalse(
            Schema::hasTable(
                'purchase_three_way_matches'
            )
        );
    }

    public function test_draft_foreign_line_duplicate_document_and_conflicting_idempotency_fail_closed(): void
    {
        $context = $this->context(
            'guards',
            issue: false
        );

        $this->assertDomainFailure(
            fn () => app(
                SupplierInvoiceManager::class
            )->record(
                $this->invoiceData(
                    $context,
                    'FAC-A-0001-00000003',
                    '2',
                    1000,
                    0
                ),
                $context['operator']
            )
        );

        $context = $this->context('guards-issued');
        $invoice = app(SupplierInvoiceManager::class)
            ->record(
                $this->invoiceData(
                    $context,
                    'FAC-A-0001-00000004',
                    '2',
                    1000,
                    0
                ),
                $context['operator']
            );

        $this->assertDomainFailure(
            fn () => app(
                SupplierInvoiceManager::class
            )->record(
                new SupplierInvoiceData(
                    purchaseOrderId:
                        $context['order']->id,
                    documentNumber:
                        'FAC-A-0001-00000004',
                    issuedOn: '2026-08-17',
                    dueOn: null,
                    logisticsAmountMinor: 0,
                    lines: [
                        new SupplierInvoiceLineData(
                            purchaseOrderLineId:
                                $context['order']
                                    ->lines
                                    ->first()
                                    ->id,
                            description:
                                'Duplicado distinto',
                            quantity: '2',
                            unitCostMinor: 1200
                        ),
                    ],
                    idempotencyKey:
                        'p97a:other-key'
                ),
                $context['operator']
            )
        );

        $this->assertDomainFailure(
            fn () => app(
                SupplierInvoiceManager::class
            )->record(
                new SupplierInvoiceData(
                    purchaseOrderId:
                        $context['order']->id,
                    documentNumber:
                        'FAC-A-0001-00000005',
                    issuedOn: '2026-08-17',
                    dueOn: null,
                    logisticsAmountMinor: 0,
                    lines: [
                        new SupplierInvoiceLineData(
                            purchaseOrderLineId:
                                $context['order']
                                    ->lines
                                    ->first()
                                    ->id,
                            description:
                                'Conflicto idem',
                            quantity: '2',
                            unitCostMinor: 1200
                        ),
                    ],
                    idempotencyKey:
                        $invoice->idempotency_key
                ),
                $context['operator']
            )
        );

        $other = $this->context('foreign');

        $this->assertDomainFailure(
            fn () => app(
                SupplierInvoiceManager::class
            )->record(
                new SupplierInvoiceData(
                    purchaseOrderId:
                        $context['order']->id,
                    documentNumber:
                        'FAC-A-0001-00000006',
                    issuedOn: '2026-08-17',
                    dueOn: null,
                    logisticsAmountMinor: 0,
                    lines: [
                        new SupplierInvoiceLineData(
                            purchaseOrderLineId:
                                $other['order']
                                    ->lines
                                    ->first()
                                    ->id,
                            description:
                                'Línea ajena',
                            quantity: '1',
                            unitCostMinor: 1000
                        ),
                    ],
                    idempotencyKey:
                        'p97a:foreign-line'
                ),
                $context['operator']
            )
        );
    }

    public function test_model_and_database_keep_invoice_and_lines_append_only(): void
    {
        $context = $this->context('immutable');

        $invoice = app(SupplierInvoiceManager::class)
            ->record(
                $this->invoiceData(
                    $context,
                    'FAC-A-0001-00000007',
                    '2',
                    1000,
                    0
                ),
                $context['operator']
            );

        $this->assertDomainFailure(
            function () use ($invoice): void {
                $invoice->notes = 'Mutación';
                $invoice->save();
            }
        );

        $line = $invoice->lines->first();

        $this->assertDomainFailure(
            function () use ($line): void {
                $line->description = 'Mutación';
                $line->save();
            }
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'supplier_invoices'
            )
                ->where('id', $invoice->id)
                ->update(['total_minor' => 1])
        );
        $this->assertQueryRejected(
            fn () => DB::table(
                'supplier_invoice_lines'
            )
                ->where('id', $line->id)
                ->delete()
        );
    }

    public function test_http_allows_operator_rejects_viewer_and_never_takes_currency_authority_from_client(): void
    {
        $context = $this->context('http');

        $payload = [
            'document_number' =>
                'FAC-A-0001-00000008',
            'issued_on' => '2026-08-17',
            'due_on' => '2026-09-17',
            'logistics_amount' => '1.50',
            'idempotency_key' =>
                'p97a:http',
            'currency_code' => 'USD',
            'lines' => [
                [
                    'purchase_order_line_id' =>
                        $context['order']
                            ->lines
                            ->first()
                            ->id,
                    'description' =>
                        'Producto HTTP',
                    'quantity' => '2',
                    'unit_cost' => '10.00',
                ],
            ],
        ];

        $this->actingAs($context['viewer']);

        $this->post(
            route(
                'purchase-orders.supplier-invoices.store',
                $context['order']->public_id
            ),
            $payload
        )->assertForbidden();

        $this->actingAs($context['operator']);

        $response = $this->post(
            route(
                'purchase-orders.supplier-invoices.store',
                $context['order']->public_id
            ),
            $payload
        );

        $response->assertRedirect(
            route(
                'purchase-orders.show',
                $context['order']
            )
        );

        $invoice = SupplierInvoice::query()
            ->sole();

        $this->assertSame(
            'ARS',
            $invoice->currency_code
        );
        $this->assertSame(
            2150,
            $invoice->total_minor
        );
        $this->assertDatabaseCount(
            'purchase_obligations',
            0
        );
        $this->assertDatabaseCount(
            'cash_movements',
            0
        );
    }

    private function invoiceData(
        array $context,
        string $document,
        string $quantity,
        int $unitCostMinor,
        int $logisticsMinor
    ): SupplierInvoiceData {
        return new SupplierInvoiceData(
            purchaseOrderId:
                $context['order']->id,
            documentNumber: $document,
            issuedOn: '2026-08-17',
            dueOn: '2026-09-17',
            logisticsAmountMinor:
                $logisticsMinor,
            lines: [
                new SupplierInvoiceLineData(
                    purchaseOrderLineId:
                        $context['order']
                            ->lines
                            ->first()
                            ->id,
                    description:
                        'Producto documentado',
                    quantity: $quantity,
                    unitCostMinor:
                        $unitCostMinor,
                    supplierCode:
                        'SUP-'.$document
                ),
            ],
            idempotencyKey:
                'p97a:'.Str::uuid()
        );
    }

    private function context(
        string $suffix,
        bool $issue = true
    ): array {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $operator = $this->user(
            $organization,
            UserRole::Operator,
            $suffix.'-operator'
        );
        $viewer = $this->user(
            $organization,
            UserRole::Viewer,
            $suffix.'-viewer'
        );
        $supplier = $this->supplier(
            $organization,
            $suffix
        );
        $product = $this->product($suffix);

        $this->actingAs($operator);

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey:
                    'p97a:order:'.$suffix,
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

        if ($issue) {
            $order = $orders->issue(
                $order,
                $operator
            );
        }

        $order->load('lines');

        return compact(
            'organization',
            'operator',
            'viewer',
            'supplier',
            'product',
            'order'
        );
    }

    private function user(
        Organization $organization,
        UserRole $role,
        string $suffix
    ): User {
        $token = (string) Str::uuid();

        $user = User::query()->create([
            'name' =>
                $role->label().' '.$suffix,
            'email' =>
                $token.'@p97a.test',
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
            'organization_id' =>
                $organization->id,
            'party_type' =>
                BusinessParty::TYPE_ORGANIZATION,
            'name' =>
                'Proveedor P9.7a '
                .$suffix.' '.Str::uuid(),
        ]);

        return Supplier::query()->create([
            'organization_id' =>
                $organization->id,
            'business_party_id' =>
                $party->id,
            'active' => true,
        ]);
    }

    private function product(
        string $suffix
    ): CatalogProduct {
        $category = ProductCategory::query()
            ->firstOrCreate(
                ['slug' => 'p97a-tests'],
                [
                    'name' =>
                        'Pruebas P9.7a',
                    'active' => true,
                ]
            );

        return CatalogProduct::query()->create([
            'product_category_id' =>
                $category->id,
            'sku' =>
                'P97A-'.Str::upper(
                    $suffix
                ).'-'.Str::upper(
                    Str::random(6)
                ),
            'name' =>
                'Producto factura '.$suffix,
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
