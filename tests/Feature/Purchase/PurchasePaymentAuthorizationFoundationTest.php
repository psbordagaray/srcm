<?php

namespace Tests\Feature\Purchase;

use App\Domain\Attention\OperationalAttentionReader;
use App\Domain\Purchase\PurchaseObligationData;
use App\Domain\Purchase\PurchaseObligationManager;
use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchasePaymentRequestData;
use App\Domain\Purchase\PurchasePaymentRequestManager;
use App\Domain\Purchase\PurchaseReceiptData;
use App\Domain\Purchase\PurchaseReceiptLineData;
use App\Domain\Purchase\PurchaseReceiptManager;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;
use App\Enums\PurchasePaymentRequestStatus;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\FinancialAccount;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\PurchasePaymentRequest;
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

class PurchasePaymentAuthorizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_operator_requests_payment_authorization_without_moving_money_and_retry_is_idempotent(): void
    {
        $context = $this->context('request');

        $this->actingAs($context['operator']);
        $beforeCash = DB::table('cash_movements')->count();

        $request = app(PurchasePaymentRequestManager::class)
            ->request(
                new PurchasePaymentRequestData(
                    purchaseObligationId:
                        $context['obligation']->id,
                    originFinancialAccountId:
                        $context['origin']->id,
                    amountMinor: 1500,
                    requestNote: 'Pago parcial controlado',
                    idempotencyKey:
                        'p4f2:test:request:request'
                ),
                $context['operator']
            );

        $this->assertSame(
            PurchasePaymentRequestStatus::Pending,
            $request->status
        );
        $this->assertSame(1500, $request->amount_minor);
        $this->assertSame(
            $context['obligation']
                ->beneficiary_business_party_id,
            $request->beneficiary_business_party_id
        );
        $this->assertSame(
            $context['origin']->id,
            $request->origin_financial_account_id
        );
        $this->assertSame(
            $beforeCash,
            DB::table('cash_movements')->count()
        );

        $retry = app(PurchasePaymentRequestManager::class)
            ->request(
                new PurchasePaymentRequestData(
                    purchaseObligationId:
                        $context['obligation']->id,
                    originFinancialAccountId:
                        $context['origin']->id,
                    amountMinor: 1500,
                    requestNote: 'Pago parcial controlado',
                    idempotencyKey:
                        'p4f2:test:request:request'
                ),
                $context['operator']
            );

        $this->assertSame($request->id, $retry->id);

        $this->assertDomainFailure(
            fn () => app(
                PurchasePaymentRequestManager::class
            )->request(
                new PurchasePaymentRequestData(
                    purchaseObligationId:
                        $context['obligation']->id,
                    originFinancialAccountId:
                        $context['origin']->id,
                    amountMinor: 1000,
                    requestNote: null,
                    idempotencyKey:
                        'p4f2:test:request:second'
                ),
                $context['operator']
            )
        );

        $this->assertDatabaseCount(
            'purchase_payment_requests',
            1
        );
    }

    public function test_admin_approves_separately_and_self_approval_or_database_tampering_fail_closed(): void
    {
        $self = $this->context('self-approval');

        $this->actingAs($self['admin']);
        $selfRequest = app(
            PurchasePaymentRequestManager::class
        )->request(
            new PurchasePaymentRequestData(
                purchaseObligationId:
                    $self['obligation']->id,
                originFinancialAccountId:
                    $self['origin']->id,
                amountMinor: 1000,
                requestNote: null,
                idempotencyKey:
                    'p4f2:test:request:self-approval'
            ),
            $self['admin']
        );

        $this->assertDomainFailure(
            fn () => app(
                PurchasePaymentRequestManager::class
            )->approve(
                $selfRequest,
                null,
                'p4f2:test:approve:self',
                $self['admin']
            )
        );

        $this->assertQueryRejected(
            fn () => DB::table('purchase_payment_requests')
                ->where('id', $selfRequest->id)
                ->update([
                    'status' => 'approved',
                    'approved_by_user_id' =>
                        $self['admin']->id,
                    'approval_idempotency_key' =>
                        'db-self-approval',
                    'approval_fingerprint' =>
                        str_repeat('a', 64),
                    'approved_at' => now(),
                ])
        );

        $context = $this->context('approval');

        $this->actingAs($context['operator']);
        $request = $this->request($context, 2000);

        $this->actingAs($context['admin']);
        $approved = app(
            PurchasePaymentRequestManager::class
        )->approve(
            $request,
            'Aprobación separada',
            'p4f2:test:approve:admin',
            $context['admin']
        );

        $this->assertSame(
            PurchasePaymentRequestStatus::Approved,
            $approved->status
        );
        $this->assertSame(
            $context['admin']->id,
            $approved->approved_by_user_id
        );
        $this->assertNotNull(
            $approved->approval_fingerprint
        );
        $this->assertSame(
            64,
            strlen($approved->approval_fingerprint)
        );
        $this->assertDatabaseCount('cash_movements', 0);

        $this->assertQueryRejected(
            fn () => DB::table('purchase_payment_requests')
                ->where('id', $approved->id)
                ->update(['amount_minor' => 1])
        );

        $this->assertQueryRejected(
            fn () => DB::table('purchase_payment_requests')
                ->where('id', $approved->id)
                ->delete()
        );
    }

    public function test_reject_cancel_and_expire_are_explicit_resolutions_without_money(): void
    {
        $reject = $this->context('reject');
        $this->actingAs($reject['operator']);
        $rejectRequest = $this->request($reject, 1000);

        $this->actingAs($reject['admin']);
        $rejected = app(
            PurchasePaymentRequestManager::class
        )->reject(
            $rejectRequest,
            'No corresponde pagar todavía',
            'p4f2:test:resolution:reject',
            $reject['admin']
        );

        $this->assertSame(
            PurchasePaymentRequestStatus::Rejected,
            $rejected->status
        );

        $cancel = $this->context('cancel');
        $this->actingAs($cancel['operator']);
        $cancelRequest = $this->request($cancel, 1000);
        $cancelled = app(
            PurchasePaymentRequestManager::class
        )->cancel(
            $cancelRequest,
            'Solicitante desiste',
            'p4f2:test:resolution:cancel',
            $cancel['operator']
        );

        $this->assertSame(
            PurchasePaymentRequestStatus::Cancelled,
            $cancelled->status
        );

        $expire = $this->context('expire');
        $this->actingAs($expire['operator']);
        $expireRequest = $this->request($expire, 1000);

        $this->actingAs($expire['admin']);
        $expired = app(
            PurchasePaymentRequestManager::class
        )->expire(
            $expireRequest,
            'Contexto de autorización vencido',
            'p4f2:test:resolution:expire',
            $expire['admin']
        );

        $this->assertSame(
            PurchasePaymentRequestStatus::Expired,
            $expired->status
        );
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_http_and_operational_attention_are_bidirectional_and_approval_still_does_not_execute(): void
    {
        $context = $this->context('http');

        $this->actingAs($context['operator']);

        $response = $this->post(
            route(
                'purchase-payment-requests.store',
                [
                    'purchaseOrder' =>
                        $context['order']->public_id,
                    'purchaseObligation' =>
                        $context['obligation']->public_id,
                ]
            ),
            [
                'origin_financial_account_id' =>
                    $context['origin']->id,
                'amount' => '20.00',
                'request_note' =>
                    'Solicitar pago P4F2 HTTP',
                'idempotency_key' =>
                    'purchase-ui:payment-request:'
                    .Str::uuid(),
            ]
        );

        $response->assertRedirect();

        $paymentRequest =
            PurchasePaymentRequest::query()->sole();

        $adminAttention = app(
            OperationalAttentionReader::class
        )->read($context['admin']);

        $this->assertTrue(
            $adminAttention['items']->contains(
                fn (array $item): bool =>
                    $item['source_type']
                        === 'purchase_payment_request'
                    && $item['state'] === 'pending'
                    && str_contains(
                        $item['title'],
                        'Autorizar pago'
                    )
            )
        );

        $this->actingAs($context['operator']);
        $this->post(
            route(
                'purchase-payment-requests.approve',
                $paymentRequest
            ),
            [
                'approval_note' => 'No debe pasar',
                'idempotency_key' =>
                    'purchase-ui:payment-approve:'
                    .Str::uuid(),
            ]
        )->assertForbidden();

        $this->actingAs($context['admin']);

        $this->post(
            route(
                'purchase-payment-requests.approve',
                $paymentRequest
            ),
            [
                'approval_note' =>
                    'Autorizado por Admin',
                'idempotency_key' =>
                    'purchase-ui:payment-approve:'
                    .Str::uuid(),
            ]
        )->assertRedirect();

        $paymentRequest->refresh();
        $this->assertSame(
            PurchasePaymentRequestStatus::Approved,
            $paymentRequest->status
        );

        $operatorAttention = app(
            OperationalAttentionReader::class
        )->read($context['operator']);

        $this->assertTrue(
            $operatorAttention['items']->contains(
                fn (array $item): bool =>
                    $item['source_type']
                        === 'purchase_payment_request'
                    && $item['state'] === 'approved'
                    && str_contains(
                        $item['title'],
                        'ejecución pendiente'
                    )
            )
        );

        $this->get(route(
            'purchase-orders.show',
            $context['order']
        ))
            ->assertOk()
            ->assertSee('AUTORIZADA · SIN EJECUCIÓN')
            ->assertSee('Autorizar no mueve dinero');

        $this->assertDatabaseCount('cash_movements', 0);
    }

    /**
     * @return array<string,mixed>
     */
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
            'name' => 'Recepción P4F2 '.Str::uuid(),
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
                    'p4f2:order:'.$suffix,
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
                        '2026-08-13 12:00:00',
                        'America/Argentina/Buenos_Aires'
                    ),
                    idempotencyKey:
                        'p4f2:receipt:'.$suffix,
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
                    logisticsCostMinor: 0,
                    documentReference:
                        'P4F2-'.$suffix
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

        $origin = FinancialAccount::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Origen P4F2 '.$suffix,
            'normalized_name' =>
                'origen-p4f2-'.$suffix,
            'type' => FinancialAccountType::CashBox,
            'provider' => null,
            'currency_code' => 'ARS',
            'external_label' => null,
            'active' => true,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        return compact(
            'organization',
            'operator',
            'admin',
            'supplier',
            'product',
            'location',
            'order',
            'receipt',
            'obligation',
            'origin'
        );
    }

    private function request(
        array $context,
        int $amountMinor
    ): PurchasePaymentRequest {
        return app(PurchasePaymentRequestManager::class)
            ->request(
                new PurchasePaymentRequestData(
                    purchaseObligationId:
                        $context['obligation']->id,
                    originFinancialAccountId:
                        $context['origin']->id,
                    amountMinor: $amountMinor,
                    requestNote: null,
                    idempotencyKey:
                        'p4f2:test:request:'.Str::uuid()
                ),
                $context['operator']
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
            'email' => $token.'@p4f2.test',
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
            'name' => 'Proveedor P4F2 '
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
            ['slug' => 'p4f2-tests'],
            [
                'name' => 'Pruebas P4F2',
                'active' => true,
            ]
        );

        return CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'sku' => 'P4F2-'.Str::upper($suffix).'-'
                .Str::upper(Str::random(6)),
            'name' => 'Producto autorización '.$suffix,
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
