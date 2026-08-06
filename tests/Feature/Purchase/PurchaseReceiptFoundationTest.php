<?php

namespace Tests\Feature\Purchase;

use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseReceiptFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_partial_receipt_confirms_inventory_and_updates_order(): void
    {
        $context = $this->context('3', 'partial');
        $receipt = $this->receive(
            $context,
            quantity: '1',
            key: 'purchase:receipt:partial:1',
            document: 'REM-PARTIAL-001'
        );

        $this->assertSame(
            PurchaseOrderStatus::PartiallyReceived,
            $receipt->order->status
        );
        $this->assertCount(1, $receipt->lines);
        $this->assertSame(
            InventoryMovementType::Receipt,
            $receipt->inventoryMovement->type
        );
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $receipt->inventoryMovement->status
        );
        $this->assertSame(
            'purchase_receipt',
            $receipt->inventoryMovement->source_type
        );
        $this->assertSame(
            $receipt->public_id,
            $receipt->inventoryMovement->source_id
        );
        $this->assertSame(
            $receipt->inventoryMovement->id,
            $receipt->lines->first()->inventory_movement_id
        );
        $this->assertSame(
            '1.000000',
            InventoryBalance::query()
                ->where(
                    'organization_id',
                    $context['organization']->id
                )
                ->where(
                    'catalog_product_id',
                    $context['product']->id
                )
                ->where(
                    'inventory_location_id',
                    $context['location']->id
                )
                ->where(
                    'condition',
                    InventoryCondition::New->value
                )
                ->value('quantity')
        );
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' =>
                $context['organization']->id,
            'event' => 'purchase_receipt.confirmed',
            'auditable_id' => (string) $receipt->id,
        ]);
    }

    public function test_multiple_receipts_complete_order_and_excess_is_rejected(): void
    {
        $context = $this->context('3', 'multiple');

        $first = $this->receive(
            $context,
            quantity: '2',
            key: 'purchase:receipt:multiple:1',
            document: 'REM-MULTI-001'
        );
        $this->assertSame(
            PurchaseOrderStatus::PartiallyReceived,
            $first->order->status
        );

        $this->assertDomainFailure(
            fn () => $this->receive(
                $context,
                quantity: '2',
                key: 'purchase:receipt:multiple:excess',
                document: 'REM-MULTI-EXCESS'
            )
        );
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('inventory_movements', 1);

        $second = $this->receive(
            $context,
            quantity: '1',
            key: 'purchase:receipt:multiple:2',
            document: 'REM-MULTI-002'
        );

        $this->assertSame(
            PurchaseOrderStatus::Received,
            $second->order->status
        );
        $this->assertDatabaseCount('purchase_receipts', 2);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertSame(
            '3.000000',
            InventoryBalance::query()
                ->where(
                    'organization_id',
                    $context['organization']->id
                )
                ->where(
                    'catalog_product_id',
                    $context['product']->id
                )
                ->where(
                    'inventory_location_id',
                    $context['location']->id
                )
                ->where(
                    'condition',
                    InventoryCondition::New->value
                )
                ->value('quantity')
        );
    }

    public function test_receipt_idempotency_and_document_identity_are_enforced(): void
    {
        $context = $this->context('2', 'idempotency');
        $data = $this->receiptData(
            $context,
            quantity: '1',
            key: 'purchase:receipt:idempotency:1',
            document: 'Remito 0001-A'
        );
        $manager = app(PurchaseReceiptManager::class);

        $receipt = $manager->receive(
            $data,
            $context['actor']
        );
        $retry = $manager->receive(
            $data,
            $context['actor']
        );

        $this->assertSame($receipt->id, $retry->id);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('inventory_movements', 1);

        $this->assertDomainFailure(
            fn () => $manager->receive(
                $this->receiptData(
                    $context,
                    quantity: '1',
                    key: 'purchase:receipt:idempotency:1',
                    document: 'Remito distinto'
                ),
                $context['actor']
            )
        );

        $this->assertDomainFailure(
            fn () => $manager->receive(
                $this->receiptData(
                    $context,
                    quantity: '1',
                    key: 'purchase:receipt:idempotency:2',
                    document: 'REMITO-0001-A'
                ),
                $context['actor']
            )
        );
    }

    public function test_cross_organization_order_and_location_are_rejected(): void
    {
        $context = $this->context('2', 'tenant');
        $otherOrganization = Organization::query()->create([
            'name' => 'Otra organización '.Str::uuid(),
            'slug' => 'other-'.Str::lower(
                Str::random(8)
            ),
            'active' => true,
        ]);
        $otherLocation = InventoryLocation::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Recepción ajena '.Str::uuid(),
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        $this->assertDomainFailure(
            fn () => app(PurchaseReceiptManager::class)
                ->receive(
                    new PurchaseReceiptData(
                        purchaseOrderId:
                            $context['order']->id,
                        receivedAt:
                            CarbonImmutable::now(),
                        idempotencyKey:
                            'purchase:receipt:tenant:location',
                        lines: [
                            new PurchaseReceiptLineData(
                                purchaseOrderLineId:
                                    $context['order']
                                        ->lines
                                        ->first()
                                        ->id,
                                quantity: '1',
                                inventoryLocationId:
                                    $otherLocation->id,
                                condition:
                                    InventoryCondition::New,
                                actualUnitCostMinor: 1000
                            ),
                        ]
                    ),
                    $context['actor']
                )
        );

        $otherActor = $this->user(
            $otherOrganization,
            UserRole::Operator
        );
        $this->actingAs($otherActor);

        $this->assertDomainFailure(
            fn () => app(PurchaseReceiptManager::class)
                ->receive(
                    $this->receiptData(
                        $context,
                        quantity: '1',
                        key: 'purchase:receipt:tenant:order',
                        document: null
                    ),
                    $otherActor
                )
        );
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_inventory_failure_rolls_back_entire_receipt(): void
    {
        $context = $this->context('1', 'rollback');
        $context['product']->forceFill([
            'active' => false,
        ])->save();

        $this->assertDomainFailure(
            fn () => $this->receive(
                $context,
                quantity: '1',
                key: 'purchase:receipt:rollback:1',
                document: 'REM-ROLLBACK-001'
            )
        );

        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount(
            'purchase_receipt_lines',
            0
        );
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(
            PurchaseOrderStatus::Issued,
            $context['order']->fresh()->status
        );
    }

    public function test_receipt_lines_and_received_order_are_immutable(): void
    {
        $context = $this->context('1', 'immutable');
        $receipt = $this->receive(
            $context,
            quantity: '1',
            key: 'purchase:receipt:immutable:1',
            document: 'REM-IMMUTABLE-001'
        );
        $line = $receipt->lines->first();

        $this->assertDomainFailure(function () use ($receipt): void {
            $receipt->notes = 'Cambio indebido.';
            $receipt->save();
        });
        $this->assertDomainFailure(function () use ($line): void {
            $line->actual_unit_cost_minor = 1;
            $line->save();
        });
        $this->assertDomainFailure(
            fn () => app(PurchaseOrderManager::class)
                ->cancel(
                    $context['order'],
                    'Intento tardío.',
                    $context['admin']
                )
        );

        $this->assertQueryRejected(
            fn () => DB::table('purchase_receipts')
                ->where('id', $receipt->id)
                ->update(['notes' => 'Bypass'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('purchase_receipt_lines')
                ->where('id', $line->id)
                ->update([
                    'actual_unit_cost_minor' => 1,
                ])
        );
        $this->assertQueryRejected(
            fn () => DB::table('purchase_receipt_lines')
                ->where('id', $line->id)
                ->delete()
        );
        $this->assertQueryRejected(
            fn () => DB::table('purchase_receipts')
                ->where('id', $receipt->id)
                ->delete()
        );
        $this->assertQueryRejected(
            fn () => DB::table('purchase_orders')
                ->where('id', $context['order']->id)
                ->update(['status' => 'issued'])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(
        string $quantity,
        string $suffix
    ): array {
        $organization = $this->organization();
        $actor = $this->user(
            $organization,
            UserRole::Operator
        );
        $admin = $this->user(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($actor);
        $supplier = $this->supplier(
            $organization,
            $suffix
        );
        $product = $this->product(
            'Producto '.$suffix,
            'PURCHASE-RECEIPT-'.Str::upper($suffix)
        );
        $location = InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Recepción '.$suffix.' '.Str::uuid(),
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);
        $orders = app(PurchaseOrderManager::class);
        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey: 'purchase:receipt:order:'
                    .$suffix,
                lines: [
                    new PurchaseOrderLineData(
                        $product->id,
                        $quantity,
                        1000
                    ),
                ]
            ),
            $actor
        );
        $order = $orders->issue($order, $actor);

        return compact(
            'organization',
            'actor',
            'admin',
            'supplier',
            'product',
            'location',
            'order'
        );
    }

    private function receive(
        array $context,
        string $quantity,
        string $key,
        ?string $document
    ): PurchaseReceipt {
        $this->actingAs($context['actor']);

        return app(PurchaseReceiptManager::class)
            ->receive(
                $this->receiptData(
                    $context,
                    $quantity,
                    $key,
                    $document
                ),
                $context['actor']
            );
    }

    private function receiptData(
        array $context,
        string $quantity,
        string $key,
        ?string $document
    ): PurchaseReceiptData {
        return new PurchaseReceiptData(
            purchaseOrderId: $context['order']->id,
            receivedAt: CarbonImmutable::parse(
                '2026-08-06 15:00:00',
                'America/Argentina/Buenos_Aires'
            ),
            idempotencyKey: $key,
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
                    condition: InventoryCondition::New,
                    actualUnitCostMinor: 1000
                ),
            ],
            logisticsCostMinor: 100,
            documentReference: $document,
            notes: 'Recepción controlada.'
        );
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
            'email' => $token.'@purchase-receipt.test',
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
        string $sku
    ): CatalogProduct {
        $category = ProductCategory::query()->firstOrCreate(
            ['slug' => 'purchase-receipt-tests'],
            [
                'name' => 'Pruebas de Recepción',
                'active' => true,
            ]
        );

        return CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'sku' => $sku.'-'.Str::upper(
                Str::random(6)
            ),
            'name' => $name,
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
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
