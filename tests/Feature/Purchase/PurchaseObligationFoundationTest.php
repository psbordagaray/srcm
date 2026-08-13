<?php

namespace Tests\Feature\Purchase;

use App\Domain\Purchase\PurchaseObligationData;
use App\Domain\Purchase\PurchaseObligationManager;
use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
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
use App\Models\PurchaseObligation;
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

class PurchaseObligationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_recognizes_exact_merchandise_obligation_without_moving_money(): void
    {
        $context = $this->context(
            suffix: 'merchandise',
            logisticsCostMinor: 100
        );

        $beforeCash = DB::table('cash_movements')->count();

        $obligation = app(PurchaseObligationManager::class)
            ->recognize(
                new PurchaseObligationData(
                    purchaseReceiptId:
                        $context['receipt']->id,
                    kind:
                        PurchaseObligationKind::Merchandise,
                    beneficiaryBusinessPartyId: null,
                    paymentCondition:
                        PurchaseObligationCondition::OnReceipt
                ),
                $context['admin']
            );

        $this->assertSame(
            2000,
            $obligation->amount_minor
        );
        $this->assertSame(
            $context['supplier']->business_party_id,
            $obligation->beneficiary_business_party_id
        );
        $this->assertSame(
            'ARS',
            $obligation->currency_code
        );
        $this->assertSame(
            PurchaseObligationKind::Merchandise,
            $obligation->kind
        );
        $this->assertSame(
            PurchaseObligationCondition::OnReceipt,
            $obligation->payment_condition
        );
        $this->assertSame(
            $beforeCash,
            DB::table('cash_movements')->count()
        );
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' =>
                $context['organization']->id,
            'event' => 'purchase_obligation.recognized',
            'auditable_id' => (string) $obligation->id,
        ]);

        $retry = app(PurchaseObligationManager::class)
            ->recognize(
                new PurchaseObligationData(
                    purchaseReceiptId:
                        $context['receipt']->id,
                    kind:
                        PurchaseObligationKind::Merchandise,
                    beneficiaryBusinessPartyId: null,
                    paymentCondition:
                        PurchaseObligationCondition::OnReceipt
                ),
                $context['admin']
            );

        $this->assertSame($obligation->id, $retry->id);
        $this->assertDatabaseCount(
            'purchase_obligations',
            1
        );
    }

    public function test_logistics_obligation_can_use_distinct_beneficiary_and_due_date(): void
    {
        $context = $this->context(
            suffix: 'logistics',
            logisticsCostMinor: 350
        );

        $carrier = BusinessParty::query()->create([
            'organization_id' =>
                $context['organization']->id,
            'party_type' =>
                BusinessParty::TYPE_ORGANIZATION,
            'name' => 'Transportista '.Str::uuid(),
        ]);

        $obligation = app(PurchaseObligationManager::class)
            ->recognize(
                new PurchaseObligationData(
                    purchaseReceiptId:
                        $context['receipt']->id,
                    kind:
                        PurchaseObligationKind::Logistics,
                    beneficiaryBusinessPartyId:
                        $carrier->id,
                    paymentCondition:
                        PurchaseObligationCondition::DueDate,
                    dueOn: '2026-08-20'
                ),
                $context['admin']
            );

        $this->assertSame(350, $obligation->amount_minor);
        $this->assertSame(
            $carrier->id,
            $obligation->beneficiary_business_party_id
        );
        $this->assertSame(
            '2026-08-20',
            $obligation->due_on->format('Y-m-d')
        );

        $this->assertDomainFailure(
            fn () => app(PurchaseObligationManager::class)
                ->recognize(
                    new PurchaseObligationData(
                        purchaseReceiptId:
                            $context['receipt']->id,
                        kind:
                            PurchaseObligationKind::Logistics,
                        beneficiaryBusinessPartyId:
                            $context['supplier']
                                ->business_party_id,
                        paymentCondition:
                            PurchaseObligationCondition::DueDate,
                        dueOn: '2026-08-20'
                    ),
                    $context['admin']
                )
        );
    }

    public function test_operator_foreign_beneficiary_zero_source_and_database_tampering_fail_closed(): void
    {
        $context = $this->context(
            suffix: 'guards',
            logisticsCostMinor: 0
        );

        $this->assertDomainFailure(
            fn () => app(PurchaseObligationManager::class)
                ->recognize(
                    new PurchaseObligationData(
                        purchaseReceiptId:
                            $context['receipt']->id,
                        kind:
                            PurchaseObligationKind::Merchandise,
                        beneficiaryBusinessPartyId: null,
                        paymentCondition:
                            PurchaseObligationCondition::OnReceipt
                    ),
                    $context['operator']
                )
        );

        $this->assertDomainFailure(
            fn () => app(PurchaseObligationManager::class)
                ->recognize(
                    new PurchaseObligationData(
                        purchaseReceiptId:
                            $context['receipt']->id,
                        kind:
                            PurchaseObligationKind::Logistics,
                        beneficiaryBusinessPartyId: null,
                        paymentCondition:
                            PurchaseObligationCondition::OnReceipt
                    ),
                    $context['admin']
                )
        );

        $otherOrganization = Organization::query()->create([
            'name' => 'Otra '.Str::uuid(),
            'slug' => 'other-'.Str::lower(
                Str::random(10)
            ),
            'active' => true,
        ]);
        $foreignParty = BusinessParty::query()->create([
            'organization_id' => $otherOrganization->id,
            'party_type' =>
                BusinessParty::TYPE_ORGANIZATION,
            'name' => 'Ajeno '.Str::uuid(),
        ]);

        $this->assertDomainFailure(
            fn () => app(PurchaseObligationManager::class)
                ->recognize(
                    new PurchaseObligationData(
                        purchaseReceiptId:
                            $context['receipt']->id,
                        kind:
                            PurchaseObligationKind::Merchandise,
                        beneficiaryBusinessPartyId:
                            $foreignParty->id,
                        paymentCondition:
                            PurchaseObligationCondition::OnReceipt
                    ),
                    $context['admin']
                )
        );

        $obligation = app(PurchaseObligationManager::class)
            ->recognize(
                new PurchaseObligationData(
                    purchaseReceiptId:
                        $context['receipt']->id,
                    kind:
                        PurchaseObligationKind::Merchandise,
                    beneficiaryBusinessPartyId: null,
                    paymentCondition:
                        PurchaseObligationCondition::OnReceipt
                ),
                $context['admin']
            );

        $this->assertDomainFailure(function () use (
            $obligation
        ): void {
            $obligation->condition_note = 'Bypass';
            $obligation->save();
        });

        $this->assertQueryRejected(
            fn () => DB::table('purchase_obligations')
                ->where('id', $obligation->id)
                ->update(['amount_minor' => 1])
        );
        $this->assertQueryRejected(
            fn () => DB::table('purchase_obligations')
                ->where('id', $obligation->id)
                ->delete()
        );
    }

    public function test_http_surface_is_admin_only_and_shows_obligation_without_payment(): void
    {
        $context = $this->context(
            suffix: 'http',
            logisticsCostMinor: 100
        );

        $this->actingAs($context['operator']);

        $this->post(
            route(
                'purchase-orders.obligations.store',
                [
                    'purchaseOrder' =>
                        $context['order']->public_id,
                    'purchaseReceipt' =>
                        $context['receipt']->public_id,
                ]
            ),
            [
                'kind' => 'merchandise',
                'payment_condition' => 'on_receipt',
            ]
        )->assertForbidden();

        $this->actingAs($context['admin']);

        $response = $this->post(
            route(
                'purchase-orders.obligations.store',
                [
                    'purchaseOrder' =>
                        $context['order']->public_id,
                    'purchaseReceipt' =>
                        $context['receipt']->public_id,
                ]
            ),
            [
                'kind' => 'merchandise',
                'beneficiary_business_party_id' =>
                    $context['supplier']->business_party_id,
                'payment_condition' => 'on_receipt',
            ]
        );

        $response->assertRedirect();
        $this->assertDatabaseCount(
            'purchase_obligations',
            1
        );
        $this->assertDatabaseCount('cash_movements', 0);

        $this->get(route(
            'purchase-orders.show',
            $context['order']
        ))
            ->assertOk()
            ->assertSee('Obligaciones económicas')
            ->assertSee('No ejecuta ningún pago')
            ->assertSee('REGISTRADA · SIN PAGO');
    }

    /**
     * @return array<string,mixed>
     */
    private function context(
        string $suffix,
        int $logisticsCostMinor
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
        $location = InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Recepción obligación '.Str::uuid(),
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        $this->actingAs($operator);

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey:
                    'p4f1:order:'.$suffix,
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
                        '2026-08-13 09:30:00',
                        'America/Argentina/Buenos_Aires'
                    ),
                    idempotencyKey:
                        'p4f1:receipt:'.$suffix,
                    lines: [
                        new PurchaseReceiptLineData(
                            purchaseOrderLineId:
                                $order->lines->first()->id,
                            quantity: '2',
                            inventoryLocationId:
                                $location->id,
                            condition:
                                InventoryCondition::New,
                            actualUnitCostMinor: 1000
                        ),
                    ],
                    logisticsCostMinor:
                        $logisticsCostMinor,
                    documentReference:
                        'P4F1-'.$suffix
                ),
                $operator
            );

        return compact(
            'organization',
            'operator',
            'admin',
            'supplier',
            'product',
            'location',
            'order',
            'receipt'
        );
    }

    private function user(
        Organization $organization,
        UserRole $role,
        string $suffix
    ): User {
        $token = (string) Str::uuid();

        $user = User::query()->create([
            'name' => $role->label().' '.$suffix,
            'email' => $token.'@p4f1.test',
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
            'party_type' =>
                BusinessParty::TYPE_ORGANIZATION,
            'name' => 'Proveedor P4F1 '
                .$suffix.' '.Str::uuid(),
        ]);

        return Supplier::query()->create([
            'organization_id' => $organization->id,
            'business_party_id' => $party->id,
            'active' => true,
        ]);
    }

    private function product(string $suffix): CatalogProduct
    {
        $category = ProductCategory::query()->firstOrCreate(
            ['slug' => 'p4f1-tests'],
            [
                'name' => 'Pruebas P4F1',
                'active' => true,
            ]
        );

        return CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'sku' => 'P4F1-'.Str::upper($suffix).'-'
                .Str::upper(Str::random(6)),
            'name' => 'Producto obligación '.$suffix,
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
                'Se esperaba que la base rechazara la operación.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
