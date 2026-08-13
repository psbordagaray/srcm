<?php

namespace Tests\Feature\Purchase;

use App\Domain\Attention\OperationalAttentionManager;
use App\Domain\Attention\OperationalAttentionReader;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Purchase\PurchaseObligationData;
use App\Domain\Purchase\PurchaseObligationManager;
use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchasePaymentControlReader;
use App\Domain\Purchase\PurchasePaymentExecutionManager;
use App\Domain\Purchase\PurchasePaymentRequestData;
use App\Domain\Purchase\PurchasePaymentRequestManager;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashCountDifferenceReason;
use App\Enums\FinancialAccountType;
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
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasePaymentAttentionControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_executed_cash_payment_becomes_acknowledgeable_result_with_pending_cash_control(): void
    {
        $context = $this->context(
            suffix: 'attention',
            obligationMinor: 3500,
            requestedMinor: 3500,
            openingMinor: 10000
        );

        $this->actingAs($context['operator']);

        $execution = app(PurchasePaymentExecutionManager::class)->executeCash(
            $context['paymentRequest'],
            'P4F4-ATTENTION',
            'Resultado a distribuir',
            'p4f4:attention:execute',
            $context['operator']
        );

        $control = app(PurchasePaymentControlReader::class)->read(
            $execution,
            $context['operator']
        );

        $this->assertSame(
            'cash_recorded_pending_count',
            $control['state']
        );
        $this->assertSame(
            'cash_register_closure',
            $control['reconciliation_mode']
        );
        $this->assertFalse(
            $control['external_verification_applicable']
        );
        $this->assertNull($control['difference_minor']);

        $attention = app(OperationalAttentionReader::class)
            ->read($context['operator']);

        $item = $attention['items']->first(
            fn (array $candidate): bool =>
                $candidate['source_type']
                    === 'purchase_payment_request'
                && $candidate['source_public_id']
                    === $context['paymentRequest']->public_id
        );

        $this->assertNotNull($item);
        $this->assertSame('result', $item['kind']);
        $this->assertSame(
            'executed:cash_recorded_pending_count',
            $item['state']
        );
        $this->assertSame(
            'Pago ejecutado · resultado registrado',
            $item['title']
        );
        $this->assertTrue($item['acknowledgeable']);
        $this->assertStringContainsString(
            'control físico pendiente',
            $item['detail']
        );

        $this->get(
            route('purchase-orders.show', $context['order'])
        )
            ->assertOk()
            ->assertSee('P4F.4 · Control y conciliación')
            ->assertSee('Caja registrada · control físico pendiente')
            ->assertSee(
                'no crea movimiento externo ni conciliación financiera'
            );

        app(OperationalAttentionManager::class)->acknowledge(
            $context['operator'],
            $item['key']
        );

        $this->assertDatabaseHas(
            'operational_attention_receipts',
            [
                'organization_id' =>
                    $context['organization']->id,
                'user_id' =>
                    $context['operator']->id,
                'attention_key' => $item['key'],
                'source_type' =>
                    'purchase_payment_request',
                'source_public_id' =>
                    $context['paymentRequest']->public_id,
            ]
        );

        $afterAck = app(OperationalAttentionReader::class)
            ->read($context['operator']);

        $this->assertNull(
            $afterAck['items']->first(
                fn (array $candidate): bool =>
                    $candidate['source_type']
                        === 'purchase_payment_request'
                    && $candidate['source_public_id']
                        === $context['paymentRequest']->public_id
            )
        );

        $this->actingAs($context['admin']);

        $adminAttention = app(OperationalAttentionReader::class)
            ->read($context['admin']);

        $this->assertNull(
            $adminAttention['items']->first(
                fn (array $candidate): bool =>
                    $candidate['source_type']
                        === 'purchase_payment_request'
                    && $candidate['source_public_id']
                        === $context['paymentRequest']->public_id
            )
        );
    }

    public function test_exact_cash_close_changes_projection_and_reappears_after_previous_acknowledgement(): void
    {
        $context = $this->context(
            suffix: 'exact-close',
            obligationMinor: 3500,
            requestedMinor: 3500,
            openingMinor: 10000
        );

        $this->actingAs($context['operator']);

        $execution = app(PurchasePaymentExecutionManager::class)->executeCash(
            $context['paymentRequest'],
            null,
            null,
            'p4f4:exact:execute',
            $context['operator']
        );

        $attention = app(OperationalAttentionReader::class)
            ->read($context['operator']);

        $pendingItem = $attention['items']->first(
            fn (array $candidate): bool =>
                $candidate['source_public_id']
                    === $context['paymentRequest']->public_id
        );

        $this->assertNotNull($pendingItem);

        app(OperationalAttentionManager::class)->acknowledge(
            $context['operator'],
            $pendingItem['key']
        );

        app(CashRegisterSessionManager::class)->closeCurrent(
            6500,
            null,
            'Cierre exacto P4F.4',
            'p4f4:exact:close',
            $context['operator']
        );

        $control = app(PurchasePaymentControlReader::class)->read(
            $execution,
            $context['operator']
        );

        $this->assertSame(
            'cash_counted_exact',
            $control['state']
        );
        $this->assertSame(0, $control['difference_minor']);
        $this->assertFalse(
            $control['external_verification_applicable']
        );

        $afterClose = app(OperationalAttentionReader::class)
            ->read($context['operator']);

        $closedItem = $afterClose['items']->first(
            fn (array $candidate): bool =>
                $candidate['source_public_id']
                    === $context['paymentRequest']->public_id
        );

        $this->assertNotNull($closedItem);
        $this->assertNotSame(
            $pendingItem['key'],
            $closedItem['key']
        );
        $this->assertSame(
            'executed:cash_counted_exact',
            $closedItem['state']
        );
        $this->assertStringContainsString(
            'sin diferencia',
            $closedItem['detail']
        );

        $this->assertDatabaseHas(
            'purchase_payment_executions',
            [
                'id' => $execution->id,
                'amount_minor' => 3500,
            ]
        );
        $this->assertDatabaseHas(
            'cash_movements',
            [
                'purchase_payment_execution_id' =>
                    $execution->id,
                'amount_minor' => 3500,
                'direction' => 'out',
                'type' => 'purchase_payment',
            ]
        );
    }

    public function test_cash_close_difference_is_control_warning_and_never_rewrites_payment(): void
    {
        $context = $this->context(
            suffix: 'difference',
            obligationMinor: 3500,
            requestedMinor: 3500,
            openingMinor: 10000
        );

        $this->actingAs($context['operator']);

        $execution = app(PurchasePaymentExecutionManager::class)->executeCash(
            $context['paymentRequest'],
            null,
            null,
            'p4f4:difference:execute',
            $context['operator']
        );

        app(CashRegisterSessionManager::class)->closeCurrent(
            6400,
            CashCountDifferenceReason::Unexplained,
            'Faltan ARS 1,00 en conteo de prueba.',
            'p4f4:difference:close',
            $context['operator']
        );

        $control = app(PurchasePaymentControlReader::class)->read(
            $execution,
            $context['operator']
        );

        $this->assertSame(
            'cash_counted_difference',
            $control['state']
        );
        $this->assertSame('warning', $control['severity']);
        $this->assertSame(-100, $control['difference_minor']);
        $this->assertStringContainsString(
            '-ARS 1,00',
            $control['detail']
        );
        $this->assertStringContainsString(
            'no reescribe',
            $control['detail']
        );

        $attention = app(OperationalAttentionReader::class)
            ->read($context['operator']);

        $item = $attention['items']->first(
            fn (array $candidate): bool =>
                $candidate['source_public_id']
                    === $context['paymentRequest']->public_id
        );

        $this->assertNotNull($item);
        $this->assertSame(
            'executed:cash_counted_difference',
            $item['state']
        );
        $this->assertSame('warning', $item['severity']);
        $this->assertTrue($item['acknowledgeable']);

        $this->assertDatabaseHas(
            'purchase_payment_executions',
            [
                'id' => $execution->id,
                'amount_minor' => 3500,
            ]
        );
        $this->assertDatabaseHas(
            'cash_movements',
            [
                'purchase_payment_execution_id' =>
                    $execution->id,
                'amount_minor' => 3500,
                'direction' => 'out',
                'type' => 'purchase_payment',
            ]
        );
        $this->assertDatabaseHas(
            'cash_register_closures',
            [
                'cash_register_session_id' =>
                    $context['session']->id,
                'expected_amount_minor' => 6500,
                'counted_amount_minor' => 6400,
                'difference_minor' => -100,
            ]
        );
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
            'name' => 'Org P4F4 '.$suffix,
            'slug' =>
                'org-p4f4-'.$suffix.'-'.
                Str::lower(Str::random(6)),
            'active' => true,
        ]);

        $admin = $this->member(
            $organization,
            UserRole::Admin
        );
        $operator = $this->member(
            $organization,
            UserRole::Operator
        );

        $party = BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' =>
                BusinessParty::TYPE_ORGANIZATION,
            'name' => 'Proveedor P4F4 '.$suffix,
        ]);

        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'business_party_id' => $party->id,
            'active' => true,
        ]);

        $category = ProductCategory::query()->firstOrCreate(
            ['slug' => 'p4f4-tests'],
            [
                'name' => 'Pruebas P4F4',
                'active' => true,
            ]
        );

        $product = CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'sku' =>
                'P4F4-'.Str::upper(Str::random(10)),
            'name' => 'Producto P4F4 '.$suffix,
            'base_unit_code' => 'unit',
            'quantity_scale' => 0,
            'active' => true,
        ]);

        $location = InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Recepción P4F4 '.Str::uuid(),
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
                    'p4f4:order:'.$suffix,
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

        $order = $orders->issue(
            $order,
            $operator
        );

        $receipt = app(PurchaseReceiptManager::class)
            ->receive(
                new PurchaseReceiptData(
                    purchaseOrderId: $order->id,
                    receivedAt: CarbonImmutable::parse(
                        '2026-08-13 20:00:00',
                        'America/Argentina/Buenos_Aires'
                    ),
                    idempotencyKey:
                        'p4f4:receipt:'.$suffix,
                    lines: [
                        new PurchaseReceiptLineData(
                            purchaseOrderLineId:
                                $order->lines->first()->id,
                            quantity: '1',
                            inventoryLocationId:
                                $location->id,
                            condition:
                                InventoryCondition::New,
                            actualUnitCostMinor:
                                $obligationMinor
                        ),
                    ],
                    logisticsCostMinor: 0,
                    documentReference:
                        'P4F4-'.$suffix
                ),
                $operator
            );

        $this->actingAs($admin);

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

        $cash = app(
            FinancialAccountManager::class
        )->create(
            'Caja P4F4 '.$suffix,
            FinancialAccountType::CashBox,
            'ARS',
            $admin
        );

        $register = app(
            CashRegisterManager::class
        )->create(
            'Caja P4F4 '.$suffix,
            $cash,
            $admin
        );

        $this->actingAs($operator);

        $session = app(
            CashRegisterSessionManager::class
        )->open(
            $register,
            $openingMinor,
            'p4f4:session:'.$suffix,
            $operator
        );

        $paymentRequest = app(
            PurchasePaymentRequestManager::class
        )->request(
            new PurchasePaymentRequestData(
                purchaseObligationId:
                    $obligation->id,
                originFinancialAccountId:
                    $cash->id,
                amountMinor:
                    $requestedMinor,
                requestNote:
                    'Solicitud P4F4 '.$suffix,
                idempotencyKey:
                    'p4f4:request:'.$suffix
            ),
            $operator
        );

        $this->actingAs($admin);

        $paymentRequest = app(
            PurchasePaymentRequestManager::class
        )->approve(
            $paymentRequest,
            'Aprobación P4F4 '.$suffix,
            'p4f4:approve:'.$suffix,
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
            'organization_id' =>
                $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'active' => true,
        ]);

        $user->forceFill([
            'current_organization_id' =>
                $organization->id,
        ])->saveQuietly();

        app(CurrentOrganization::class)->forget($user);

        return $user->refresh();
    }
}
