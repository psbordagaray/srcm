<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CashSecurityDropReason;
use App\Enums\FinancialAccountType;
use App\Enums\UserRole;
use App\Models\CashMovement;
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

    public function test_operator_records_idempotent_security_drop_and_expected_cash_decreases(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member(
            $organization,
            UserRole::Operator
        );

        $this->actingAs($admin);

        $cash = app(FinancialAccountManager::class)->create(
            'Efectivo Caja 1',
            FinancialAccountType::CashBox,
            'ARS',
            $admin
        );
        $treasury = app(FinancialAccountManager::class)->create(
            'Caja fuerte',
            FinancialAccountType::CashReserve,
            'ARS',
            $admin
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
            'p4c:open:operator',
            $operator
        );

        $ledger = app(CashLedgerRecorder::class);

        $this->assertSame(
            5000000,
            $ledger->expectedAmountMinor(
                $session,
                $operator
            )
        );

        $movement = $ledger->recordSecurityDrop(
            $treasury,
            2000000,
            CashSecurityDropReason::ExcessCash,
            'Sobre 001',
            'p4c:security-drop:001',
            $operator
        );

        $retry = $ledger->recordSecurityDrop(
            $treasury,
            2000000,
            CashSecurityDropReason::ExcessCash,
            'Sobre 001',
            'p4c:security-drop:001',
            $operator
        );

        $this->assertSame($movement->id, $retry->id);
        $this->assertDatabaseCount('cash_movements', 1);

        $this->assertSame(
            CashMovementDirection::Out,
            $movement->direction
        );
        $this->assertSame(
            CashMovementType::SecurityDrop,
            $movement->type
        );
        $this->assertSame(
            CashSecurityDropReason::ExcessCash,
            $movement->reason_code
        );
        $this->assertNull($movement->commerce_payment_id);
        $this->assertSame($cash->id, $movement->financial_account_id);
        $this->assertSame(
            $treasury->id,
            $movement->destination_financial_account_id
        );
        $this->assertSame(2000000, $movement->amount_minor);
        $this->assertSame('Sobre 001', $movement->note);

        $this->assertSame(
            3000000,
            $ledger->expectedAmountMinor(
                $session,
                $operator
            )
        );

        $this->expectException(QueryException::class);

        DB::table('cash_movements')
            ->where('id', $movement->id)
            ->update(['amount_minor' => 1]);
    }

    public function test_security_drop_rejects_wrong_destination_currency_and_overdraw(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member(
            $organization,
            UserRole::Operator
        );

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
            'p4c:open:invalid',
            $operator
        );

        $ledger = app(CashLedgerRecorder::class);

        foreach ([
            [$bank, 100000, 'p4c:bad:bank'],
            [$usdTreasury, 100000, 'p4c:bad:currency'],
            [$arsTreasury, 1100000, 'p4c:bad:overdraw'],
        ] as [$destination, $amount, $key]) {
            try {
                $ledger->recordSecurityDrop(
                    $destination,
                    $amount,
                    CashSecurityDropReason::ScheduledDrop,
                    null,
                    $key,
                    $operator
                );
                $this->fail(
                    'El retiro inválido no debe registrarse.'
                );
            } catch (DomainException) {
                $this->assertDatabaseMissing(
                    'cash_movements',
                    ['idempotency_key' => $key]
                );
            }
        }
    }

    public function test_cash_register_screen_exposes_treasury_drop_without_closing_shift(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member(
            $organization,
            UserRole::Operator
        );

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
            ->assertSee('Efectivo esperado')
            ->assertSee('Retiro de seguridad')
            ->assertSee('Caja fuerte')
            ->assertSee('No cierra el turno');

        $this->post(
            route('cash-registers.security-drops'),
            [
                'destination_financial_account_id' => $treasury->id,
                'amount' => '20000,00',
                'reason_code' =>
                    CashSecurityDropReason::ScheduledDrop->value,
                'note' => 'Sobre caja',
                'idempotency_key' =>
                    'cash-ui:security-drop:'.
                    Str::uuid(),
            ]
        )
            ->assertRedirect(route('cash-registers.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('cash_movements', [
            'organization_id' => $organization->id,
            'cash_register_session_id' => 1,
            'cash_register_id' => $register->id,
            'financial_account_id' => $cash->id,
            'destination_financial_account_id' => $treasury->id,
            'direction' => CashMovementDirection::Out->value,
            'type' => CashMovementType::SecurityDrop->value,
            'amount_minor' => 2000000,
        ]);
    }

    public function test_supervisor_can_read_foreign_open_shift_expected_cash_but_operator_cannot(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member(
            $organization,
            UserRole::Operator
        );
        $otherOperator = $this->member(
            $organization,
            UserRole::Operator
        );

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
