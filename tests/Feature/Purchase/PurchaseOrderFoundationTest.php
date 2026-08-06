<?php

namespace Tests\Feature\Purchase;

use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Enums\InventoryBaseUnit;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseOrderFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_permissions_and_gates_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns(
            'purchase_orders',
            [
                'organization_id',
                'public_id',
                'supplier_id',
                'status',
                'currency_code',
                'expected_logistics_cost_minor',
                'merchandise_subtotal_minor',
                'expected_total_minor',
                'idempotency_key',
                'fingerprint',
                'issued_at',
                'cancelled_at',
            ]
        ));
        $this->assertTrue(
            Schema::hasTable('purchase_order_lines')
        );
        $this->assertTrue(
            Schema::hasTable('purchase_receipts')
        );
        $this->assertTrue(
            Schema::hasTable('purchase_receipt_lines')
        );

        $this->assertTrue(UserRole::Admin->canViewPurchases());
        $this->assertTrue(UserRole::Viewer->canViewPurchases());
        $this->assertTrue(
            UserRole::Operator->canDraftPurchaseOrders()
        );
        $this->assertTrue(
            UserRole::Operator->canIssuePurchaseOrders()
        );
        $this->assertTrue(
            UserRole::Operator->canReceivePurchases()
        );
        $this->assertFalse(
            UserRole::Operator->canCancelPurchaseOrders()
        );
        $this->assertTrue(
            UserRole::Admin->canCancelPurchaseOrders()
        );

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

        $this->assertTrue(
            Gate::forUser($admin)->allows('view-purchases')
        );
        $this->assertTrue(
            Gate::forUser($operator)
                ->allows('draft-purchase-orders')
        );
        $this->assertTrue(
            Gate::forUser($operator)
                ->allows('issue-purchase-orders')
        );
        $this->assertTrue(
            Gate::forUser($operator)
                ->allows('receive-purchases')
        );
        $this->assertFalse(
            Gate::forUser($operator)
                ->allows('cancel-purchase-orders')
        );
        $this->assertFalse(
            Gate::forUser($viewer)
                ->allows('draft-purchase-orders')
        );
    }

    public function test_order_draft_issue_and_idempotency_are_deterministic(): void
    {
        $organization = $this->organization();
        $actor = $this->user(
            $organization,
            UserRole::Operator
        );
        $this->actingAs($actor);

        $supplier = $this->supplier($organization, 'Base');
        $product = $this->product(
            'Pantalla OLED',
            'PURCHASE-ORDER-1'
        );
        $offer = $this->offer(
            $organization,
            $supplier,
            $product
        );
        $data = new PurchaseOrderDraftData(
            supplierId: $supplier->id,
            currencyCode: 'ars',
            idempotencyKey: 'purchase:order:foundation:1',
            lines: [
                new PurchaseOrderLineData(
                    catalogProductId: $product->id,
                    quantity: '2',
                    unitCostMinor: 1500000,
                    supplierOfferId: $offer->id
                ),
            ],
            expectedLogisticsCostMinor: 250000,
            notes: 'Entrega coordinada.'
        );
        $manager = app(PurchaseOrderManager::class);

        $draft = $manager->draft($data, $actor);
        $retry = $manager->draft($data, $actor);

        $this->assertSame($draft->id, $retry->id);
        $this->assertSame(
            PurchaseOrderStatus::Draft,
            $draft->status
        );
        $this->assertSame('ARS', $draft->currency_code);
        $this->assertSame(
            3000000,
            $draft->merchandise_subtotal_minor
        );
        $this->assertSame(
            3250000,
            $draft->expected_total_minor
        );
        $this->assertCount(1, $draft->lines);
        $this->assertSame(
            $offer->id,
            $draft->lines->first()->supplier_offer_id
        );
        $this->assertSame(
            'Pantalla OLED proveedor',
            $draft->lines->first()->description
        );

        $this->assertDomainFailure(
            fn () => $manager->draft(
                new PurchaseOrderDraftData(
                    supplierId: $supplier->id,
                    currencyCode: 'ARS',
                    idempotencyKey:
                        'purchase:order:foundation:1',
                    lines: [
                        new PurchaseOrderLineData(
                            catalogProductId: $product->id,
                            quantity: '2',
                            unitCostMinor: 1600000,
                            supplierOfferId: $offer->id
                        ),
                    ]
                ),
                $actor
            )
        );

        $issued = $manager->issue($draft, $actor);
        $issuedRetry = $manager->issue($draft, $actor);

        $this->assertSame(
            PurchaseOrderStatus::Issued,
            $issued->status
        );
        $this->assertSame($issued->id, $issuedRetry->id);
        $this->assertSame($actor->id, $issued->issued_by_user_id);
        $this->assertNotNull($issued->issued_at);
        $this->assertSame(64, strlen($issued->fingerprint));
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'event' => 'purchase_order.issued',
            'auditable_id' => (string) $issued->id,
        ]);
    }

    public function test_draft_can_be_revised_but_not_after_issue(): void
    {
        $organization = $this->organization();
        $actor = $this->user(
            $organization,
            UserRole::Operator
        );
        $this->actingAs($actor);
        $supplier = $this->supplier(
            $organization,
            'Revision'
        );
        $productA = $this->product(
            'Producto inicial',
            'PURCHASE-REVISE-A'
        );
        $productB = $this->product(
            'Producto agregado',
            'PURCHASE-REVISE-B'
        );
        $manager = app(PurchaseOrderManager::class);
        $key = 'purchase:order:revise:1';
        $draft = $manager->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey: $key,
                lines: [
                    new PurchaseOrderLineData(
                        $productA->id,
                        '1',
                        1000
                    ),
                ]
            ),
            $actor
        );
        $originalLineId = $draft->lines->first()->id;
        $revision = new PurchaseOrderDraftData(
            supplierId: $supplier->id,
            currencyCode: 'ARS',
            idempotencyKey: $key,
            lines: [
                new PurchaseOrderLineData(
                    $productA->id,
                    '2',
                    1000
                ),
                new PurchaseOrderLineData(
                    $productB->id,
                    '1',
                    500
                ),
            ],
            expectedLogisticsCostMinor: 250,
            notes: 'Borrador revisado.'
        );

        $revised = $manager->revise(
            $draft,
            $revision,
            $actor
        );
        $retry = $manager->revise(
            $draft,
            $revision,
            $actor
        );

        $this->assertSame($draft->id, $revised->id);
        $this->assertSame($revised->id, $retry->id);
        $this->assertCount(2, $revised->lines);
        $this->assertSame(2500, $revised->merchandise_subtotal_minor);
        $this->assertSame(2750, $revised->expected_total_minor);
        $this->assertDatabaseMissing('purchase_order_lines', [
            'id' => $originalLineId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'event' => 'purchase_order.revised',
            'auditable_id' => (string) $draft->id,
        ]);

        $issued = $manager->issue($revised, $actor);

        $this->assertDomainFailure(
            fn () => $manager->revise(
                $issued,
                $revision,
                $actor
            )
        );
    }

    public function test_viewer_cannot_draft_or_issue_orders(): void
    {
        $organization = $this->organization();
        $viewer = $this->user(
            $organization,
            UserRole::Viewer
        );
        $this->actingAs($viewer);
        $supplier = $this->supplier($organization, 'Viewer');
        $product = $this->product(
            'Producto protegido',
            'PURCHASE-VIEWER-1'
        );
        $data = new PurchaseOrderDraftData(
            supplierId: $supplier->id,
            currencyCode: 'ARS',
            idempotencyKey: 'purchase:viewer:1',
            lines: [
                new PurchaseOrderLineData(
                    $product->id,
                    '1',
                    1000
                ),
            ]
        );

        $this->assertDomainFailure(
            fn () => app(PurchaseOrderManager::class)
                ->draft($data, $viewer)
        );
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_offer_must_match_active_supplier_and_product(): void
    {
        $organization = $this->organization();
        $actor = $this->user(
            $organization,
            UserRole::Operator
        );
        $this->actingAs($actor);
        $supplierA = $this->supplier(
            $organization,
            'Oferta A'
        );
        $supplierB = $this->supplier(
            $organization,
            'Oferta B'
        );
        $productA = $this->product(
            'Producto A',
            'PURCHASE-OFFER-A'
        );
        $productB = $this->product(
            'Producto B',
            'PURCHASE-OFFER-B'
        );
        $offer = $this->offer(
            $organization,
            $supplierA,
            $productA
        );

        foreach ([
            [$supplierB, $productA],
            [$supplierA, $productB],
        ] as $index => [$supplier, $product]) {
            $this->assertDomainFailure(
                fn () => app(PurchaseOrderManager::class)
                    ->draft(
                        new PurchaseOrderDraftData(
                            supplierId: $supplier->id,
                            currencyCode: 'ARS',
                            idempotencyKey:
                                'purchase:offer:mismatch:'
                                .$index,
                            lines: [
                                new PurchaseOrderLineData(
                                    $product->id,
                                    '1',
                                    1000,
                                    $offer->id
                                ),
                            ]
                        ),
                        $actor
                    )
            );
        }

        $offer->forceFill(['active' => false])->save();

        $this->assertDomainFailure(
            fn () => app(PurchaseOrderManager::class)
                ->draft(
                    new PurchaseOrderDraftData(
                        supplierId: $supplierA->id,
                        currencyCode: 'ARS',
                        idempotencyKey:
                            'purchase:offer:inactive',
                        lines: [
                            new PurchaseOrderLineData(
                                $productA->id,
                                '1',
                                1000,
                                $offer->id
                            ),
                        ]
                    ),
                    $actor
                )
        );
    }

    public function test_issued_order_and_lines_are_immutable_in_model_and_database(): void
    {
        [$order, $actor] = $this->issuedOrder(
            quantity: '2',
            unitCostMinor: 1000,
            suffix: 'immutable'
        );
        $this->actingAs($actor);
        $line = $order->lines->first();

        $this->assertDomainFailure(function () use ($order): void {
            $order->notes = 'Cambio indebido.';
            $order->save();
        });
        $this->assertDomainFailure(function () use ($line): void {
            $line->description = 'Cambio indebido.';
            $line->save();
        });

        $this->assertQueryRejected(
            fn () => DB::table('purchase_orders')
                ->where('id', $order->id)
                ->update(['notes' => 'Bypass'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('purchase_orders')
                ->where('id', $order->id)
                ->update(['status' => 'received'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('purchase_order_lines')
                ->where('id', $line->id)
                ->update(['description' => 'Bypass'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('purchase_order_lines')
                ->where('id', $line->id)
                ->delete()
        );
        $this->assertQueryRejected(
            fn () => DB::table('purchase_orders')
                ->where('id', $order->id)
                ->delete()
        );
    }

    public function test_only_admin_can_cancel_an_unreceived_issued_order(): void
    {
        [$order, $operator, $organization] =
            $this->issuedOrder(
                quantity: '1',
                unitCostMinor: 1000,
                suffix: 'cancel'
            );
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );

        $this->actingAs($operator);
        $this->assertDomainFailure(
            fn () => app(PurchaseOrderManager::class)
                ->cancel(
                    $order,
                    'No autorizado.',
                    $operator
                )
        );

        $this->actingAs($admin);
        $cancelled = app(PurchaseOrderManager::class)
            ->cancel(
                $order,
                'Proveedor sin disponibilidad.',
                $admin
            );
        $retry = app(PurchaseOrderManager::class)
            ->cancel(
                $order,
                'Proveedor sin disponibilidad.',
                $admin
            );

        $this->assertSame($cancelled->id, $retry->id);
        $this->assertSame(
            PurchaseOrderStatus::Cancelled,
            $cancelled->status
        );
        $this->assertSame(
            $admin->id,
            $cancelled->cancelled_by_user_id
        );
        $this->assertNotNull($cancelled->cancelled_at);
    }

    public function test_fractional_quantities_and_minor_units_do_not_round_silently(): void
    {
        $organization = $this->organization();
        $actor = $this->user(
            $organization,
            UserRole::Operator
        );
        $this->actingAs($actor);
        $supplier = $this->supplier(
            $organization,
            'Fractional'
        );
        $product = $this->product(
            'Cable por metro',
            'PURCHASE-FRACTIONAL-1',
            InventoryBaseUnit::Meter->value,
            3
        );

        $order = app(PurchaseOrderManager::class)->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey: 'purchase:fractional:ok',
                lines: [
                    new PurchaseOrderLineData(
                        $product->id,
                        '1.250',
                        120
                    ),
                ]
            ),
            $actor
        );

        $this->assertSame(
            '1.250000',
            $order->lines->first()->ordered_quantity
        );
        $this->assertSame(
            150,
            $order->lines->first()->subtotal_minor
        );

        $this->assertDomainFailure(
            fn () => app(PurchaseOrderManager::class)
                ->draft(
                    new PurchaseOrderDraftData(
                        supplierId: $supplier->id,
                        currencyCode: 'ARS',
                        idempotencyKey:
                            'purchase:fractional:scale',
                        lines: [
                            new PurchaseOrderLineData(
                                $product->id,
                                '1.2345',
                                120
                            ),
                        ]
                    ),
                    $actor
                )
        );

        $this->assertDomainFailure(
            fn () => app(PurchaseOrderManager::class)
                ->draft(
                    new PurchaseOrderDraftData(
                        supplierId: $supplier->id,
                        currencyCode: 'ARS',
                        idempotencyKey:
                            'purchase:fractional:money',
                        lines: [
                            new PurchaseOrderLineData(
                                $product->id,
                                '0.333',
                                100
                            ),
                        ]
                    ),
                    $actor
                )
        );
    }

    /**
     * @return array{PurchaseOrder, User, Organization}
     */
    private function issuedOrder(
        string $quantity,
        int $unitCostMinor,
        string $suffix
    ): array {
        $organization = $this->organization();
        $actor = $this->user(
            $organization,
            UserRole::Operator
        );
        $this->actingAs($actor);
        $supplier = $this->supplier(
            $organization,
            $suffix
        );
        $product = $this->product(
            'Producto '.$suffix,
            'PURCHASE-'.Str::upper($suffix)
        );
        $manager = app(PurchaseOrderManager::class);
        $order = $manager->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey: 'purchase:issued:'.$suffix,
                lines: [
                    new PurchaseOrderLineData(
                        $product->id,
                        $quantity,
                        $unitCostMinor
                    ),
                ]
            ),
            $actor
        );

        return [
            $manager->issue($order, $actor),
            $actor,
            $organization,
        ];
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $token = (string) Str::uuid();
        $user = User::query()->create([
            'name' => $role->label().' '.$token,
            'email' => $token.'@purchase.test',
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

    private function supplier(
        Organization $organization,
        string $suffix
    ): Supplier {
        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_ORGANIZATION,
            'name' => 'Proveedor '.$suffix.' '.Str::uuid(),
        ]);

        return Supplier::query()->create([
            'organization_id' => $organization->id,
            'business_party_id' => $party->id,
            'active' => true,
        ]);
    }

    private function product(
        string $name,
        string $sku,
        string $baseUnit = 'unit',
        int $scale = 0
    ): CatalogProduct {
        $category = ProductCategory::query()->firstOrCreate(
            ['slug' => 'purchase-tests'],
            [
                'name' => 'Pruebas de Compras',
                'active' => true,
            ]
        );

        return CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'sku' => $sku.'-'.Str::upper(
                Str::random(6)
            ),
            'name' => $name,
            'base_unit_code' => $baseUnit,
            'quantity_scale' => $scale,
            'active' => true,
        ]);
    }

    private function offer(
        Organization $organization,
        Supplier $supplier,
        CatalogProduct $product
    ): SupplierOffer {
        return SupplierOffer::query()->create([
            'organization_id' => $organization->id,
            'supplier_id' => $supplier->id,
            'catalog_product_id' => $product->id,
            'supplier_code' => 'SUP-'.$product->id,
            'published_description' =>
                $product->name.' proveedor',
            'cost_amount' => '15000.00',
            'currency' => 'ARS',
            'availability_status' =>
                SupplierOffer::AVAILABILITY_AVAILABLE,
            'checked_at' => now()->toDateString(),
            'active' => true,
        ]);
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail(
                'Se esperaba una DomainException.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail(
                'Se esperaba que la base rechazara la operación.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
