<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\CashSecurityDropManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CashSecurityDropReason;
use App\Enums\CashSecurityDropRequestStatus;
use App\Enums\FinancialAccountType;
use App\Enums\UserRole;
use App\Models\CashSecurityDropRequest;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashSecurityDropTreasuryTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_approval_and_execution_are_separate_and_only_execution_moves_cash(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            'Efectivo Caja 1',
            FinancialAccountType::CashBox,
            'ARS'
        );
        $treasury = $this->account(
            $admin,
            'Caja fuerte',
            FinancialAccountType::CashReserve,
            'ARS'
        );
        $register = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash,
            $admin
        );

        $this->actingAs($operator);

        $session = app(CashRegisterSessionManager::class)->open(
            $register,
            5000000,
            'p4c:auth:open',
            $operator
        );

        $manager = app(CashSecurityDropManager::class);
        $ledger = app(CashLedgerRecorder::class);

        $dropRequest = $manager->request(
            $treasury,
            2000000,
            CashSecurityDropReason::ExcessCash,
            'Sobre 001',
            'p4c:auth:request',
            $operator
        );

        $this->assertSame(
            CashSecurityDropRequestStatus::Pending,
            $dropRequest->status
        );
        $this->assertSame($operator->id, $dropRequest->requested_by_user_id);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(
            5000000,
            $ledger->expectedAmountMinor($session, $operator)
        );

        $this->actingAs($admin);

        $approved = $manager->approve(
            $dropRequest,
            'Autorizado por supervisión',
            'p4c:auth:approve',
            $admin
        );

        $this->assertSame(
            CashSecurityDropRequestStatus::Approved,
            $approved->status
        );
        $this->assertSame($admin->id, $approved->approved_by_user_id);
        $this->assertNotNull($approved->approval_fingerprint);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(
            5000000,
            $ledger->expectedAmountMinor($session, $admin)
        );

        $this->actingAs($operator);

        $movement = $manager->execute(
            $approved,
            'p4c:auth:execute',
            $operator
        );
        $retry = $manager->execute(
            $approved->refresh(),
            'p4c:auth:execute',
            $operator
        );

        $this->assertSame($movement->id, $retry->id);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame(CashMovementDirection::Out, $movement->direction);
        $this->assertSame(CashMovementType::SecurityDrop, $movement->type);
        $this->assertSame(
            $dropRequest->id,
            $movement->cash_security_drop_request_id
        );
        $this->assertSame($operator->id, $movement->recorded_by_user_id);
        $this->assertSame($treasury->id, $movement->destination_financial_account_id);
        $this->assertSame(2000000, $movement->amount_minor);

        $executed = $dropRequest->refresh();
        $this->assertSame(
            CashSecurityDropRequestStatus::Executed,
            $executed->status
        );
        $this->assertSame($operator->id, $executed->executed_by_user_id);
        $this->assertSame($admin->id, $executed->approved_by_user_id);
        $this->assertSame(
            3000000,
            $ledger->expectedAmountMinor($session, $operator)
        );
    }

    public function test_requester_cannot_self_approve_and_operator_cannot_approve(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $otherAdmin = $this->member($organization, UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            'Efectivo',
            FinancialAccountType::CashBox,
            'ARS'
        );
        $treasury = $this->account(
            $admin,
            'Tesorería',
            FinancialAccountType::CashReserve,
            'ARS'
        );
        $register = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash,
            $admin
        );

        app(CashRegisterSessionManager::class)->open(
            $register,
            1000000,
            'p4c:self:open',
            $admin
        );

        $manager = app(CashSecurityDropManager::class);
        $dropRequest = $manager->request(
            $treasury,
            100000,
            CashSecurityDropReason::ScheduledDrop,
            null,
            'p4c:self:request',
            $admin
        );

        try {
            $manager->approve(
                $dropRequest,
                null,
                'p4c:self:approve',
                $admin
            );
            $this->fail('El solicitante no debe autoautorizarse.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'no puede autorizarlo',
                $exception->getMessage()
            );
        }

        $this->actingAs($operator);

        try {
            $manager->approve(
                $dropRequest,
                null,
                'p4c:operator:approve',
                $operator
            );
            $this->fail('Un operador no debe autorizar retiros.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'No posee permiso',
                $exception->getMessage()
            );
        }

        $this->actingAs($otherAdmin);

        $approved = $manager->approve(
            $dropRequest,
            null,
            'p4c:other-admin:approve',
            $otherAdmin
        );

        $this->assertSame($otherAdmin->id, $approved->approved_by_user_id);
    }

    public function test_invalid_destination_overdraw_and_core_tampering_are_rejected(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            'Efectivo',
            FinancialAccountType::CashBox,
            'ARS'
        );
        $bank = $this->account(
            $admin,
            'Banco',
            FinancialAccountType::BankAccount,
            'ARS'
        );
        $usdTreasury = $this->account(
            $admin,
            'Caja fuerte USD',
            FinancialAccountType::CashReserve,
            'USD'
        );
        $arsTreasury = $this->account(
            $admin,
            'Caja fuerte ARS',
            FinancialAccountType::CashReserve,
            'ARS'
        );
        $register = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash,
            $admin
        );

        $this->actingAs($operator);

        app(CashRegisterSessionManager::class)->open(
            $register,
            1000000,
            'p4c:invalid:open',
            $operator
        );

        $manager = app(CashSecurityDropManager::class);

        foreach ([
            [$bank, 100000, 'p4c:invalid:bank'],
            [$usdTreasury, 100000, 'p4c:invalid:currency'],
            [$arsTreasury, 1100000, 'p4c:invalid:overdraw'],
        ] as [$destination, $amount, $key]) {
            try {
                $manager->request(
                    $destination,
                    $amount,
                    CashSecurityDropReason::ScheduledDrop,
                    null,
                    $key,
                    $operator
                );
                $this->fail('La solicitud inválida debe rechazarse.');
            } catch (DomainException) {
                $this->assertDatabaseMissing(
                    'cash_security_drop_requests',
                    ['request_idempotency_key' => $key]
                );
            }
        }

        $valid = $manager->request(
            $arsTreasury,
            100000,
            CashSecurityDropReason::ScheduledDrop,
            'Sobre válido',
            'p4c:valid:request',
            $operator
        );

        $this->expectException(QueryException::class);

        DB::table('cash_security_drop_requests')
            ->where('id', $valid->id)
            ->update(['amount_minor' => 1]);
    }

    public function test_database_rejects_direct_security_drop_without_approved_request(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            'Efectivo',
            FinancialAccountType::CashBox,
            'ARS'
        );
        $treasury = $this->account(
            $admin,
            'Tesorería',
            FinancialAccountType::CashReserve,
            'ARS'
        );
        $register = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash,
            $admin
        );

        $this->actingAs($operator);

        $session = app(CashRegisterSessionManager::class)->open(
            $register,
            1000000,
            'p4c:db:open',
            $operator
        );

        $this->expectException(QueryException::class);

        DB::table('cash_movements')->insert([
            'organization_id' => $organization->id,
            'public_id' => (string) Str::uuid(),
            'cash_register_session_id' => $session->id,
            'cash_register_id' => $register->id,
            'financial_account_id' => $cash->id,
            'destination_financial_account_id' => $treasury->id,
            'cash_security_drop_request_id' => null,
            'commerce_payment_id' => null,
            'direction' => CashMovementDirection::Out->value,
            'type' => CashMovementType::SecurityDrop->value,
            'reason_code' => CashSecurityDropReason::Other->value,
            'note' => 'Intento directo',
            'amount_minor' => 100000,
            'currency_code' => 'ARS',
            'idempotency_key' => 'p4c:direct:forbidden',
            'fingerprint' => str_repeat('a', 64),
            'recorded_by_user_id' => $operator->id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function test_unresolved_security_drop_blocks_close_until_cancelled(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            'Efectivo',
            FinancialAccountType::CashBox,
            'ARS'
        );
        $treasury = $this->account(
            $admin,
            'Tesorería',
            FinancialAccountType::CashReserve,
            'ARS'
        );
        $register = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash,
            $admin
        );

        $this->actingAs($operator);

        $sessionManager = app(CashRegisterSessionManager::class);
        $sessionManager->open(
            $register,
            1000000,
            'p4c:close-block:open',
            $operator
        );

        $dropManager = app(CashSecurityDropManager::class);
        $dropRequest = $dropManager->request(
            $treasury,
            100000,
            CashSecurityDropReason::ExcessCash,
            null,
            'p4c:close-block:request',
            $operator
        );

        try {
            $sessionManager->closeCurrent(
                1000000,
                null,
                null,
                'p4c:close-block:pending',
                $operator
            );
            $this->fail('No debe cerrar con solicitud pendiente.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'retiro de seguridad pendiente',
                $exception->getMessage()
            );
        }

        $this->actingAs($admin);
        $dropManager->approve(
            $dropRequest,
            null,
            'p4c:close-block:approve',
            $admin
        );

        $this->actingAs($operator);

        try {
            $sessionManager->closeCurrent(
                1000000,
                null,
                null,
                'p4c:close-block:approved',
                $operator
            );
            $this->fail('No debe cerrar con retiro autorizado pendiente.');
        } catch (DomainException) {
            $this->assertDatabaseCount('cash_register_closures', 0);
        }

        $dropManager->cancel(
            $dropRequest->refresh(),
            'Se decide cerrar la caja sin retirar.',
            'p4c:close-block:cancel',
            $operator
        );

        $closure = $sessionManager->closeCurrent(
            1000000,
            null,
            null,
            'p4c:close-block:final',
            $operator
        );

        $this->assertSame(0, $closure->difference_minor);
    }

    public function test_http_surface_uses_exclusive_operation_selector_and_authorized_flow(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            'Efectivo',
            FinancialAccountType::CashBox,
            'ARS'
        );
        $treasury = $this->account(
            $admin,
            'Caja fuerte',
            FinancialAccountType::CashReserve,
            'ARS'
        );
        $register = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash,
            $admin
        );

        $this->actingAs($operator);

        app(CashRegisterSessionManager::class)->open(
            $register,
            5000000,
            'p4c:http:open',
            $operator
        );

        $this->get(route('cash-registers.index'))
            ->assertOk()
            ->assertSee('Operaciones del turno')
            ->assertSee('Elegí qué necesitás hacer')
            ->assertSee('Solicitar autorización')
            ->assertSee('Arqueo y cierre')
            ->assertDontSee('Registrar retiro de seguridad');

        $this->post(
            route('cash-registers.security-drop-requests.store'),
            [
                'destination_financial_account_id' => $treasury->id,
                'amount' => '20000,00',
                'reason_code' =>
                    CashSecurityDropReason::ScheduledDrop->value,
                'note' => 'Sobre caja',
                'idempotency_key' =>
                    'cash-ui:security-drop-request:'.Str::uuid(),
            ]
        )
            ->assertRedirect(route('cash-registers.index'))
            ->assertSessionHas('success');

        $dropRequest = CashSecurityDropRequest::query()->sole();
        $this->assertSame(
            CashSecurityDropRequestStatus::Pending,
            $dropRequest->status
        );
        $this->assertDatabaseCount('cash_movements', 0);

        $this->actingAs($admin);

        $this->get(route('cash-registers.index'))
            ->assertOk()
            ->assertSee('Autorizaciones de retiros pendientes')
            ->assertSee($operator->name);

        $this->post(
            route(
                'cash-registers.security-drop-requests.approve',
                $dropRequest
            ),
            [
                'approval_note' => 'OK supervisor',
                'idempotency_key' =>
                    'cash-ui:security-drop-approve:'.Str::uuid(),
            ]
        )
            ->assertRedirect(route('cash-registers.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('cash_movements', 0);

        $this->actingAs($operator);

        $this->get(route('cash-registers.index'))
            ->assertOk()
            ->assertSee('Autorizado · pendiente de ejecución')
            ->assertSee('Ejecutar retiro autorizado');

        $this->post(
            route(
                'cash-registers.security-drop-requests.execute',
                $dropRequest
            ),
            [
                'confirm_execute' => '1',
                'idempotency_key' =>
                    'cash-ui:security-drop-execute:'.Str::uuid(),
            ]
        )
            ->assertRedirect(route('cash-registers.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('cash_movements', [
            'organization_id' => $organization->id,
            'cash_register_id' => $register->id,
            'financial_account_id' => $cash->id,
            'destination_financial_account_id' => $treasury->id,
            'cash_security_drop_request_id' => $dropRequest->id,
            'direction' => CashMovementDirection::Out->value,
            'type' => CashMovementType::SecurityDrop->value,
            'amount_minor' => 2000000,
        ]);
    }

    public function test_supervisor_can_read_foreign_open_shift_expected_cash_but_operator_cannot(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);
        $otherOperator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            'Efectivo',
            FinancialAccountType::CashBox,
            'ARS'
        );
        $register = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash,
            $admin
        );

        $this->actingAs($operator);

        $session = app(CashRegisterSessionManager::class)->open(
            $register,
            1000000,
            'p4c:supervisor:open',
            $operator
        );

        $this->actingAs($admin);

        $this->assertSame(
            1000000,
            app(CashLedgerRecorder::class)
                ->expectedAmountMinor($session, $admin)
        );

        $this->actingAs($otherOperator);

        $this->expectException(DomainException::class);

        app(CashLedgerRecorder::class)
            ->expectedAmountMinor($session, $otherOperator);
    }

    private function account(
        User $admin,
        string $name,
        FinancialAccountType $type,
        string $currency
    ): FinancialAccount {
        return app(FinancialAccountManager::class)->create(
            $name,
            $type,
            $currency,
            $admin
        );
    }

    /** @return array{Organization, User} */
    private function actor(UserRole $role): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org P4C '.$suffix,
            'slug' => 'org-p4c-'.$suffix,
            'active' => true,
        ]);

        $user = $this->member($organization, $role);

        return [$organization, $user];
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
