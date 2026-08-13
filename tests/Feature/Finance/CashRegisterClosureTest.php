<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashCountDifferenceReason;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\FinancialAccountType;
use App\Enums\UserRole;
use App\Models\CashRegisterClosure;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashRegisterClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_closes_exact_session_idempotently_and_register_is_reusable(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = app(FinancialAccountManager::class)->create(
            'Efectivo Caja 1',
            FinancialAccountType::CashBox,
            'ARS',
            $admin
        );
        $register = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash,
            $admin
        );

        $this->actingAs($operator);

        $manager = app(CashRegisterSessionManager::class);

        $session = $manager->open(
            $register,
            5000000,
            'p4d:open:exact',
            $operator
        );

        $closure = $manager->closeCurrent(
            5000000,
            null,
            'Cierre exacto',
            'p4d:close:exact',
            $operator
        );

        $retry = $manager->closeCurrent(
            5000000,
            null,
            'Cierre exacto',
            'p4d:close:exact',
            $operator
        );

        $this->assertSame($closure->id, $retry->id);
        $this->assertSame($session->id, $closure->cash_register_session_id);
        $this->assertSame(5000000, $closure->expected_amount_minor);
        $this->assertSame(5000000, $closure->counted_amount_minor);
        $this->assertSame(0, $closure->difference_minor);
        $this->assertNull($closure->difference_reason);
        $this->assertSame('Cierre exacto', $closure->note);
        $this->assertSame(
            CashRegisterSessionStatus::Closed,
            $session->refresh()->status
        );
        $this->assertNull($manager->currentFor($operator));
        $this->assertDatabaseCount('cash_register_closures', 1);

        $next = $manager->open(
            $register,
            0,
            'p4d:open:next',
            $operator
        );

        $this->assertSame(
            CashRegisterSessionStatus::Open,
            $next->status
        );
        $this->assertNotSame($session->id, $next->id);
    }

    public function test_difference_requires_reason_and_note_and_never_creates_implicit_movement(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = app(FinancialAccountManager::class)->create(
            'Efectivo',
            FinancialAccountType::CashBox,
            'ARS',
            $admin
        );
        $register = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash,
            $admin
        );

        $this->actingAs($operator);

        $manager = app(CashRegisterSessionManager::class);

        $session = $manager->open(
            $register,
            5000000,
            'p4d:open:difference',
            $operator
        );

        foreach ([
            [null, null, 'p4d:close:no-reason'],
            [
                CashCountDifferenceReason::Unexplained,
                null,
                'p4d:close:no-note',
            ],
        ] as [$reason, $note, $key]) {
            try {
                $manager->closeCurrent(
                    4950000,
                    $reason,
                    $note,
                    $key,
                    $operator
                );
                $this->fail(
                    'Una diferencia sin evidencia suficiente debe rechazarse.'
                );
            } catch (DomainException) {
                $this->assertSame(
                    CashRegisterSessionStatus::Open,
                    $session->refresh()->status
                );
            }
        }

        $closure = $manager->closeCurrent(
            4950000,
            CashCountDifferenceReason::Unexplained,
            'Faltante confirmado luego del reconteo.',
            'p4d:close:difference',
            $operator
        );

        $this->assertSame(-50000, $closure->difference_minor);
        $this->assertSame(
            CashCountDifferenceReason::Unexplained,
            $closure->difference_reason
        );
        $this->assertDatabaseCount('cash_movements', 0);

        $this->expectException(QueryException::class);

        DB::table('cash_register_closures')
            ->where('id', $closure->id)
            ->update(['difference_minor' => 0]);
    }

    public function test_web_close_is_explicit_server_timed_and_visible_in_history(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = app(FinancialAccountManager::class)->create(
            'Efectivo',
            FinancialAccountType::CashBox,
            'ARS',
            $admin
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
            'p4d:http:open',
            $operator
        );

        $this->get(route('cash-registers.index'))
            ->assertOk()
            ->assertSee('Arqueo y cierre')
            ->assertSee('Efectivo esperado')
            ->assertSee('Confirmo que conté físicamente');

        $this->post(
            route('cash-registers.close'),
            [
                'counted_amount' => '50000,00',
                'difference_reason' => '',
                'closing_note' => 'Cierre web',
                'confirm_close' => '1',
                'idempotency_key' =>
                    'cash-ui:close:'.Str::uuid(),
                'closed_at' => '2020-01-01 00:00:00',
            ]
        )
            ->assertSessionHasErrors('closed_at');

        $this->assertDatabaseCount('cash_register_closures', 0);

        $this->post(
            route('cash-registers.close'),
            [
                'counted_amount' => '50000,00',
                'difference_reason' => '',
                'closing_note' => 'Cierre web',
                'confirm_close' => '1',
                'idempotency_key' =>
                    'cash-ui:close:'.Str::uuid(),
            ]
        )
            ->assertRedirect(route('cash-registers.index'))
            ->assertSessionHas('success');

        $closure = CashRegisterClosure::query()->sole();

        $this->assertNotNull($closure->closed_at);
        $this->assertSame(0, $closure->difference_minor);

        $this->get(route('cash-registers.index'))
            ->assertOk()
            ->assertSee('No tenés un turno de caja abierto.')
            ->assertSee('Últimos arqueos y cierres')
            ->assertSee('Cierre web')
            ->assertSee('Diferencia');
    }

    public function test_database_requires_closure_before_closed_status(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = app(FinancialAccountManager::class)->create(
            'Efectivo',
            FinancialAccountType::CashBox,
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
            1000000,
            'p4d:db:open',
            $operator
        );

        DB::table('cash_register_sessions')
            ->where('id', $session->id)
            ->update(['status' => 'closing_requested']);

        $this->expectException(QueryException::class);

        DB::table('cash_register_sessions')
            ->where('id', $session->id)
            ->update(['status' => 'closed']);
    }

    public function test_closed_session_rejects_new_cash_movement_at_database_boundary(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = app(FinancialAccountManager::class)->create(
            'Efectivo',
            FinancialAccountType::CashBox,
            'ARS',
            $admin
        );
        $register = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash,
            $admin
        );

        $this->actingAs($operator);

        $manager = app(CashRegisterSessionManager::class);

        $session = $manager->open(
            $register,
            1000000,
            'p4d:movement:open',
            $operator
        );

        $manager->closeCurrent(
            1000000,
            null,
            null,
            'p4d:movement:close',
            $operator
        );

        $this->expectException(QueryException::class);

        DB::table('cash_movements')->insert([
            'organization_id' => $organization->id,
            'public_id' => (string) Str::uuid(),
            'cash_register_session_id' => $session->id,
            'cash_register_id' => $register->id,
            'financial_account_id' => $cash->id,
            'destination_financial_account_id' => null,
            'commerce_payment_id' => null,
            'direction' => 'out',
            'type' => 'security_drop',
            'reason_code' => 'other',
            'note' => 'No debe entrar',
            'amount_minor' => 1,
            'currency_code' => 'ARS',
            'idempotency_key' => 'p4d:invalid:movement',
            'fingerprint' => str_repeat('a', 64),
            'recorded_by_user_id' => $operator->id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function actor(UserRole $role): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org P4D '.$suffix,
            'slug' => 'org-p4d-'.$suffix,
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
