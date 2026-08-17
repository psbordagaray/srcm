<?php

namespace Tests\Feature\Purchase;

use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Purchase\SupplierAdvanceExecutionData;
use App\Domain\Purchase\SupplierAdvanceManager;
use App\Domain\Purchase\SupplierAdvanceRequestData;
use App\Domain\Purchase\SupplierAdvanceRequestManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\FinancialAccountType;
use App\Enums\SupplierAdvanceDecisionType;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\CashMovement;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Supplier;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceDecision;
use App\Models\SupplierAdvanceRequest;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierAdvanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_and_approval_are_idempotent_segregated_and_non_monetary(): void
    {
        $context = $this->context(
            'authorization',
            FinancialAccountType::CashBox,
            10000
        );

        $requests = app(
            SupplierAdvanceRequestManager::class
        );

        $this->actingAs($context['operator']);

        $data = new SupplierAdvanceRequestData(
            supplierId: $context['supplier']->id,
            originFinancialAccountId:
                $context['account']->id,
            amountMinor: 2500,
            idempotencyKey:
                'p97f:request:authorization',
            requestNote:
                'Reserva de fondos al proveedor'
        );

        $request = $requests->request(
            $data,
            $context['operator']
        );
        $retry = $requests->request(
            $data,
            $context['operator']
        );

        $this->assertSame(
            $request->id,
            $retry->id
        );
        $this->assertDatabaseCount(
            'supplier_advance_requests',
            1
        );
        $this->assertDatabaseCount(
            'supplier_advance_decisions',
            0
        );
        $this->assertDatabaseCount(
            'supplier_advances',
            0
        );
        $this->assertDatabaseCount(
            'cash_movements',
            0
        );

        $this->assertDomainFailure(
            fn () => $requests->approve(
                $request,
                null,
                'p97f:approve:self',
                $context['operator']
            )
        );

        $this->actingAs($context['admin']);

        $decision = $requests->approve(
            $request,
            'Autorización separada',
            'p97f:approve:authorization',
            $context['admin']
        );
        $decisionRetry = $requests->approve(
            $request,
            'Autorización separada',
            'p97f:approve:authorization',
            $context['admin']
        );

        $this->assertSame(
            $decision->id,
            $decisionRetry->id
        );
        $this->assertSame(
            SupplierAdvanceDecisionType::Approved,
            $decision->decision
        );
        $this->assertDatabaseCount(
            'supplier_advance_decisions',
            1
        );
        $this->assertDatabaseCount(
            'supplier_advances',
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

    public function test_cash_advance_executes_once_and_reduces_expected_cash(): void
    {
        $context = $this->context(
            'cash',
            FinancialAccountType::CashBox,
            10000
        );

        $request = $this->approvedRequest(
            $context,
            2500,
            'cash'
        );

        $this->actingAs($context['operator']);

        $data = new SupplierAdvanceExecutionData(
            idempotencyKey:
                'p97f:execute:cash',
            executionReference:
                'REC-ADV-001',
            executionNote:
                'Anticipo efectivo controlado'
        );

        $manager = app(
            SupplierAdvanceManager::class
        );

        $advance = $manager->execute(
            $request,
            $data,
            $context['operator']
        );
        $retry = $manager->execute(
            $request,
            $data,
            $context['operator']
        );

        $this->assertSame(
            $advance->id,
            $retry->id
        );
        $this->assertSame(
            'cash',
            $advance->channel
        );
        $this->assertDatabaseCount(
            'supplier_advances',
            1
        );
        $this->assertDatabaseCount(
            'cash_movements',
            1
        );

        $movement = CashMovement::query()
            ->sole();

        $this->assertSame(
            CashMovementDirection::Out,
            $movement->direction
        );
        $this->assertSame(
            CashMovementType::SupplierAdvance,
            $movement->type
        );
        $this->assertSame(
            $advance->id,
            $movement->supplier_advance_id
        );
        $this->assertSame(
            2500,
            $movement->amount_minor
        );
        $this->assertSame(
            7500,
            app(
                CashLedgerRecorder::class
            )->expectedAmountMinor(
                $context['session'],
                $context['operator']
            )
        );
        $this->assertDatabaseCount(
            'purchase_obligations',
            0
        );
    }

    public function test_noncash_advance_requires_reference_without_fake_cash_or_external_movement(): void
    {
        $context = $this->context(
            'bank',
            FinancialAccountType::BankAccount,
            null
        );

        $request = $this->approvedRequest(
            $context,
            3200,
            'bank'
        );

        $this->actingAs($context['operator']);

        $this->assertDomainFailure(
            fn () => app(
                SupplierAdvanceManager::class
            )->execute(
                $request,
                new SupplierAdvanceExecutionData(
                    idempotencyKey:
                        'p97f:bank:no-ref'
                ),
                $context['operator']
            )
        );

        $advance = app(
            SupplierAdvanceManager::class
        )->execute(
            $request,
            new SupplierAdvanceExecutionData(
                idempotencyKey:
                    'p97f:bank:execute',
                executionReference:
                    'TRF-2026-0001',
                executionNote:
                    'Transferencia confirmada localmente'
            ),
            $context['operator']
        );

        $this->assertSame(
            'noncash',
            $advance->channel
        );
        $this->assertSame(
            'TRF-2026-0001',
            $advance->execution_reference
        );
        $this->assertNull(
            $advance->cash_register_session_id
        );
        $this->assertDatabaseCount(
            'cash_movements',
            0
        );
        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );
        $this->assertDatabaseCount(
            'supplier_credit_applications',
            0
        );
    }

    public function test_rejection_blocks_execution_and_approver_cannot_execute_approved_advance(): void
    {
        $context = $this->context(
            'segregation',
            FinancialAccountType::CashBox,
            8000
        );

        $requests = app(
            SupplierAdvanceRequestManager::class
        );

        $this->actingAs($context['operator']);

        $rejectedRequest = $requests->request(
            new SupplierAdvanceRequestData(
                supplierId:
                    $context['supplier']->id,
                originFinancialAccountId:
                    $context['account']->id,
                amountMinor: 1000,
                idempotencyKey:
                    'p97f:segregation:reject-request'
            ),
            $context['operator']
        );

        $this->actingAs($context['admin']);

        $decision = $requests->reject(
            $rejectedRequest,
            'No autorizado',
            'p97f:segregation:reject',
            $context['admin']
        );

        $this->assertSame(
            SupplierAdvanceDecisionType::Rejected,
            $decision->decision
        );

        $this->actingAs($context['operator']);

        $this->assertDomainFailure(
            fn () => app(
                SupplierAdvanceManager::class
            )->execute(
                $rejectedRequest,
                new SupplierAdvanceExecutionData(
                    idempotencyKey:
                        'p97f:segregation:rejected-exec'
                ),
                $context['operator']
            )
        );

        $approvedRequest = $this->approvedRequest(
            $context,
            1200,
            'segregation-approved'
        );

        $this->actingAs($context['admin']);

        $this->assertDomainFailure(
            fn () => app(
                SupplierAdvanceManager::class
            )->execute(
                $approvedRequest,
                new SupplierAdvanceExecutionData(
                    idempotencyKey:
                        'p97f:segregation:approver-exec'
                ),
                $context['admin']
            )
        );

        $this->assertDatabaseCount(
            'supplier_advances',
            0
        );
        $this->assertDatabaseCount(
            'cash_movements',
            0
        );
    }

    public function test_foreign_supplier_and_insufficient_cash_fail_closed(): void
    {
        $context = $this->context(
            'guards',
            FinancialAccountType::CashBox,
            1000
        );

        $other = $this->context(
            'foreign',
            FinancialAccountType::BankAccount,
            null
        );

        $this->actingAs($context['operator']);

        $this->assertDomainFailure(
            fn () => app(
                SupplierAdvanceRequestManager::class
            )->request(
                new SupplierAdvanceRequestData(
                    supplierId:
                        $other['supplier']->id,
                    originFinancialAccountId:
                        $context['account']->id,
                    amountMinor: 500,
                    idempotencyKey:
                        'p97f:foreign-supplier'
                ),
                $context['operator']
            )
        );

        $request = $this->approvedRequest(
            $context,
            1500,
            'insufficient'
        );

        $this->actingAs($context['operator']);

        $this->assertDomainFailure(
            fn () => app(
                SupplierAdvanceManager::class
            )->execute(
                $request,
                new SupplierAdvanceExecutionData(
                    idempotencyKey:
                        'p97f:insufficient:execute'
                ),
                $context['operator']
            )
        );

        $this->assertDatabaseCount(
            'supplier_advances',
            0
        );
        $this->assertDatabaseCount(
            'cash_movements',
            0
        );
        $this->assertSame(
            1000,
            app(
                CashLedgerRecorder::class
            )->expectedAmountMinor(
                $context['session'],
                $context['operator']
            )
        );
    }

    public function test_foundation_is_append_only_and_database_guards_source_links(): void
    {
        $context = $this->context(
            'immutable',
            FinancialAccountType::BankAccount,
            null
        );

        $request = $this->approvedRequest(
            $context,
            2100,
            'immutable'
        );

        $this->actingAs($context['operator']);

        $advance = app(
            SupplierAdvanceManager::class
        )->execute(
            $request,
            new SupplierAdvanceExecutionData(
                idempotencyKey:
                    'p97f:immutable:execute',
                executionReference:
                    'TRF-IMM-001'
            ),
            $context['operator']
        );

        $decision = SupplierAdvanceDecision::query()
            ->where(
                'supplier_advance_request_id',
                $request->id
            )
            ->sole();

        $this->assertQueryRejected(
            fn () => DB::table(
                'supplier_advance_requests'
            )
                ->where('id', $request->id)
                ->update(['amount_minor' => 1])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'supplier_advance_decisions'
            )
                ->where('id', $decision->id)
                ->delete()
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'supplier_advances'
            )
                ->where('id', $advance->id)
                ->update(['amount_minor' => 1])
        );

        $this->assertQueryRejected(
            fn () => DB::table(
                'supplier_advance_decisions'
            )->insert([
                'organization_id' =>
                    $context['organization']->id,
                'public_id' =>
                    (string) Str::uuid(),
                'supplier_advance_request_id' =>
                    $request->id,
                'decision' => 'approved',
                'decision_note' => null,
                'decided_by_user_id' =>
                    $context['operator']->id,
                'idempotency_key' =>
                    'p97f:forged:self-approval',
                'fingerprint' =>
                    str_repeat('a', 64),
                'decided_at' => now(),
                'created_at' => now(),
            ])
        );

        $this->assertTrue(
            Schema::hasColumn(
                'cash_movements',
                'supplier_advance_id'
            )
        );
        $this->assertSame(
            2100,
            SupplierAdvance::query()
                ->sole()
                ->amount_minor
        );
    }

    private function approvedRequest(
        array $context,
        int $amountMinor,
        string $suffix
    ): SupplierAdvanceRequest {
        $this->actingAs($context['operator']);

        $request = app(
            SupplierAdvanceRequestManager::class
        )->request(
            new SupplierAdvanceRequestData(
                supplierId:
                    $context['supplier']->id,
                originFinancialAccountId:
                    $context['account']->id,
                amountMinor: $amountMinor,
                idempotencyKey:
                    'p97f:request:'.$suffix,
                requestNote:
                    'Solicitud '.$suffix
            ),
            $context['operator']
        );

        $this->actingAs($context['admin']);

        app(
            SupplierAdvanceRequestManager::class
        )->approve(
            $request,
            'Aprobación '.$suffix,
            'p97f:approve:'.$suffix,
            $context['admin']
        );

        return $request->refresh();
    }

    private function context(
        string $suffix,
        FinancialAccountType $accountType,
        ?int $openingMinor
    ): array {
        $organization = Organization::query()
            ->create([
                'name' =>
                    'Org P9.7f '.$suffix,
                'slug' =>
                    'org-p97f-'.$suffix.'-'
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
                    'Proveedor P9.7f '.$suffix,
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
            'Cuenta P9.7f '.$suffix,
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
                'Caja P9.7f '.$suffix,
                $account,
                $admin
            );

            $this->actingAs($operator);

            $session = app(
                CashRegisterSessionManager::class
            )->open(
                $register,
                $openingMinor ?? 0,
                'p97f:session:'.$suffix,
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
