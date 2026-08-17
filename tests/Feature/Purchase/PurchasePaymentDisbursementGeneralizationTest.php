<?php

namespace Tests\Feature\Purchase;

use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Purchase\PurchaseObligationBalanceReader;
use App\Domain\Purchase\PurchaseObligationData;
use App\Domain\Purchase\PurchaseObligationManager;
use App\Domain\Purchase\PurchaseOrderDraftData;
use App\Domain\Purchase\PurchaseOrderLineData;
use App\Domain\Purchase\PurchaseOrderManager;
use App\Domain\Purchase\PurchasePaymentDisbursementManager;
use App\Domain\Purchase\PurchasePaymentExecutionManager;
use App\Domain\Purchase\PurchasePaymentGroupItemData;
use App\Domain\Purchase\PurchasePaymentGroupRequestData;
use App\Domain\Purchase\PurchasePaymentGroupRequestManager;
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
use App\Enums\PurchasePaymentDisbursementChannel;
use App\Enums\PurchasePaymentRequestStatus;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\CashMovement;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\PurchasePaymentDisbursement;
use App\Models\PurchasePaymentDisbursementAllocation;
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

class PurchasePaymentDisbursementGeneralizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_noncash_executes_once_without_fake_cash_or_external_movement(): void
    {
        $context = $this->individualContext(
            'individual-bank',
            FinancialAccountType::BankAccount,
            3000,
            1200
        );

        $this->actingAs($context['operator']);

        $manager = app(
            PurchasePaymentDisbursementManager::class
        );

        $disbursement = $manager->executeIndividual(
            $context['request'],
            'TRF-P97I-001',
            'Transferencia bancaria',
            'p97i:individual:bank',
            $context['operator']
        );

        $retry = $manager->executeIndividual(
            $context['request']->refresh(),
            'TRF-P97I-001',
            'Transferencia bancaria',
            'p97i:individual:bank',
            $context['operator']
        );

        $this->assertSame(
            $disbursement->id,
            $retry->id
        );
        $this->assertSame(
            PurchasePaymentDisbursementChannel::NonCash,
            $disbursement->channel
        );
        $this->assertSame(
            PurchasePaymentRequestStatus::Executed,
            $context['request']->refresh()->status
        );
        $this->assertDatabaseCount(
            'purchase_payment_disbursements',
            1
        );
        $this->assertDatabaseCount(
            'purchase_payment_disbursement_allocations',
            1
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

        $balance = app(
            PurchaseObligationBalanceReader::class
        )->read($context['obligation']);

        $this->assertSame(
            1200,
            $balance[
                'disbursement_execution_minor'
            ]
        );
        $this->assertSame(
            1800,
            $balance['remaining_minor']
        );
    }

    public function test_individual_cash_creates_one_disbursement_cash_movement_and_reduces_expected_cash(): void
    {
        $context = $this->individualContext(
            'individual-cash',
            FinancialAccountType::CashBox,
            2500,
            1000,
            5000
        );

        $this->actingAs($context['operator']);

        $before = app(
            CashLedgerRecorder::class
        )->expectedAmountMinor(
            $context['session'],
            $context['operator']
        );

        $disbursement = app(
            PurchasePaymentDisbursementManager::class
        )->executeIndividual(
            $context['request'],
            null,
            'Pago cash canónico',
            'p97i:individual:cash',
            $context['operator']
        );

        $movement = CashMovement::query()->sole();

        $this->assertSame(
            PurchasePaymentDisbursementChannel::Cash,
            $disbursement->channel
        );
        $this->assertSame(
            CashMovementDirection::Out,
            $movement->direction
        );
        $this->assertSame(
            CashMovementType::PurchasePaymentDisbursement,
            $movement->type
        );
        $this->assertSame(
            $disbursement->id,
            $movement
                ->purchase_payment_disbursement_id
        );
        $this->assertNull(
            $movement->purchase_payment_execution_id
        );
        $this->assertSame(
            $before - 1000,
            app(CashLedgerRecorder::class)
                ->expectedAmountMinor(
                    $context['session'],
                    $context['operator']
                )
        );
        $this->assertDatabaseCount(
            'purchase_payment_disbursements',
            1
        );
        $this->assertDatabaseCount(
            'purchase_payment_disbursement_allocations',
            1
        );
        $this->assertDatabaseCount(
            'cash_movements',
            1
        );
    }

    public function test_group_noncash_executes_atomically_as_one_disbursement_with_two_allocations(): void
    {
        $context = $this->groupContext(
            'group-bank',
            FinancialAccountType::BankAccount
        );

        $this->actingAs($context['operator']);

        $disbursement = app(
            PurchasePaymentDisbursementManager::class
        )->executeGroup(
            $context['group'],
            'TRF-P97I-GROUP',
            'Transferencia agrupada',
            'p97i:group:bank',
            $context['operator']
        );

        $this->assertSame(
            PurchasePaymentRequestStatus::Executed,
            $context['group']->refresh()->status
        );
        $this->assertSame(
            1200,
            $disbursement->amount_minor
        );
        $this->assertCount(
            2,
            $disbursement->allocations
        );
        $this->assertSame(
            1200,
            (int) $disbursement->allocations
                ->sum('amount_minor')
        );
        $this->assertDatabaseCount(
            'purchase_payment_disbursements',
            1
        );
        $this->assertDatabaseCount(
            'purchase_payment_disbursement_allocations',
            2
        );
        $this->assertDatabaseCount(
            'cash_movements',
            0
        );
        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );

        $this->assertSame(
            1500,
            app(
                PurchaseObligationBalanceReader::class
            )->read(
                $context['first']['obligation']
            )['remaining_minor']
        );
        $this->assertSame(
            1300,
            app(
                PurchaseObligationBalanceReader::class
            )->read(
                $context['second']['obligation']
            )['remaining_minor']
        );
    }

    public function test_group_cash_creates_exactly_one_cash_movement_for_total_disbursement(): void
    {
        $context = $this->groupContext(
            'group-cash',
            FinancialAccountType::CashBox,
            6000
        );

        $this->actingAs($context['operator']);

        $before = app(
            CashLedgerRecorder::class
        )->expectedAmountMinor(
            $context['session'],
            $context['operator']
        );

        $disbursement = app(
            PurchasePaymentDisbursementManager::class
        )->executeGroup(
            $context['group'],
            null,
            'Pago agrupado cash',
            'p97i:group:cash',
            $context['operator']
        );

        $this->assertDatabaseCount(
            'purchase_payment_disbursements',
            1
        );
        $this->assertDatabaseCount(
            'purchase_payment_disbursement_allocations',
            2
        );
        $this->assertDatabaseCount(
            'cash_movements',
            1
        );

        $movement = CashMovement::query()->sole();

        $this->assertSame(
            1200,
            $movement->amount_minor
        );
        $this->assertSame(
            $disbursement->id,
            $movement
                ->purchase_payment_disbursement_id
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

    public function test_segregation_noncash_reference_and_cash_reserve_fail_closed(): void
    {
        $bank = $this->individualContext(
            'guards-bank',
            FinancialAccountType::BankAccount,
            2000,
            800
        );

        $this->actingAs($bank['admin']);

        $this->assertDomainFailure(
            fn () => app(
                PurchasePaymentDisbursementManager::class
            )->executeIndividual(
                $bank['request'],
                'TRF-SELF',
                null,
                'p97i:guards:self',
                $bank['admin']
            )
        );

        $this->actingAs($bank['operator']);

        $this->assertDomainFailure(
            fn () => app(
                PurchasePaymentDisbursementManager::class
            )->executeIndividual(
                $bank['request'],
                null,
                null,
                'p97i:guards:no-ref',
                $bank['operator']
            )
        );

        $reserve = $this->individualContext(
            'guards-reserve',
            FinancialAccountType::CashReserve,
            2000,
            800
        );

        $this->actingAs($reserve['operator']);

        $this->assertDomainFailure(
            fn () => app(
                PurchasePaymentDisbursementManager::class
            )->executeIndividual(
                $reserve['request'],
                'TESORERIA',
                null,
                'p97i:guards:reserve',
                $reserve['operator']
            )
        );

        $this->assertDatabaseCount(
            'purchase_payment_disbursements',
            0
        );
    }

    public function test_legacy_and_canonical_executions_converge_in_one_obligation_balance(): void
    {
        $context = $this->individualContext(
            'converge',
            FinancialAccountType::BankAccount,
            3000,
            1000
        );

        $this->actingAs($context['operator']);

        app(
            PurchasePaymentDisbursementManager::class
        )->executeIndividual(
            $context['request'],
            'TRF-CONVERGE',
            null,
            'p97i:converge:new',
            $context['operator']
        );

        $this->actingAs($context['admin']);

        $cash = app(
            FinancialAccountManager::class
        )->create(
            'Caja converge',
            FinancialAccountType::CashBox,
            'ARS',
            $context['admin']
        );

        $register = app(
            CashRegisterManager::class
        )->create(
            'Caja converge',
            $cash,
            $context['admin']
        );

        $this->actingAs($context['operator']);

        $session = app(
            CashRegisterSessionManager::class
        )->open(
            $register,
            5000,
            'p97i:converge:session',
            $context['operator']
        );

        $second = app(
            PurchasePaymentRequestManager::class
        )->request(
            new PurchasePaymentRequestData(
                purchaseObligationId:
                    $context['obligation']->id,
                originFinancialAccountId:
                    $cash->id,
                amountMinor: 1000,
                requestNote: null,
                idempotencyKey:
                    'p97i:converge:request'
            ),
            $context['operator']
        );

        $this->actingAs($context['admin']);

        $second = app(
            PurchasePaymentRequestManager::class
        )->approve(
            $second,
            'Legacy compatibility',
            'p97i:converge:approve',
            $context['admin']
        );

        $this->actingAs($context['operator']);

        app(
            PurchasePaymentExecutionManager::class
        )->executeCash(
            $second,
            'LEGACY-001',
            null,
            'p97i:converge:legacy',
            $context['operator']
        );

        $balance = app(
            PurchaseObligationBalanceReader::class
        )->read($context['obligation']);

        $this->assertSame(
            1000,
            $balance['legacy_execution_minor']
        );
        $this->assertSame(
            1000,
            $balance[
                'disbursement_execution_minor'
            ]
        );
        $this->assertSame(
            2000,
            $balance['executed_minor']
        );
        $this->assertSame(
            1000,
            $balance['remaining_minor']
        );
        $this->assertSame(
            4000,
            app(CashLedgerRecorder::class)
                ->expectedAmountMinor(
                    $session,
                    $context['operator']
                )
        );
    }

    public function test_disbursement_and_allocations_are_append_only_and_database_guarded(): void
    {
        $context = $this->individualContext(
            'immutable',
            FinancialAccountType::BankAccount,
            2000,
            900
        );

        $this->actingAs($context['operator']);

        $disbursement = app(
            PurchasePaymentDisbursementManager::class
        )->executeIndividual(
            $context['request'],
            'TRF-IMMUTABLE',
            null,
            'p97i:immutable',
            $context['operator']
        );

        $allocation =
            PurchasePaymentDisbursementAllocation::query()
                ->sole();

        $this->assertDomainFailure(
            function () use ($disbursement): void {
                $disbursement->amount_minor = 1;
                $disbursement->save();
            }
        );

        $this->assertDomainFailure(
            fn () => $allocation->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'purchase_payment_disbursements'
            )
                ->where('id', $disbursement->id)
                ->update([
                    'amount_minor' => 1,
                ])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'purchase_payment_disbursement_allocations'
            )
                ->where('id', $allocation->id)
                ->update([
                    'amount_minor' => 1,
                ])
        );

        $this->assertTrue(
            Schema::hasColumn(
                'cash_movements',
                'purchase_payment_disbursement_id'
            )
        );
        $this->assertSame(
            900,
            PurchasePaymentDisbursement::query()
                ->sole()
                ->amount_minor
        );
    }

    private function individualContext(
        string $suffix,
        FinancialAccountType $accountType,
        int $obligationMinor,
        int $requestedMinor,
        int $openingMinor = 0
    ): array {
        $base = $this->baseContext(
            $suffix,
            $accountType,
            $openingMinor
        );

        $purchase = $this->purchase(
            $base,
            $suffix,
            $obligationMinor
        );

        $this->actingAs($base['operator']);

        $request = app(
            PurchasePaymentRequestManager::class
        )->request(
            new PurchasePaymentRequestData(
                purchaseObligationId:
                    $purchase['obligation']->id,
                originFinancialAccountId:
                    $base['account']->id,
                amountMinor:
                    $requestedMinor,
                requestNote:
                    'Solicitud P9.7i '.$suffix,
                idempotencyKey:
                    'p97i:request:'.$suffix
            ),
            $base['operator']
        );

        $this->actingAs($base['admin']);

        $request = app(
            PurchasePaymentRequestManager::class
        )->approve(
            $request,
            'Aprobación P9.7i '.$suffix,
            'p97i:approve:'.$suffix,
            $base['admin']
        );

        return array_merge(
            $base,
            $purchase,
            compact('request')
        );
    }

    private function groupContext(
        string $suffix,
        FinancialAccountType $accountType,
        int $openingMinor = 0
    ): array {
        $base = $this->baseContext(
            $suffix,
            $accountType,
            $openingMinor
        );

        $first = $this->purchase(
            $base,
            $suffix.'-a',
            2000
        );

        $second = $this->purchase(
            $base,
            $suffix.'-b',
            2000
        );

        $this->actingAs($base['operator']);

        $group = app(
            PurchasePaymentGroupRequestManager::class
        )->request(
            new PurchasePaymentGroupRequestData(
                originFinancialAccountId:
                    $base['account']->id,
                items: [
                    new PurchasePaymentGroupItemData(
                        purchaseObligationId:
                            $first['obligation']->id,
                        amountMinor: 500
                    ),
                    new PurchasePaymentGroupItemData(
                        purchaseObligationId:
                            $second['obligation']->id,
                        amountMinor: 700
                    ),
                ],
                idempotencyKey:
                    'p97i:group:request:'.$suffix,
                requestNote:
                    'Grupo P9.7i '.$suffix
            ),
            $base['operator']
        );

        $this->actingAs($base['admin']);

        $group = app(
            PurchasePaymentGroupRequestManager::class
        )->approve(
            $group,
            'Aprobación grupo P9.7i',
            'p97i:group:approve:'.$suffix,
            $base['admin']
        );

        return array_merge(
            $base,
            compact(
                'first',
                'second',
                'group'
            )
        );
    }

    private function baseContext(
        string $suffix,
        FinancialAccountType $accountType,
        int $openingMinor
    ): array {
        $organization = Organization::query()
            ->create([
                'name' =>
                    'Org P9.7i '.$suffix,
                'slug' =>
                    'org-p97i-'.$suffix.'-'
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
                    'Proveedor P9.7i '.$suffix,
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
            'Cuenta P9.7i '.$suffix,
            $accountType,
            'ARS',
            $admin
        );

        $register = null;
        $session = null;

        if (
            $accountType
                === FinancialAccountType::CashBox
        ) {
            $register = app(
                CashRegisterManager::class
            )->create(
                'Caja P9.7i '.$suffix,
                $account,
                $admin
            );

            $this->actingAs($operator);

            $session = app(
                CashRegisterSessionManager::class
            )->open(
                $register,
                $openingMinor,
                'p97i:session:'.$suffix,
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
        $category = ProductCategory::query()
            ->firstOrCreate(
                ['slug' => 'p97i-tests'],
                [
                    'name' => 'Pruebas P9.7i',
                    'active' => true,
                ]
            );

        $product = CatalogProduct::query()
            ->create([
                'product_category_id' =>
                    $category->id,
                'sku' =>
                    'P97I-'.Str::upper(
                        Str::random(10)
                    ),
                'name' =>
                    'Producto P9.7i '.$suffix,
                'base_unit_code' => 'unit',
                'quantity_scale' => 0,
                'active' => true,
            ]);

        $location = InventoryLocation::query()
            ->create([
                'organization_id' =>
                    $context['organization']->id,
                'name' =>
                    'Recepción P9.7i '
                    .Str::uuid(),
                'type' =>
                    InventoryLocationType::Receiving,
                'active' => true,
            ]);

        $this->actingAs($context['operator']);

        $orders = app(
            PurchaseOrderManager::class
        );

        $order = $orders->draft(
            new PurchaseOrderDraftData(
                supplierId:
                    $context['supplier']->id,
                currencyCode: 'ARS',
                idempotencyKey:
                    'p97i:order:'
                    .$suffix
                    .':'.Str::uuid(),
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

        $order = $orders->issue(
            $order,
            $context['operator']
        );
        $order->load('lines');

        $receipt = app(
            PurchaseReceiptManager::class
        )->receive(
            new PurchaseReceiptData(
                purchaseOrderId: $order->id,
                receivedAt:
                    CarbonImmutable::parse(
                        '2026-08-17 15:00:00',
                        'America/Argentina/Buenos_Aires'
                    ),
                idempotencyKey:
                    'p97i:receipt:'
                    .$suffix
                    .':'.Str::uuid(),
                lines: [
                    new PurchaseReceiptLineData(
                        purchaseOrderLineId:
                            $order->lines
                                ->first()
                                ->id,
                        quantity: '1',
                        inventoryLocationId:
                            $location->id,
                        condition:
                            InventoryCondition::New,
                        actualUnitCostMinor:
                            $amountMinor
                    ),
                ],
                logisticsCostMinor: 0,
                documentReference:
                    'REM-P97I-'
                    .Str::upper(
                        Str::random(8)
                    )
            ),
            $context['operator']
        );

        $this->actingAs($context['admin']);

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
