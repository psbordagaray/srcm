<?php

namespace Tests\Feature\Purchase;

use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
use App\Domain\Purchase\PurchaseThreeWayMatchReader;
use App\Domain\Purchase\SupplierInvoiceData;
use App\Domain\Purchase\SupplierInvoiceLineData;
use App\Domain\Purchase\SupplierInvoiceManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseThreeWayMatchFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_match_is_derived_read_only_and_route_is_explicit(): void
    {
        $this->assertTrue(
            Route::has(
                'purchase-orders.three-way-match'
            )
        );
        $this->assertFalse(
            Schema::hasTable(
                'purchase_three_way_matches'
            )
        );

        $context = $this->context('contract');

        $match = app(
            PurchaseThreeWayMatchReader::class
        )->read($context['order']);

        $this->assertSame(
            'missing_document',
            $match['status']
        );
        $this->assertFalse($match['exact']);
        $this->assertSame(
            0,
            $match['summary']['document_count']
        );
        $this->assertDatabaseCount(
            'purchase_obligations',
            0
        );
    }

    public function test_exact_order_receipt_and_invoice_produce_exact_match(): void
    {
        $context = $this->context('exact');

        $this->receive(
            $context,
            '2',
            1000,
            100,
            'p97b:receipt:exact'
        );
        $this->invoice(
            $context,
            'FAC-P97B-EXACT',
            '2',
            1000,
            100,
            'p97b:invoice:exact'
        );

        $match = app(
            PurchaseThreeWayMatchReader::class
        )->read(
            $context['order']->fresh()
        );

        $this->assertSame(
            'exact',
            $match['status']
        );
        $this->assertTrue($match['exact']);
        $this->assertSame(
            0,
            $match['summary']
                ['line_difference_count']
        );
        $this->assertSame(
            0,
            $match['summary']
                ['unmatched_document_line_count']
        );
        $this->assertSame(
            2100,
            $match['summary']
                ['order_total_minor']
        );
        $this->assertSame(
            2100,
            $match['summary']
                ['receipt_total_minor']
        );
        $this->assertSame(
            2100,
            $match['summary']
                ['document_total_minor']
        );
    }

    public function test_quantity_cost_logistics_and_unmatched_lines_are_explicit_differences(): void
    {
        $context = $this->context('difference');

        $this->receive(
            $context,
            '1',
            1100,
            50,
            'p97b:receipt:difference'
        );

        app(SupplierInvoiceManager::class)
            ->record(
                new SupplierInvoiceData(
                    purchaseOrderId:
                        $context['order']->id,
                    documentNumber:
                        'FAC-P97B-DIFF',
                    issuedOn: '2026-08-17',
                    dueOn: null,
                    logisticsAmountMinor: 200,
                    lines: [
                        new SupplierInvoiceLineData(
                            purchaseOrderLineId:
                                $context['order']
                                    ->lines
                                    ->first()
                                    ->id,
                            description:
                                'Producto con diferencia',
                            quantity: '3',
                            unitCostMinor: 1200
                        ),
                        new SupplierInvoiceLineData(
                            purchaseOrderLineId: null,
                            description:
                                'Cargo no previsto',
                            quantity: '1',
                            unitCostMinor: 300
                        ),
                    ],
                    idempotencyKey:
                        'p97b:invoice:difference'
                ),
                $context['operator']
            );

        $match = app(
            PurchaseThreeWayMatchReader::class
        )->read(
            $context['order']->fresh()
        );

        $this->assertSame(
            'different',
            $match['status']
        );
        $this->assertFalse($match['exact']);
        $this->assertSame(
            1,
            $match['summary']
                ['line_difference_count']
        );
        $this->assertSame(
            1,
            $match['summary']
                ['unmatched_document_line_count']
        );
        $this->assertFalse(
            $match['summary']['logistics_exact']
        );
        $this->assertFalse(
            $match['summary']['total_exact']
        );
        $this->assertSame(
            '1.000000',
            $match['lines'][0]
                ['received_quantity']
        );
        $this->assertSame(
            '3.000000',
            $match['lines'][0]
                ['documented_quantity']
        );
        $this->assertSame(
            '1.000000',
            $match['lines'][0]
                ['quantity_document_order_delta']
        );
        $this->assertSame(
            '2.000000',
            $match['lines'][0]
                ['quantity_document_receipt_delta']
        );
    }

    public function test_multiple_receipts_are_aggregated_progressively(): void
    {
        $context = $this->context('progressive');

        $this->invoice(
            $context,
            'FAC-P97B-PROG',
            '2',
            1000,
            100,
            'p97b:invoice:progressive'
        );

        $this->receive(
            $context,
            '1',
            1000,
            50,
            'p97b:receipt:progressive:1'
        );

        $partial = app(
            PurchaseThreeWayMatchReader::class
        )->read(
            $context['order']->fresh()
        );

        $this->assertSame(
            'different',
            $partial['status']
        );
        $this->assertSame(
            '1.000000',
            $partial['lines'][0]
                ['received_quantity']
        );

        $this->receive(
            $context,
            '1',
            1000,
            50,
            'p97b:receipt:progressive:2'
        );

        $exact = app(
            PurchaseThreeWayMatchReader::class
        )->read(
            $context['order']->fresh()
        );

        $this->assertSame(
            'exact',
            $exact['status']
        );
        $this->assertTrue($exact['exact']);
        $this->assertSame(
            '2.000000',
            $exact['lines'][0]
                ['received_quantity']
        );
        $this->assertSame(
            2,
            $exact['summary']['receipt_count']
        );
    }

    public function test_multiple_supplier_documents_are_aggregated_without_new_financial_truth(): void
    {
        $context = $this->context('multi-doc');

        $this->receive(
            $context,
            '2',
            1000,
            100,
            'p97b:receipt:multi'
        );

        $this->invoice(
            $context,
            'FAC-P97B-MULTI-1',
            '1',
            1000,
            40,
            'p97b:invoice:multi:1'
        );
        $this->invoice(
            $context,
            'FAC-P97B-MULTI-2',
            '1',
            1000,
            60,
            'p97b:invoice:multi:2'
        );

        $before = [
            'obligations' =>
                DB::table(
                    'purchase_obligations'
                )->count(),
            'cash' =>
                DB::table(
                    'cash_movements'
                )->count(),
            'external' =>
                DB::table(
                    'financial_external_movements'
                )->count(),
        ];

        $match = app(
            PurchaseThreeWayMatchReader::class
        )->read(
            $context['order']->fresh()
        );

        $this->assertSame(
            'exact',
            $match['status']
        );
        $this->assertSame(
            2,
            $match['summary']['document_count']
        );
        $this->assertSame(
            $before['obligations'],
            DB::table(
                'purchase_obligations'
            )->count()
        );
        $this->assertSame(
            $before['cash'],
            DB::table(
                'cash_movements'
            )->count()
        );
        $this->assertSame(
            $before['external'],
            DB::table(
                'financial_external_movements'
            )->count()
        );
    }

    public function test_viewer_can_read_match_but_foreign_tenant_is_hidden(): void
    {
        $context = $this->context('http');

        $this->actingAs($context['viewer']);

        $this->get(
            route(
                'purchase-orders.three-way-match',
                $context['order']->public_id
            )
        )
            ->assertOk()
            ->assertSee('3-way match')
            ->assertSee(
                'Falta documento del proveedor'
            );

        $foreign = $this->otherOrganizationContext(
            'foreign'
        );

        $this->actingAs($context['viewer']);

        $this->get(
            route(
                'purchase-orders.three-way-match',
                $foreign['order']->public_id
            )
        )->assertNotFound();
    }

    private function context(string $suffix): array
    {
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
        $location = InventoryLocation::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'name' =>
                    'Recepción P9.7b '.Str::uuid(),
                'type' =>
                    InventoryLocationType::Receiving,
                'active' => true,
            ]);

        $this->actingAs($operator);

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                expectedLogisticsCostMinor: 100,
                idempotencyKey:
                    'p97b:order:'.$suffix,
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

        return compact(
            'organization',
            'operator',
            'viewer',
            'supplier',
            'product',
            'location',
            'order'
        );
    }

    private function receive(
        array $context,
        string $quantity,
        int $unitCostMinor,
        int $logisticsMinor,
        string $idempotency
    ): void {
        app(PurchaseReceiptManager::class)
            ->receive(
                new PurchaseReceiptData(
                    purchaseOrderId:
                        $context['order']->id,
                    receivedAt:
                        CarbonImmutable::parse(
                            '2026-08-17 10:00:00',
                            'America/Argentina/Buenos_Aires'
                        ),
                    idempotencyKey:
                        $idempotency,
                    lines: [
                        new PurchaseReceiptLineData(
                            purchaseOrderLineId:
                                $context['order']
                                    ->lines
                                    ->first()
                                    ->id,
                            quantity: $quantity,
                            inventoryLocationId:
                                $context['location']->id,
                            condition:
                                InventoryCondition::New,
                            actualUnitCostMinor:
                                $unitCostMinor
                        ),
                    ],
                    logisticsCostMinor:
                        $logisticsMinor,
                    documentReference:
                        strtoupper(
                            str_replace(
                                ':',
                                '-',
                                $idempotency
                            )
                        )
                ),
                $context['operator']
            );
    }

    private function invoice(
        array $context,
        string $document,
        string $quantity,
        int $unitCostMinor,
        int $logisticsMinor,
        string $idempotency
    ): void {
        app(SupplierInvoiceManager::class)
            ->record(
                new SupplierInvoiceData(
                    purchaseOrderId:
                        $context['order']->id,
                    documentNumber: $document,
                    issuedOn: '2026-08-17',
                    dueOn: null,
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
                                'Producto P9.7b',
                            quantity: $quantity,
                            unitCostMinor:
                                $unitCostMinor
                        ),
                    ],
                    idempotencyKey:
                        $idempotency
                ),
                $context['operator']
            );
    }

    private function otherOrganizationContext(
        string $suffix
    ): array {
        $organization = Organization::query()
            ->create([
                'name' => 'Otra '.Str::uuid(),
                'slug' =>
                    'p97b-other-'.Str::lower(
                        Str::random(8)
                    ),
                'active' => true,
            ]);
        $operator = $this->user(
            $organization,
            UserRole::Operator,
            $suffix.'-operator'
        );
        $supplier = $this->supplier(
            $organization,
            $suffix
        );
        $product = $this->product(
            $suffix.'-other'
        );

        $this->actingAs($operator);

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                expectedLogisticsCostMinor: 0,
                idempotencyKey:
                    'p97b:foreign:'.$suffix,
                lines: [
                    new PurchaseOrderLineData(
                        $product->id,
                        '1',
                        100
                    ),
                ]
            ),
            $operator
        );
        $order = $orders->issue(
            $order,
            $operator
        );

        return compact(
            'organization',
            'operator',
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
                $token.'@p97b.test',
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
                'Proveedor P9.7b '
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
                ['slug' => 'p97b-tests'],
                [
                    'name' =>
                        'Pruebas P9.7b',
                    'active' => true,
                ]
            );

        return CatalogProduct::query()->create([
            'product_category_id' =>
                $category->id,
            'sku' =>
                'P97B-'.Str::upper(
                    Str::random(8)
                ),
            'name' =>
                'Producto match '.$suffix,
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
            'active' => true,
        ]);
    }
}
