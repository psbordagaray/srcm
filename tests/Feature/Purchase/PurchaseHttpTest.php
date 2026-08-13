<?php

namespace Tests\Feature\Purchase;

use App\Domain\Inventory\InventoryQuantity;
use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_routes_permissions_and_navigation_are_explicit(): void
    {
        $organization = $this->organization();
        $viewer = $this->user($organization, UserRole::Viewer);
        $this->actingAs($viewer);

        $this->get(route('purchase-orders.index'))->assertOk();
        $this->get(route('purchase-orders.create'))->assertForbidden();
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Compras')
            ->assertSee(route('purchase-orders.index'), false);

        $routes = app('router')->getRoutes();
        $this->assertNotNull($routes->getByName('purchase-orders.index'));
        $this->assertNotNull($routes->getByName('purchase-orders.show'));
        $this->assertNotNull($routes->getByName('purchase-orders.store'));
        $this->assertNotNull($routes->getByName('purchase-orders.issue'));
        $this->assertNotNull($routes->getByName('purchase-orders.cancel'));
        $this->assertNotNull(
            $routes->getByName('purchase-orders.receipts.store')
        );
        $this->assertNull($routes->getByName('purchase-orders.destroy'));
        $this->assertNull($routes->getByName('purchase-receipts.destroy'));
    }

    public function test_operator_completes_draft_revision_issue_and_partial_total_receipts(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $this->actingAs($operator);
        $supplier = $this->supplier($organization, 'HTTP flujo');
        $product = $this->product('TV compra HTTP', 'PURCHASE-HTTP-FLOW');
        $location = $this->location($organization, 'Recepción HTTP');
        $key = 'purchase-ui:order:'.Str::uuid();
        $payload = $this->orderPayload($supplier, $product, $key, '3');

        $this->post(route('purchase-orders.store'), $payload)
            ->assertSessionHasNoErrors();
        $order = PurchaseOrder::query()->sole();
        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame(1, $order->lines()->count());

        $this->post(route('purchase-orders.store'), $payload)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_orders', 1);

        $updated = $payload;
        $updated['expected_logistics_cost'] = '2.50';
        $updated['notes'] = 'Borrador revisado por HTTP.';
        $this->put(route('purchase-orders.update', $order), $updated)
            ->assertRedirect(route('purchase-orders.show', $order));
        $order->refresh();
        $this->assertSame(250, $order->expected_logistics_cost_minor);

        $this->post(route('purchase-orders.issue', $order))
            ->assertRedirect(route('purchase-orders.show', $order));
        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Issued, $order->status);
        $this->get(route('purchase-orders.edit', $order))->assertNotFound();
        $this->from(route('purchase-orders.show', $order))
            ->put(route('purchase-orders.update', $order), $updated)
            ->assertSessionHasErrors('purchase');
        $this->assertSame(
            PurchaseOrderStatus::Issued,
            $order->refresh()->status
        );

        $movementBaseline = \App\Models\InventoryMovement::query()->count();

        $firstReceipt = $this->receiptPayload(
            $order,
            $location,
            '1',
            'REM-HTTP-001'
        );
        $this->post(
            route('purchase-orders.receipts.store', $order),
            $firstReceipt
        )->assertRedirect(route('purchase-orders.show', $order));
        $order->refresh();
        $this->assertSame(
            PurchaseOrderStatus::PartiallyReceived,
            $order->status
        );
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertSame(
            $movementBaseline + 1,
            \App\Models\InventoryMovement::query()->count()
        );

        $this->post(
            route('purchase-orders.receipts.store', $order),
            $firstReceipt
        )->assertRedirect(route('purchase-orders.show', $order));
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertSame(
            $movementBaseline + 1,
            \App\Models\InventoryMovement::query()->count()
        );

        $secondReceipt = $this->receiptPayload(
            $order,
            $location,
            '2',
            'REM-HTTP-002'
        );
        $this->post(
            route('purchase-orders.receipts.store', $order),
            $secondReceipt
        )->assertRedirect(route('purchase-orders.show', $order));
        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Received, $order->status);
        $this->assertDatabaseCount('purchase_receipts', 2);
        $this->assertSame(
            $movementBaseline + 2,
            \App\Models\InventoryMovement::query()->count()
        );

        $this->get(route('purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('REM-HTTP-001')
            ->assertSee('REM-HTTP-002')
            ->assertSee($order->receipts()->first()->inventoryMovement->public_id);
    }

    public function test_purchase_quantities_are_human_readable_without_losing_scale_truth(): void
    {
        $organization = $this->organization();
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $this->actingAs($operator);

        [$order] = $this->issuedOrder(
            $organization,
            $operator,
            'Cantidad visual',
            '10'
        );

        $this->get(route('purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('10 unit')
            ->assertDontSee('10.000000');

        $this->get(
            route('purchase-orders.receipts.create', $order)
        )
            ->assertOk()
            ->assertSee(
                'Pedido 10 · recibido 0 · pendiente 10 unit'
            )
            ->assertDontSee('10.000000');

        $this->assertSame(
            '10',
            InventoryQuantity::input('10.000000', 0)
        );
        $this->assertSame(
            '10,5',
            InventoryQuantity::display('10.500000', 2)
        );
        $this->assertSame(
            '1.234,5',
            InventoryQuantity::display('1234.500000', 2)
        );
    }

    public function test_receipt_validation_and_domain_reject_invalid_locations_and_overreceipt(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $this->actingAs($operator);
        [$order, $location] = $this->issuedOrder(
            $organization,
            $operator,
            'HTTP rechazo',
            '1'
        );
        $inactive = $this->location($organization, 'Inactiva', false);
        $beforeMovements = \App\Models\InventoryMovement::query()->count();

        $invalid = $this->receiptPayload(
            $order,
            $inactive,
            '1',
            'REM-INACTIVE'
        );
        $this->from(route('purchase-orders.receipts.create', $order))
            ->post(route('purchase-orders.receipts.store', $order), $invalid)
            ->assertSessionHasErrors('lines.0.inventory_location_id');
        $this->assertDatabaseCount('purchase_receipts', 0);

        $over = $this->receiptPayload(
            $order,
            $location,
            '2',
            'REM-OVER'
        );
        $this->from(route('purchase-orders.receipts.create', $order))
            ->post(route('purchase-orders.receipts.store', $order), $over)
            ->assertSessionHasErrors('receipt');
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertSame(
            $beforeMovements,
            \App\Models\InventoryMovement::query()->count()
        );
    }

    public function test_offer_mismatch_and_foreign_orders_fail_closed(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $this->actingAs($operator);
        $supplierA = $this->supplier($organization, 'HTTP oferta A');
        $supplierB = $this->supplier($organization, 'HTTP oferta B');
        $product = $this->product('Producto oferta HTTP', 'PURCHASE-HTTP-OFFER');
        $offer = $this->offer($organization, $supplierA, $product);
        $payload = $this->orderPayload(
            $supplierB,
            $product,
            'purchase-ui:order:'.Str::uuid(),
            '1'
        );
        $payload['lines'][0]['supplier_offer_id'] = $offer->id;

        $this->from(route('purchase-orders.create'))
            ->post(route('purchase-orders.store'), $payload)
            ->assertSessionHasErrors('purchase');
        $this->assertDatabaseCount('purchase_orders', 0);

        $foreign = Organization::query()->create([
            'name' => 'Organización extranjera '.Str::uuid(),
            'slug' => 'foreign-'.Str::random(10),
            'active' => true,
        ]);
        $foreignUser = $this->user($foreign, UserRole::Operator);
        $this->actingAs($foreignUser);
        [$foreignOrder] = $this->issuedOrder(
            $foreign,
            $foreignUser,
            'Extranjera',
            '1'
        );

        $this->actingAs($operator);
        $this->get(route('purchase-orders.show', $foreignOrder))
            ->assertNotFound();
        $this->post(route('purchase-orders.issue', $foreignOrder))
            ->assertNotFound();
    }

    public function test_only_admin_cancels_and_received_orders_cannot_be_cancelled(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $this->actingAs($operator);
        [$order] = $this->issuedOrder(
            $organization,
            $operator,
            'Cancelar HTTP',
            '1'
        );

        $this->post(route('purchase-orders.cancel', $order), [
            'reason' => 'Operador no autorizado.',
        ])->assertForbidden();

        $this->actingAs($admin);
        $this->post(route('purchase-orders.cancel', $order), [
            'reason' => 'Proveedor sin disponibilidad.',
        ])->assertRedirect(route('purchase-orders.show', $order));
        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $order->status);

        $this->actingAs($operator);
        [$receivedOrder, $location] = $this->issuedOrder(
            $organization,
            $operator,
            'No cancelar recibida',
            '2'
        );
        app(PurchaseReceiptManager::class)->receive(
            new PurchaseReceiptData(
                purchaseOrderId: $receivedOrder->id,
                receivedAt: CarbonImmutable::parse('2026-08-06 18:00:00'),
                idempotencyKey: 'purchase:http:cancel:receipt',
                lines: [new PurchaseReceiptLineData(
                    purchaseOrderLineId: $receivedOrder->lines->first()->id,
                    quantity: '1',
                    inventoryLocationId: $location->id,
                    condition: InventoryCondition::New,
                    actualUnitCostMinor: 1000
                )]
            ),
            $operator
        );
        $receivedOrder->refresh();
        $this->assertSame(
            PurchaseOrderStatus::PartiallyReceived,
            $receivedOrder->status
        );

        $this->actingAs($admin);
        $this->from(route('purchase-orders.show', $receivedOrder))
            ->post(route('purchase-orders.cancel', $receivedOrder), [
                'reason' => 'Intento tardío.',
            ])
            ->assertSessionHasErrors('purchase');
        $this->assertSame(
            PurchaseOrderStatus::PartiallyReceived,
            $receivedOrder->refresh()->status
        );
    }

    public function test_viewer_is_read_only_and_expedient_is_tenant_scoped(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);
        $this->actingAs($operator);
        [$order] = $this->issuedOrder(
            $organization,
            $operator,
            'Viewer expediente',
            '1'
        );

        $this->actingAs($viewer);
        $this->get(route('purchase-orders.show', $order))
            ->assertOk()
            ->assertSee($order->supplier->party->name)
            ->assertSee('Total esperado');
        $this->get(route('purchase-orders.receipts.create', $order))
            ->assertForbidden();
        $this->put(route('purchase-orders.update', $order), [])
            ->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function orderPayload(
        Supplier $supplier,
        CatalogProduct $product,
        string $key,
        string $quantity
    ): array {
        return [
            'supplier_id' => $supplier->id,
            'currency_code' => 'ARS',
            'expected_logistics_cost' => '0.00',
            'notes' => 'Orden HTTP controlada.',
            'idempotency_key' => $key,
            'lines' => [[
                'catalog_product_id' => $product->id,
                'supplier_offer_id' => '',
                'quantity' => $quantity,
                'unit_cost' => '10.00',
                'supplier_code' => 'HTTP-'.$product->id,
                'description' => $product->name.' compra',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function receiptPayload(
        PurchaseOrder $order,
        InventoryLocation $location,
        string $quantity,
        string $document
    ): array {
        $order->loadMissing('lines');

        return [
            'received_at' => '2026-08-06 18:00:00',
            'document_reference' => $document,
            'logistics_cost' => '1.00',
            'notes' => 'Recepción HTTP.',
            'idempotency_key' => 'purchase-ui:receipt:'.Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'quantity' => $quantity,
                'inventory_location_id' => $location->id,
                'condition' => InventoryCondition::New->value,
                'actual_unit_cost' => '10.00',
            ]],
        ];
    }

    /** @return array{PurchaseOrder, InventoryLocation} */
    private function issuedOrder(
        Organization $organization,
        User $actor,
        string $suffix,
        string $quantity
    ): array {
        $this->actingAs($actor);
        $supplier = $this->supplier($organization, $suffix);
        $product = $this->product(
            'Producto '.$suffix,
            'PURCHASE-HTTP-'.Str::upper(Str::random(6))
        );
        $location = $this->location($organization, 'Recepción '.$suffix);
        $manager = app(PurchaseOrderManager::class);
        $order = $manager->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey: 'purchase:http:issued:'.Str::uuid(),
                lines: [new PurchaseOrderLineData(
                    catalogProductId: $product->id,
                    quantity: $quantity,
                    unitCostMinor: 1000
                )]
            ),
            $actor
        );

        return [$manager->issue($order, $actor), $location];
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
            'email' => $token.'@purchase-http.test',
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

    private function product(string $name, string $sku): CatalogProduct
    {
        $category = ProductCategory::query()->firstOrCreate(
            ['slug' => 'purchase-http-tests'],
            ['name' => 'Pruebas HTTP Compras', 'active' => true]
        );

        return CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'sku' => $sku.'-'.Str::upper(Str::random(6)),
            'name' => $name,
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
            'active' => true,
        ]);
    }

    private function location(
        Organization $organization,
        string $name,
        bool $active = true
    ): InventoryLocation {
        return InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'name' => $name.' '.Str::uuid(),
            'type' => InventoryLocationType::Receiving,
            'active' => $active,
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
            'supplier_code' => 'HTTP-OFFER-'.$product->id,
            'published_description' => $product->name.' oferta',
            'cost_amount' => '10.00',
            'currency' => 'ARS',
            'availability_status' => SupplierOffer::AVAILABILITY_AVAILABLE,
            'checked_at' => now()->toDateString(),
            'active' => true,
        ]);
    }
}
