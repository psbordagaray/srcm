<?php

namespace Tests\Feature\Purchase;

use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Purchase\PurchaseObligationData;
use App\Domain\Purchase\PurchaseObligationManager;
use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchasePaymentGroupItemData;
use App\Domain\Purchase\PurchasePaymentGroupRequestData;
use App\Domain\Purchase\PurchasePaymentGroupRequestManager;
use App\Domain\Purchase\PurchasePaymentRequestData;
use App\Domain\Purchase\PurchasePaymentRequestManager;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementType;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;
use App\Enums\PurchasePaymentRequestStatus;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CashMovement;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\PurchasePaymentDisbursement;
use App\Models\PurchasePaymentGroupRequest;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasePaymentOperationalConvergenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_are_explicit_and_viewer_is_read_only(): void
    {
        foreach ([
            'purchase-payment-operations.index',
            'purchase-payment-groups.store',
            'purchase-payment-groups.approve',
            'purchase-payment-groups.reject',
            'purchase-payment-groups.cancel',
            'purchase-payment-groups.execute',
            'purchase-payment-requests.execute',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName));
        }

        $organization = Organization::query()->create([
            'name' => 'Org P9.7j routes',
            'slug' => 'org-p97j-routes-'.Str::lower(Str::random(6)),
            'active' => true,
        ]);
        $viewer = $this->member($organization, UserRole::Viewer);

        $this->actingAs($viewer)
            ->get(route('purchase-payment-operations.index'))
            ->assertOk()
            ->assertSee('Pagos a proveedores');

        $this->post(route('purchase-payment-groups.store'), [])
            ->assertForbidden();
    }

    public function test_individual_noncash_http_uses_canonical_disbursement_and_external_control(): void
    {
        $context = $this->individualContext(
            'individual-bank',
            FinancialAccountType::BankAccount,
            2400,
            900
        );

        $this->actingAs($context['operator'])
            ->post(
                route(
                    'purchase-payment-requests.execute',
                    $context['paymentRequest']
                ),
                [
                    'confirm_execute' => '1',
                    'execution_reference' => 'TRF-P97J-IND',
                    'execution_note' => 'Transferencia individual',
                    'idempotency_key' =>
                        'purchase-ui:payment-execute:'.Str::uuid(),
                ]
            )
            ->assertRedirect();

        $this->assertDatabaseCount('purchase_payment_executions', 0);
        $this->assertDatabaseCount('purchase_payment_disbursements', 1);
        $this->assertDatabaseCount(
            'purchase_payment_disbursement_allocations',
            1
        );
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('financial_external_movements', 0);

        $this->get(route('purchase-orders.show', $context['order']))
            ->assertOk()
            ->assertSee('Desembolso canónico No efectivo')
            ->assertSee('TRF-P97J-IND')
            ->assertSee('Débito externo pendiente de verificación');
    }

    public function test_group_noncash_http_runs_request_approval_and_one_atomic_disbursement(): void
    {
        $context = $this->groupContext(
            'group-bank',
            FinancialAccountType::BankAccount
        );

        $this->actingAs($context['operator'])
            ->get(route('purchase-payment-operations.index'))
            ->assertOk()
            ->assertSee('Nueva autorización agrupada');

        $this->post(route('purchase-payment-groups.store'), [
            'origin_financial_account_id' => $context['account']->id,
            'items' => [
                [
                    'selected' => '1',
                    'purchase_obligation_id' =>
                        $context['first']['obligation']->public_id,
                    'amount' => '5.00',
                ],
                [
                    'selected' => '1',
                    'purchase_obligation_id' =>
                        $context['second']['obligation']->public_id,
                    'amount' => '7.00',
                ],
            ],
            'request_note' => 'Pago conjunto P9.7j',
            'idempotency_key' =>
                'purchase-ui:payment-group-request:'.Str::uuid(),
        ])->assertRedirect();

        $group = PurchasePaymentGroupRequest::query()
            ->with('items')
            ->sole();

        $this->assertCount(2, $group->items);
        $this->assertSame(1200, (int) $group->items->sum('amount_minor'));

        $this->actingAs($context['admin'])
            ->post(route('purchase-payment-groups.approve', $group), [
                'approval_note' => 'Autorización separada',
                'idempotency_key' =>
                    'purchase-ui:payment-group-approve:'.Str::uuid(),
            ])
            ->assertRedirect();

        $this->actingAs($context['operator'])
            ->post(route('purchase-payment-groups.execute', $group), [
                'confirm_execute' => '1',
                'execution_reference' => 'TRF-P97J-GROUP',
                'execution_note' => 'Transferencia agrupada',
                'idempotency_key' =>
                    'purchase-ui:payment-execute:'.Str::uuid(),
            ])
            ->assertRedirect();

        $this->assertSame(
            PurchasePaymentRequestStatus::Executed,
            $group->refresh()->status
        );
        $this->assertDatabaseCount('purchase_payment_disbursements', 1);
        $this->assertDatabaseCount(
            'purchase_payment_disbursement_allocations',
            2
        );
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('financial_external_movements', 0);

        $this->get(route('purchase-payment-operations.index'))
            ->assertOk()
            ->assertSee('TRF-P97J-GROUP')
            ->assertSee('Débito externo pendiente de verificación');
    }

    public function test_group_cash_http_creates_exactly_one_movement_for_total(): void
    {
        $context = $this->groupContext(
            'group-cash',
            FinancialAccountType::CashBox,
            5000
        );

        $this->actingAs($context['operator']);

        $group = app(
            PurchasePaymentGroupRequestManager::class
        )->request(
            new PurchasePaymentGroupRequestData(
                originFinancialAccountId:
                    $context['account']->id,
                items: [
                    new PurchasePaymentGroupItemData(
                        purchaseObligationId:
                            $context['first']['obligation']->id,
                        amountMinor: 500
                    ),
                    new PurchasePaymentGroupItemData(
                        purchaseObligationId:
                            $context['second']['obligation']->id,
                        amountMinor: 700
                    ),
                ],
                idempotencyKey:
                    'p97j:group-cash:request',
                requestNote: 'Grupo cash P9.7j'
            ),
            $context['operator']
        );

        $this->actingAs($context['admin']);
        $group = app(
            PurchasePaymentGroupRequestManager::class
        )->approve(
            $group,
            'Autorización cash separada',
            'p97j:group-cash:approve',
            $context['admin']
        );

        $this->actingAs($context['operator']);
        $before = app(CashLedgerRecorder::class)
            ->expectedAmountMinor(
                $context['session'],
                $context['operator']
            );

        $this->post(route('purchase-payment-groups.execute', $group), [
            'confirm_execute' => '1',
            'execution_reference' => null,
            'execution_note' => 'Entrega agrupada cash',
            'idempotency_key' =>
                'purchase-ui:payment-execute:'.Str::uuid(),
        ])->assertRedirect();

        $this->assertDatabaseCount('purchase_payment_disbursements', 1);
        $this->assertDatabaseCount(
            'purchase_payment_disbursement_allocations',
            2
        );
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseCount('financial_external_movements', 0);

        $disbursement = PurchasePaymentDisbursement::query()->sole();
        $movement = CashMovement::query()->sole();

        $this->assertSame(1200, $disbursement->amount_minor);
        $this->assertSame(1200, $movement->amount_minor);
        $this->assertSame(
            CashMovementType::PurchasePaymentDisbursement,
            $movement->type
        );
        $this->assertSame(
            $disbursement->id,
            $movement->purchase_payment_disbursement_id
        );
        $this->assertSame(
            $before - 1200,
            app(CashLedgerRecorder::class)
                ->expectedAmountMinor(
                    $context['session'],
                    $context['operator']
                )
        );
    }

    private function individualContext(
        string $suffix,
        FinancialAccountType $type,
        int $obligationMinor,
        int $requestMinor
    ): array {
        $base = $this->baseContext($suffix, $type);
        $purchase = $this->purchase(
            $base,
            $suffix,
            $obligationMinor
        );

        $this->actingAs($base['operator']);
        $paymentRequest = app(
            PurchasePaymentRequestManager::class
        )->request(
            new PurchasePaymentRequestData(
                purchaseObligationId:
                    $purchase['obligation']->id,
                originFinancialAccountId:
                    $base['account']->id,
                amountMinor: $requestMinor,
                requestNote: 'Solicitud P9.7j',
                idempotencyKey:
                    'p97j:individual:request:'.$suffix
            ),
            $base['operator']
        );

        $this->actingAs($base['admin']);
        $paymentRequest = app(
            PurchasePaymentRequestManager::class
        )->approve(
            $paymentRequest,
            'Aprobación P9.7j',
            'p97j:individual:approve:'.$suffix,
            $base['admin']
        );

        return array_merge(
            $base,
            $purchase,
            compact('paymentRequest')
        );
    }

    private function groupContext(
        string $suffix,
        FinancialAccountType $type,
        int $openingMinor = 0
    ): array {
        $base = $this->baseContext(
            $suffix,
            $type,
            $openingMinor
        );
        $first = $this->purchase(
            $base,
            $suffix.'-first',
            2000
        );
        $second = $this->purchase(
            $base,
            $suffix.'-second',
            2000
        );

        return array_merge(
            $base,
            compact('first', 'second')
        );
    }

    private function baseContext(
        string $suffix,
        FinancialAccountType $type,
        int $openingMinor = 0
    ): array {
        $organization = Organization::query()->create([
            'name' => 'Org P9.7j '.$suffix,
            'slug' => 'org-p97j-'.$suffix.'-'.Str::lower(Str::random(6)),
            'active' => true,
        ]);
        $admin = $this->member($organization, UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);
        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_ORGANIZATION,
            'name' => 'Proveedor P9.7j '.$suffix,
        ]);
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'business_party_id' => $party->id,
            'active' => true,
        ]);

        $this->actingAs($admin);
        $account = app(FinancialAccountManager::class)->create(
            'Cuenta P9.7j '.$suffix,
            $type,
            'ARS',
            $admin
        );
        $register = null;
        $session = null;

        if ($type === FinancialAccountType::CashBox) {
            $register = app(CashRegisterManager::class)->create(
                'Caja P9.7j '.$suffix,
                $account,
                $admin
            );
            $this->actingAs($operator);
            $session = app(CashRegisterSessionManager::class)->open(
                $register,
                $openingMinor,
                'p97j:session:'.$suffix,
                $operator
            );
        }

        return compact(
            'organization',
            'admin',
            'operator',
            'party',
            'supplier',
            'account',
            'register',
            'session'
        );
    }

    private function purchase(
        array $context,
        string $suffix,
        int $amountMinor
    ): array {
        $category = ProductCategory::query()->firstOrCreate(
            ['slug' => 'p97j-tests'],
            ['name' => 'Pruebas P9.7j', 'active' => true]
        );
        $product = CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'sku' => 'P97J-'.Str::upper(Str::random(10)),
            'name' => 'Producto P9.7j '.$suffix,
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
            'active' => true,
        ]);
        $location = InventoryLocation::query()->create([
            'organization_id' => $context['organization']->id,
            'name' => 'Recepción P9.7j '.Str::uuid(),
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        $this->actingAs($context['operator']);
        $orders = app(PurchaseOrderManager::class);
        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId: $context['supplier']->id,
                currencyCode: 'ARS',
                idempotencyKey:
                    'p97j:order:'.$suffix.':'.Str::uuid(),
                lines: [
                    new PurchaseOrderLineData(
                        $product->id,
                        '1',
                        $amountMinor
                    ),
                ]
            ),
            $context['operator']
        );
        $order = $orders->issue($order, $context['operator']);
        $order->load('lines');
        $receipt = app(PurchaseReceiptManager::class)->receive(
            new PurchaseReceiptData(
                purchaseOrderId: $order->id,
                receivedAt: CarbonImmutable::parse(
                    '2026-08-17 18:00:00',
                    'America/Argentina/Buenos_Aires'
                ),
                idempotencyKey:
                    'p97j:receipt:'.$suffix.':'.Str::uuid(),
                lines: [
                    new PurchaseReceiptLineData(
                        purchaseOrderLineId:
                            $order->lines->first()->id,
                        quantity: '1',
                        inventoryLocationId: $location->id,
                        condition: InventoryCondition::New,
                        actualUnitCostMinor: $amountMinor
                    ),
                ],
                logisticsCostMinor: 0,
                documentReference: 'P97J-'.$suffix
            ),
            $context['operator']
        );

        $this->actingAs($context['admin']);
        $obligation = app(PurchaseObligationManager::class)->recognize(
            new PurchaseObligationData(
                purchaseReceiptId: $receipt->id,
                kind: PurchaseObligationKind::Merchandise,
                beneficiaryBusinessPartyId: null,
                paymentCondition:
                    PurchaseObligationCondition::OnReceipt
            ),
            $context['admin']
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
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'active' => true,
        ]);
        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();
        app(CurrentOrganization::class)->forget($user);

        return $user->refresh();
    }
}
