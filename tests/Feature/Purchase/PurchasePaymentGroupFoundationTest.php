<?php

namespace Tests\Feature\Purchase;

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
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;
use App\Enums\PurchasePaymentRequestStatus;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\PurchasePaymentGroupRequest;
use App\Models\PurchasePaymentGroupRequestItem;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasePaymentGroupFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_requests_two_obligations_idempotently_without_money_effect(): void
    {
        $context = $this->context('request');

        $manager = app(
            PurchasePaymentGroupRequestManager::class
        );

        $data = $this->groupData(
            $context,
            'request'
        );

        $request = $manager->request(
            $data,
            $context['operator']
        );
        $retry = $manager->request(
            $data,
            $context['operator']
        );

        $this->assertSame(
            $request->id,
            $retry->id
        );
        $this->assertSame(
            PurchasePaymentRequestStatus::Pending,
            $request->status
        );
        $this->assertCount(
            2,
            $request->items
        );
        $this->assertSame(
            1200,
            (int) $request->items
                ->sum('amount_minor')
        );
        $this->assertDatabaseCount(
            'purchase_payment_group_requests',
            1
        );
        $this->assertDatabaseCount(
            'purchase_payment_group_request_items',
            2
        );
        $this->assertDatabaseCount(
            'purchase_payment_executions',
            0
        );
        $this->assertDatabaseCount(
            'cash_movements',
            0
        );
        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
    }

    public function test_admin_approves_separately_and_group_still_does_not_settle_debt(): void
    {
        $context = $this->context('approve');

        $manager = app(
            PurchasePaymentGroupRequestManager::class
        );

        $request = $manager->request(
            $this->groupData(
                $context,
                'approve'
            ),
            $context['operator']
        );

        $this->assertDomainFailure(
            fn () => $manager->approve(
                $request,
                null,
                'p97h:self-approve',
                $context['operator']
            )
        );

        $approved = $manager->approve(
            $request,
            'Autorización agrupada',
            'p97h:approve',
            $context['admin']
        );

        $retry = $manager->approve(
            $request,
            'Autorización agrupada',
            'p97h:approve',
            $context['admin']
        );

        $this->assertSame(
            $approved->id,
            $retry->id
        );
        $this->assertSame(
            PurchasePaymentRequestStatus::Approved,
            $approved->status
        );
        $this->assertSame(
            $context['admin']->id,
            $approved->approved_by_user_id
        );
        $this->assertDatabaseCount(
            'purchase_payment_executions',
            0
        );

        foreach (
            [
                $context['first']['obligation'],
                $context['second']['obligation'],
            ] as $obligation
        ) {
            $this->assertSame(
                2000,
                app(
                    \App\Domain\Purchase\PurchaseObligationBalanceReader::class
                )->read($obligation)
                    ['remaining_minor']
            );
        }
    }

    public function test_group_requires_two_distinct_compatible_obligations_and_exact_balance(): void
    {
        $context = $this->context('guards');

        $manager = app(
            PurchasePaymentGroupRequestManager::class
        );

        $this->assertDomainFailure(
            fn () => $manager->request(
                new PurchasePaymentGroupRequestData(
                    originFinancialAccountId:
                        $context['account']->id,
                    items: [
                        new PurchasePaymentGroupItemData(
                            purchaseObligationId:
                                $context['first']
                                    ['obligation']->id,
                            amountMinor: 500
                        ),
                    ],
                    idempotencyKey:
                        'p97h:one-item'
                ),
                $context['operator']
            )
        );

        $this->assertDomainFailure(
            fn () => $manager->request(
                new PurchasePaymentGroupRequestData(
                    originFinancialAccountId:
                        $context['account']->id,
                    items: [
                        new PurchasePaymentGroupItemData(
                            purchaseObligationId:
                                $context['first']
                                    ['obligation']->id,
                            amountMinor: 500
                        ),
                        new PurchasePaymentGroupItemData(
                            purchaseObligationId:
                                $context['first']
                                    ['obligation']->id,
                            amountMinor: 400
                        ),
                    ],
                    idempotencyKey:
                        'p97h:duplicate'
                ),
                $context['operator']
            )
        );

        $this->assertDomainFailure(
            fn () => $manager->request(
                new PurchasePaymentGroupRequestData(
                    originFinancialAccountId:
                        $context['account']->id,
                    items: [
                        new PurchasePaymentGroupItemData(
                            purchaseObligationId:
                                $context['first']
                                    ['obligation']->id,
                            amountMinor: 2001
                        ),
                        new PurchasePaymentGroupItemData(
                            purchaseObligationId:
                                $context['second']
                                    ['obligation']->id,
                            amountMinor: 500
                        ),
                    ],
                    idempotencyKey:
                        'p97h:overbalance'
                ),
                $context['operator']
            )
        );

        $thirdParty = BusinessParty::query()
            ->create([
                'organization_id' =>
                    $context['organization']->id,
                'party_type' =>
                    BusinessParty::TYPE_ORGANIZATION,
                'name' =>
                    'Logística P9.7h '.Str::uuid(),
            ]);

        $logistics = app(
            PurchaseObligationManager::class
        )->recognize(
            new PurchaseObligationData(
                purchaseReceiptId:
                    $context['first']
                        ['receipt']->id,
                kind:
                    PurchaseObligationKind::Logistics,
                beneficiaryBusinessPartyId:
                    $thirdParty->id,
                paymentCondition:
                    PurchaseObligationCondition::OnReceipt
            ),
            $context['admin']
        );

        $this->assertDomainFailure(
            fn () => $manager->request(
                new PurchasePaymentGroupRequestData(
                    originFinancialAccountId:
                        $context['account']->id,
                    items: [
                        new PurchasePaymentGroupItemData(
                            purchaseObligationId:
                                $context['first']
                                    ['obligation']->id,
                            amountMinor: 500
                        ),
                        new PurchasePaymentGroupItemData(
                            purchaseObligationId:
                                $logistics->id,
                            amountMinor: 100
                        ),
                    ],
                    idempotencyKey:
                        'p97h:mixed-beneficiary'
                ),
                $context['operator']
            )
        );

        $this->assertDatabaseCount(
            'purchase_payment_group_requests',
            0
        );
    }

    public function test_active_group_blocks_individual_payment_request(): void
    {
        $context = $this->context('exclusive');

        $group = app(
            PurchasePaymentGroupRequestManager::class
        )->request(
            $this->groupData(
                $context,
                'exclusive'
            ),
            $context['operator']
        );

        $this->assertSame(
            PurchasePaymentRequestStatus::Pending,
            $group->status
        );

        $this->assertDomainFailure(
            fn () => app(
                PurchasePaymentRequestManager::class
            )->request(
                new PurchasePaymentRequestData(
                    purchaseObligationId:
                        $context['first']
                            ['obligation']->id,
                    originFinancialAccountId:
                        $context['account']->id,
                    amountMinor: 100,
                    requestNote: null,
                    idempotencyKey:
                        'p97h:individual-conflict'
                ),
                $context['operator']
            )
        );

        $this->assertDatabaseCount(
            'purchase_payment_requests',
            0
        );
    }

    public function test_cancellation_releases_obligations_for_individual_authorization(): void
    {
        $context = $this->context('cancel');

        $manager = app(
            PurchasePaymentGroupRequestManager::class
        );

        $group = $manager->request(
            $this->groupData(
                $context,
                'cancel'
            ),
            $context['operator']
        );

        $cancelled = $manager->cancel(
            $group,
            'Se pagará por separado',
            'p97h:cancel',
            $context['operator']
        );

        $this->assertSame(
            PurchasePaymentRequestStatus::Cancelled,
            $cancelled->status
        );

        $individual = app(
            PurchasePaymentRequestManager::class
        )->request(
            new PurchasePaymentRequestData(
                purchaseObligationId:
                    $context['first']
                        ['obligation']->id,
                originFinancialAccountId:
                    $context['account']->id,
                amountMinor: 300,
                requestNote: null,
                idempotencyKey:
                    'p97h:individual-after-cancel'
            ),
            $context['operator']
        );

        $this->assertSame(
            300,
            $individual->amount_minor
        );
        $this->assertSame(
            PurchasePaymentRequestStatus::Pending,
            $individual->status
        );
    }

    public function test_items_are_append_only_and_database_guards_cross_tenant_forgery(): void
    {
        $context = $this->context('immutable');

        $request = app(
            PurchasePaymentGroupRequestManager::class
        )->request(
            $this->groupData(
                $context,
                'immutable'
            ),
            $context['operator']
        );

        $item = $request->items->first();

        $this->assertNotNull($item);

        $this->assertDomainFailure(
            function () use ($item): void {
                $item->amount_minor = 1;
                $item->save();
            }
        );

        $this->assertDomainFailure(
            fn () => $item->delete()
        );

        $other = $this->context('foreign');

        $this->assertQueryRejected(
            fn () => DB::table(
                'purchase_payment_group_request_items'
            )->insert([
                'organization_id' =>
                    $context['organization']->id,
                'purchase_payment_group_request_id' =>
                    $request->id,
                'purchase_obligation_id' =>
                    $other['first']
                        ['obligation']->id,
                'amount_minor' => 100,
                'fingerprint' =>
                    str_repeat('a', 64),
                'created_at' => now(),
            ])
        );

        $this->assertTrue(
            Schema::hasTable(
                'purchase_payment_group_requests'
            )
        );
        $this->assertTrue(
            Schema::hasTable(
                'purchase_payment_group_request_items'
            )
        );
        $this->assertDatabaseCount(
            'purchase_payment_group_request_items',
            2
        );
    }

    private function groupData(
        array $context,
        string $suffix
    ): PurchasePaymentGroupRequestData {
        return new PurchasePaymentGroupRequestData(
            originFinancialAccountId:
                $context['account']->id,
            items: [
                new PurchasePaymentGroupItemData(
                    purchaseObligationId:
                        $context['first']
                            ['obligation']->id,
                    amountMinor: 500
                ),
                new PurchasePaymentGroupItemData(
                    purchaseObligationId:
                        $context['second']
                            ['obligation']->id,
                    amountMinor: 700
                ),
            ],
            idempotencyKey:
                'p97h:group:'.$suffix,
            requestNote:
                'Pago agrupado '.$suffix
        );
    }

    private function context(string $suffix): array
    {
        $organization = Organization::query()
            ->create([
                'name' =>
                    'Org P9.7h '.$suffix,
                'slug' =>
                    'org-p97h-'.$suffix.'-'
                    .Str::lower(
                        Str::random(6)
                    ),
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

        $party = BusinessParty::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'party_type' =>
                    BusinessParty::TYPE_ORGANIZATION,
                'name' =>
                    'Proveedor P9.7h '.$suffix,
            ]);

        $supplier = Supplier::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'business_party_id' =>
                    $party->id,
                'active' => true,
            ]);

        $this->actingAs($admin);

        $account = app(
            FinancialAccountManager::class
        )->create(
            'Banco P9.7h '.$suffix,
            FinancialAccountType::BankAccount,
            'ARS',
            $admin
        );

        $base = compact(
            'organization',
            'admin',
            'operator',
            'supplier',
            'account'
        );

        $first = $this->purchase(
            $base,
            $suffix.'-a'
        );
        $second = $this->purchase(
            $base,
            $suffix.'-b'
        );

        $this->actingAs($operator);

        return array_merge(
            $base,
            compact(
                'first',
                'second'
            )
        );
    }

    private function purchase(
        array $context,
        string $suffix
    ): array {
        $organization =
            $context['organization'];
        $admin = $context['admin'];
        $operator = $context['operator'];
        $supplier = $context['supplier'];

        $category = ProductCategory::query()
            ->firstOrCreate(
                ['slug' => 'p97h-tests'],
                [
                    'name' => 'Pruebas P9.7h',
                    'active' => true,
                ]
            );

        $product = CatalogProduct::query()
            ->create([
                'product_category_id' =>
                    $category->id,
                'sku' =>
                    'P97H-'.Str::upper(
                        Str::random(8)
                    ),
                'name' =>
                    'Producto P9.7h '.$suffix,
                'base_unit_code' => 'unit',
                'quantity_scale' => 0,
                'active' => true,
            ]);

        $location = InventoryLocation::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'name' =>
                    'Recepción P9.7h '
                    .Str::uuid(),
                'type' =>
                    InventoryLocationType::Receiving,
                'active' => true,
            ]);

        $orders = app(
            PurchaseOrderManager::class
        );

        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId: $supplier->id,
                currencyCode: 'ARS',
                idempotencyKey:
                    'p97h:order:'
                    .$suffix
                    .':'.Str::uuid(),
                lines: [
                    new PurchaseOrderLineData(
                        $product->id,
                        '2',
                        1000
                    ),
                ],
                expectedLogisticsCostMinor:
                    100
            ),
            $operator
        );

        $order = $orders->issue(
            $order,
            $operator
        );
        $order->load('lines');

        $receipt = app(
            PurchaseReceiptManager::class
        )->receive(
            new PurchaseReceiptData(
                purchaseOrderId: $order->id,
                receivedAt:
                    CarbonImmutable::parse(
                        '2026-08-17 10:00:00',
                        'America/Argentina/Buenos_Aires'
                    ),
                idempotencyKey:
                    'p97h:receipt:'
                    .$suffix
                    .':'.Str::uuid(),
                lines: [
                    new PurchaseReceiptLineData(
                        purchaseOrderLineId:
                            $order->lines
                                ->first()
                                ->id,
                        quantity: '2',
                        inventoryLocationId:
                            $location->id,
                        condition:
                            InventoryCondition::New,
                        actualUnitCostMinor:
                            1000
                    ),
                ],
                logisticsCostMinor: 100,
                documentReference:
                    'REM-P97H-'
                    .Str::upper(
                        Str::random(8)
                    )
            ),
            $operator
        );

        $obligation = app(
            PurchaseObligationManager::class
        )->recognize(
            new PurchaseObligationData(
                purchaseReceiptId:
                    $receipt->id,
                kind:
                    PurchaseObligationKind::Merchandise,
                beneficiaryBusinessPartyId:
                    null,
                paymentCondition:
                    PurchaseObligationCondition::OnReceipt
            ),
            $admin
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

        OrganizationMembership::query()
            ->create([
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

        app(CurrentOrganization::class)
            ->forget($user);

        return $user->refresh();
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
