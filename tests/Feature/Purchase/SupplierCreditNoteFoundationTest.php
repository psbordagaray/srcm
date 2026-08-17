<?php

namespace Tests\Feature\Purchase;

use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\SupplierCreditNoteData;
use App\Domain\Purchase\SupplierCreditNoteManager;
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
use App\Models\SupplierCreditNote;
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

class SupplierCreditNoteFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_route_authority_and_non_effect_contract_are_explicit(): void
    {
        $this->assertTrue(
            Schema::hasTable('supplier_credit_notes')
        );
        $this->assertFalse(
            Schema::hasTable(
                'supplier_credit_allocations'
            )
        );
        $this->assertTrue(
            Route::has(
                'purchase-orders.supplier-credit-notes.store'
            )
        );

        foreach ([
            'supplier_invoice_id',
            'purchase_order_id',
            'supplier_id',
            'document_number',
            'normalized_document_number',
            'issued_on',
            'currency_code',
            'amount_minor',
            'reason',
            'idempotency_key',
            'fingerprint',
            'recorded_by_user_id',
            'recorded_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn(
                    'supplier_credit_notes',
                    $column
                )
            );
        }
    }

    public function test_operator_records_credit_note_idempotently_without_payable_inventory_or_money_effect(): void
    {
        $context = $this->context('record');

        $before = [
            'obligations' =>
                DB::table(
                    'purchase_obligations'
                )->count(),
            'payment_requests' =>
                DB::table(
                    'purchase_payment_requests'
                )->count(),
            'executions' =>
                DB::table(
                    'purchase_payment_executions'
                )->count(),
            'movements' =>
                DB::table(
                    'inventory_movements'
                )->count(),
            'cash' =>
                DB::table('cash_movements')->count(),
            'external' =>
                DB::table(
                    'financial_external_movements'
                )->count(),
        ];

        $data = new SupplierCreditNoteData(
            supplierInvoiceId:
                $context['invoice']->id,
            documentNumber:
                'NC-A-0001-00000001',
            issuedOn: '2026-08-18',
            amountMinor: 500,
            reason:
                'Bonificación comercial',
            idempotencyKey:
                'p97d:record'
        );

        $note = app(
            SupplierCreditNoteManager::class
        )->record(
            $data,
            $context['operator']
        );

        $this->assertSame(
            500,
            $note->amount_minor
        );
        $this->assertSame(
            'ARS',
            $note->currency_code
        );
        $this->assertSame(
            $context['invoice']->id,
            $note->supplier_invoice_id
        );

        $retry = app(
            SupplierCreditNoteManager::class
        )->record(
            $data,
            $context['operator']
        );

        $this->assertSame(
            $note->id,
            $retry->id
        );
        $this->assertDatabaseCount(
            'supplier_credit_notes',
            1
        );

        $this->assertSame(
            $before['obligations'],
            DB::table(
                'purchase_obligations'
            )->count()
        );
        $this->assertSame(
            $before['payment_requests'],
            DB::table(
                'purchase_payment_requests'
            )->count()
        );
        $this->assertSame(
            $before['executions'],
            DB::table(
                'purchase_payment_executions'
            )->count()
        );
        $this->assertSame(
            $before['movements'],
            DB::table(
                'inventory_movements'
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
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' =>
                $context['organization']->id,
            'event' =>
                'supplier_credit_note.recorded',
            'auditable_id' =>
                (string) $note->id,
        ]);
    }

    public function test_cumulative_credit_duplicate_document_and_conflicting_idempotency_fail_closed(): void
    {
        $context = $this->context('limits');

        $manager = app(
            SupplierCreditNoteManager::class
        );

        $first = $manager->record(
            new SupplierCreditNoteData(
                supplierInvoiceId:
                    $context['invoice']->id,
                documentNumber:
                    'NC-A-0001-00000002',
                issuedOn: '2026-08-18',
                amountMinor: 1500,
                reason: 'Ajuste uno',
                idempotencyKey:
                    'p97d:limits:first'
            ),
            $context['operator']
        );

        $this->assertSame(
            1500,
            $first->amount_minor
        );

        $this->assertDomainFailure(
            fn () => $manager->record(
                new SupplierCreditNoteData(
                    supplierInvoiceId:
                        $context['invoice']->id,
                    documentNumber:
                        'NC-A-0001-00000003',
                    issuedOn: '2026-08-18',
                    amountMinor: 600,
                    reason:
                        'Exceso acumulado',
                    idempotencyKey:
                        'p97d:limits:excess'
                ),
                $context['operator']
            )
        );

        $this->assertDomainFailure(
            fn () => $manager->record(
                new SupplierCreditNoteData(
                    supplierInvoiceId:
                        $context['invoice']->id,
                    documentNumber:
                        'NC-A-0001-00000002',
                    issuedOn: '2026-08-18',
                    amountMinor: 100,
                    reason:
                        'Misma referencia distinta',
                    idempotencyKey:
                        'p97d:limits:duplicate-doc'
                ),
                $context['operator']
            )
        );

        $this->assertDomainFailure(
            fn () => $manager->record(
                new SupplierCreditNoteData(
                    supplierInvoiceId:
                        $context['invoice']->id,
                    documentNumber:
                        'NC-A-0001-00000004',
                    issuedOn: '2026-08-18',
                    amountMinor: 100,
                    reason:
                        'Conflicto idempotencia',
                    idempotencyKey:
                        $first->idempotency_key
                ),
                $context['operator']
            )
        );
    }

    public function test_foreign_invoice_and_date_before_invoice_fail_closed(): void
    {
        $context = $this->context('tenant');
        $other = $this->otherOrganizationContext(
            'foreign'
        );

        $this->assertDomainFailure(
            fn () => app(
                SupplierCreditNoteManager::class
            )->record(
                new SupplierCreditNoteData(
                    supplierInvoiceId:
                        $other['invoice']->id,
                    documentNumber:
                        'NC-A-0001-00000005',
                    issuedOn: '2026-08-18',
                    amountMinor: 100,
                    reason:
                        'Factura ajena',
                    idempotencyKey:
                        'p97d:foreign'
                ),
                $context['operator']
            )
        );

        $this->assertDomainFailure(
            fn () => app(
                SupplierCreditNoteManager::class
            )->record(
                new SupplierCreditNoteData(
                    supplierInvoiceId:
                        $context['invoice']->id,
                    documentNumber:
                        'NC-A-0001-00000006',
                    issuedOn: '2026-08-16',
                    amountMinor: 100,
                    reason:
                        'Fecha inválida',
                    idempotencyKey:
                        'p97d:date'
                ),
                $context['operator']
            )
        );
    }

    public function test_model_and_database_keep_credit_note_append_only(): void
    {
        $context = $this->context('immutable');

        $note = app(
            SupplierCreditNoteManager::class
        )->record(
            new SupplierCreditNoteData(
                supplierInvoiceId:
                    $context['invoice']->id,
                documentNumber:
                    'NC-A-0001-00000007',
                issuedOn: '2026-08-18',
                amountMinor: 500,
                reason:
                    'Ajuste inmutable',
                idempotencyKey:
                    'p97d:immutable'
            ),
            $context['operator']
        );

        $this->assertDomainFailure(
            function () use ($note): void {
                $note->reason = 'Mutación';
                $note->save();
            }
        );

        $this->assertDomainFailure(
            fn () => $note->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'supplier_credit_notes'
            )
                ->where('id', $note->id)
                ->update(['amount_minor' => 1])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'supplier_credit_notes'
            )
                ->where('id', $note->id)
                ->delete()
        );
    }

    public function test_http_allows_operator_rejects_viewer_and_derives_invoice_authority(): void
    {
        $context = $this->context('http');

        $payload = [
            'document_number' =>
                'NC-A-0001-00000008',
            'issued_on' => '2026-08-18',
            'amount' => '5.00',
            'reason' =>
                'Bonificación HTTP',
            'notes' =>
                'Evidencia estructurada',
            'idempotency_key' =>
                'p97d:http',
            'currency_code' => 'USD',
            'supplier_id' => 999999,
        ];

        $this->actingAs($context['viewer']);

        $this->post(
            route(
                'purchase-orders.supplier-credit-notes.store',
                [
                    $context['order']->public_id,
                    $context['invoice']->public_id,
                ]
            ),
            $payload
        )->assertForbidden();

        $this->actingAs($context['operator']);

        $response = $this->post(
            route(
                'purchase-orders.supplier-credit-notes.store',
                [
                    $context['order']->public_id,
                    $context['invoice']->public_id,
                ]
            ),
            $payload
        );

        $response->assertRedirect(
            route(
                'purchase-orders.show',
                $context['order']
            )
        );

        $note = SupplierCreditNote::query()
            ->sole();

        $this->assertSame(
            'ARS',
            $note->currency_code
        );
        $this->assertSame(
            $context['supplier']->id,
            $note->supplier_id
        );
        $this->assertSame(
            500,
            $note->amount_minor
        );
        $this->assertDatabaseCount(
            'purchase_obligations',
            0
        );
        $this->assertDatabaseCount(
            'purchase_payment_executions',
            0
        );
        $this->assertDatabaseCount(
            'cash_movements',
            0
        );
    }

    private function context(
        string $suffix
    ): array {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        return $this->buildContext(
            $organization,
            $suffix
        );
    }

    private function otherOrganizationContext(
        string $suffix
    ): array {
        $organization = Organization::query()
            ->create([
                'name' => 'Otra '.Str::uuid(),
                'slug' =>
                    'p97d-other-'.Str::lower(
                        Str::random(8)
                    ),
                'active' => true,
            ]);

        return $this->buildContext(
            $organization,
            $suffix
        );
    }

    private function buildContext(
        Organization $organization,
        string $suffix
    ): array {
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

        $orders = app(
            PurchaseOrderManager::class
        );

        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey:
                    'p97d:order:'.$suffix,
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

        $order = $orders->issue(
            $order,
            $operator
        );
        $order->load('lines');

        $invoice = app(
            SupplierInvoiceManager::class
        )->record(
            new SupplierInvoiceData(
                purchaseOrderId: $order->id,
                documentNumber:
                    'FAC-P97D-'
                    .Str::upper(
                        Str::random(10)
                    ),
                issuedOn: '2026-08-17',
                dueOn: null,
                logisticsAmountMinor: 0,
                lines: [
                    new SupplierInvoiceLineData(
                        purchaseOrderLineId:
                            $order->lines
                                ->first()
                                ->id,
                        description:
                            'Producto P9.7d',
                        quantity: '2',
                        unitCostMinor: 1000
                    ),
                ],
                idempotencyKey:
                    'p97d:invoice:'
                    .$suffix
                    .':'.Str::uuid()
            ),
            $operator
        );

        return compact(
            'organization',
            'operator',
            'viewer',
            'supplier',
            'product',
            'order',
            'invoice'
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
                $token.'@p97d.test',
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
                'Proveedor P9.7d '
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
                ['slug' => 'p97d-tests'],
                [
                    'name' =>
                        'Pruebas P9.7d',
                    'active' => true,
                ]
            );

        return CatalogProduct::query()->create([
            'product_category_id' =>
                $category->id,
            'sku' =>
                'P97D-'.Str::upper(
                    Str::random(8)
                ),
            'name' =>
                'Producto nota crédito '.$suffix,
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
