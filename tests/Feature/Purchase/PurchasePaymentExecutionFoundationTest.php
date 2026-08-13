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
use App\Domain\Purchase\PurchasePaymentExecutionManager;
use App\Domain\Purchase\PurchasePaymentRequestData;
use App\Domain\Purchase\PurchasePaymentRequestManager;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;
use App\Enums\PurchasePaymentRequestStatus;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CashMovement;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasePaymentExecutionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_cash_payment_executes_once_and_reduces_expected_cash(): void
    {
        $context = $this->context(
            suffix: 'execute',
            obligationMinor: 3500,
            requestedMinor: 3500,
            openingMinor: 10000
        );

        $this->actingAs($context['operator']);
        $manager = app(PurchasePaymentExecutionManager::class);

        $execution = $manager->executeCash(
            $context['paymentRequest'],
            'REC-001',
            'Pago efectivo controlado',
            'p4f3:test:execute:one',
            $context['operator']
        );

        $retry = $manager->executeCash(
            $context['paymentRequest']->refresh(),
            'REC-001',
            'Pago efectivo controlado',
            'p4f3:test:execute:one',
            $context['operator']
        );

        $this->assertSame($execution->id, $retry->id);
        $this->assertDatabaseCount('purchase_payment_executions', 1);
        $this->assertDatabaseCount('cash_movements', 1);

        $movement = CashMovement::query()->sole();

        $this->assertSame(CashMovementDirection::Out, $movement->direction);
        $this->assertSame(CashMovementType::PurchasePayment, $movement->type);
        $this->assertSame(
            $execution->id,
            $movement->purchase_payment_execution_id
        );
        $this->assertSame(
            $context['cash']->id,
            $movement->financial_account_id
        );
        $this->assertNull($movement->destination_financial_account_id);
        $this->assertNull($movement->cash_security_drop_request_id);
        $this->assertSame(3500, $movement->amount_minor);
        $this->assertSame(
            PurchasePaymentRequestStatus::Executed,
            $context['paymentRequest']->refresh()->status
        );
        $this->assertSame(
            6500,
            app(CashLedgerRecorder::class)->expectedAmountMinor(
                $context['session'],
                $context['operator']
            )
        );
    }

    public function test_approver_cannot_execute_and_cash_requires_own_compatible_open_shift(): void
    {
        $context = $this->context(
            suffix: 'authority',
            obligationMinor: 2000,
            requestedMinor: 2000,
            openingMinor: 5000
        );

        $this->actingAs($context['admin']);

        $this->assertDomainFailure(
            fn () => app(PurchasePaymentExecutionManager::class)
                ->executeCash(
                    $context['paymentRequest'],
                    null,
                    null,
                    'p4f3:test:authority:admin',
                    $context['admin']
                )
        );

        $other = $this->member(
            $context['organization'],
            UserRole::Operator
        );
        $this->actingAs($other);

        $this->assertDomainFailure(
            fn () => app(PurchasePaymentExecutionManager::class)
                ->executeCash(
                    $context['paymentRequest'],
                    null,
                    null,
                    'p4f3:test:authority:other',
                    $other
                )
        );

        $this->assertDatabaseCount('purchase_payment_executions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(
            PurchasePaymentRequestStatus::Approved,
            $context['paymentRequest']->refresh()->status
        );
    }

    public function test_execution_fails_when_authorized_amount_exceeds_current_expected_cash(): void
    {
        $context = $this->context(
            suffix: 'insufficient',
            obligationMinor: 5000,
            requestedMinor: 5000,
            openingMinor: 3000
        );

        $this->actingAs($context['operator']);

        $this->assertDomainFailure(
            fn () => app(PurchasePaymentExecutionManager::class)
                ->executeCash(
                    $context['paymentRequest'],
                    null,
                    null,
                    'p4f3:test:insufficient',
                    $context['operator']
                )
        );

        $this->assertDatabaseCount('purchase_payment_executions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(
            PurchasePaymentRequestStatus::Approved,
            $context['paymentRequest']->refresh()->status
        );
    }

    public function test_partial_payment_uses_execution_ledger_for_next_authorization_balance(): void
    {
        $context = $this->context(
            suffix: 'partial',
            obligationMinor: 6000,
            requestedMinor: 2000,
            openingMinor: 10000
        );

        $this->actingAs($context['operator']);

        app(PurchasePaymentExecutionManager::class)->executeCash(
            $context['paymentRequest'],
            null,
            null,
            'p4f3:test:partial:execute',
            $context['operator']
        );

        $requests = app(PurchasePaymentRequestManager::class);

        $this->assertDomainFailure(
            fn () => $requests->request(
                new PurchasePaymentRequestData(
                    purchaseObligationId: $context['obligation']->id,
                    originFinancialAccountId: $context['cash']->id,
                    amountMinor: 5000,
                    requestNote: null,
                    idempotencyKey: 'p4f3:test:partial:too-much'
                ),
                $context['operator']
            )
        );

        $next = $requests->request(
            new PurchasePaymentRequestData(
                purchaseObligationId: $context['obligation']->id,
                originFinancialAccountId: $context['cash']->id,
                amountMinor: 4000,
                requestNote: 'Saldo restante',
                idempotencyKey: 'p4f3:test:partial:remaining'
            ),
            $context['operator']
        );

        $this->assertSame(PurchasePaymentRequestStatus::Pending, $next->status);
        $this->assertSame(4000, $next->amount_minor);
        $this->assertDatabaseCount('purchase_payment_executions', 1);
        $this->assertDatabaseCount('purchase_payment_requests', 2);
    }

    public function test_execution_and_purchase_cash_movement_are_database_immutable_and_fail_closed(): void
    {
        $context = $this->context(
            suffix: 'guards',
            obligationMinor: 2500,
            requestedMinor: 2500,
            openingMinor: 5000
        );

        $this->actingAs($context['operator']);

        $execution = app(PurchasePaymentExecutionManager::class)->executeCash(
            $context['paymentRequest'],
            null,
            null,
            'p4f3:test:guards:execute',
            $context['operator']
        );

        $this->assertQueryRejected(
            fn () => DB::table('purchase_payment_executions')
                ->where('id', $execution->id)
                ->update(['amount_minor' => 1])
        );

        $this->assertQueryRejected(
            fn () => DB::table('purchase_payment_executions')
                ->where('id', $execution->id)
                ->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table('cash_movements')->insert([
                'organization_id' => $context['organization']->id,
                'public_id' => (string) Str::uuid(),
                'cash_register_session_id' => $context['session']->id,
                'cash_register_id' => $context['register']->id,
                'financial_account_id' => $context['cash']->id,
                'destination_financial_account_id' => null,
                'cash_security_drop_request_id' => null,
                'purchase_payment_execution_id' => null,
                'commerce_payment_id' => null,
                'direction' => 'out',
                'type' => 'purchase_payment',
                'reason_code' => null,
                'note' => null,
                'amount_minor' => 1,
                'currency_code' => 'ARS',
                'idempotency_key' => 'p4f3:test:guards:invalid',
                'fingerprint' => str_repeat('a', 64),
                'recorded_by_user_id' => $context['operator']->id,
                'occurred_at' => now(),
                'created_at' => now(),
            ])
        );
    }

    public function test_http_requires_explicit_confirmation_and_renders_executed_payment(): void
    {
        $context = $this->context(
            suffix: 'http',
            obligationMinor: 3000,
            requestedMinor: 3000,
            openingMinor: 6000
        );

        $this->actingAs($context['operator']);

        $route = route(
            'purchase-payment-requests.execute',
            $context['paymentRequest']
        );

        $this->from(route('purchase-orders.show', $context['order']))
            ->post($route, [
                'execution_reference' => 'REC-HTTP',
                'execution_note' => 'Sin confirmación',
                'idempotency_key' =>
                    'purchase-ui:payment-execute:'.Str::uuid(),
            ])
            ->assertSessionHasErrors('confirm_execute');

        $this->assertDatabaseCount('purchase_payment_executions', 0);

        $this->post($route, [
            'confirm_execute' => '1',
            'execution_reference' => 'REC-HTTP',
            'execution_note' => 'Confirmado HTTP',
            'idempotency_key' =>
                'purchase-ui:payment-execute:'.Str::uuid(),
        ])->assertRedirect();

        $this->get(route('purchase-orders.show', $context['order']))
            ->assertOk()
            ->assertSee('EJECUTADA · PAGO REGISTRADO')
            ->assertSee('Pago en efectivo ejecutado')
            ->assertSee('REC-HTTP');

        $this->assertDatabaseCount('purchase_payment_executions', 1);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    /**
     * @return array<string,mixed>
     */
    private function context(
        string $suffix,
        int $obligationMinor,
        int $requestedMinor,
        int $openingMinor
    ): array {
        $organization = Organization::query()->create([
            'name' => 'Org P4F3 '.$suffix,
            'slug' => 'org-p4f3-'.$suffix.'-'.Str::lower(Str::random(6)),
            'active' => true,
        ]);

        $admin = $this->member($organization, UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_ORGANIZATION,
            'name' => 'Proveedor P4F3 '.$suffix,
        ]);

        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'business_party_id' => $party->id,
            'active' => true,
        ]);

        $category = ProductCategory::query()->firstOrCreate(
            ['slug' => 'p4f3-tests'],
            ['name' => 'Pruebas P4F3', 'active' => true]
        );

        $product = CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'sku' => 'P4F3-'.Str::upper(Str::random(10)),
            'name' => 'Producto P4F3 '.$suffix,
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
            'active' => true,
        ]);

        $location = InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Recepción P4F3 '.Str::uuid(),
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        $this->actingAs($operator);

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey: 'p4f3:order:'.$suffix,
                lines: [
                    new PurchaseOrderLineData(
                        $product->id,
                        '1',
                        $obligationMinor
                    ),
                ]
            ),
            $operator
        );
        $order = $orders->issue($order, $operator);

        $receipt = app(PurchaseReceiptManager::class)->receive(
            new PurchaseReceiptData(
                purchaseOrderId: $order->id,
                receivedAt: CarbonImmutable::parse(
                    '2026-08-13 16:00:00',
                    'America/Argentina/Buenos_Aires'
                ),
                idempotencyKey: 'p4f3:receipt:'.$suffix,
                lines: [
                    new PurchaseReceiptLineData(
                        purchaseOrderLineId: $order->lines->first()->id,
                        quantity: '1',
                        inventoryLocationId: $location->id,
                        condition: InventoryCondition::New,
                        actualUnitCostMinor: $obligationMinor
                    ),
                ],
                logisticsCostMinor: 0,
                documentReference: 'P4F3-'.$suffix
            ),
            $operator
        );

        $this->actingAs($admin);

        $obligation = app(PurchaseObligationManager::class)->recognize(
            new PurchaseObligationData(
                purchaseReceiptId: $receipt->id,
                kind: PurchaseObligationKind::Merchandise,
                beneficiaryBusinessPartyId: null,
                paymentCondition: PurchaseObligationCondition::OnReceipt
            ),
            $admin
        );

        $cash = app(FinancialAccountManager::class)->create(
            'Caja P4F3 '.$suffix,
            FinancialAccountType::CashBox,
            'ARS',
            $admin
        );

        $register = app(CashRegisterManager::class)->create(
            'Caja P4F3 '.$suffix,
            $cash,
            $admin
        );

        $this->actingAs($operator);

        $session = app(CashRegisterSessionManager::class)->open(
            $register,
            $openingMinor,
            'p4f3:session:'.$suffix,
            $operator
        );

        $paymentRequest = app(PurchasePaymentRequestManager::class)->request(
            new PurchasePaymentRequestData(
                purchaseObligationId: $obligation->id,
                originFinancialAccountId: $cash->id,
                amountMinor: $requestedMinor,
                requestNote: 'Solicitud P4F3 '.$suffix,
                idempotencyKey: 'p4f3:request:'.$suffix
            ),
            $operator
        );

        $this->actingAs($admin);

        $paymentRequest = app(PurchasePaymentRequestManager::class)->approve(
            $paymentRequest,
            'Aprobación P4F3 '.$suffix,
            'p4f3:approve:'.$suffix,
            $admin
        );

        return compact(
            'organization',
            'admin',
            'operator',
            'party',
            'supplier',
            'product',
            'location',
            'order',
            'receipt',
            'obligation',
            'cash',
            'register',
            'session',
            'paymentRequest'
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

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba una DomainException.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba que la base rechazara la operación.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
