<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\FinancialAccountType;
use App\Enums\UserRole;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashRegisterSessionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_and_cash_permissions_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('cash_registers', [
            'organization_id',
            'public_id',
            'financial_account_id',
            'name',
            'normalized_name',
            'active',
            'created_by_user_id',
            'updated_by_user_id',
        ]));

        $this->assertTrue(Schema::hasColumns(
            'cash_register_sessions',
            [
                'organization_id',
                'public_id',
                'cash_register_id',
                'opened_by_user_id',
                'status',
                'currency_code',
                'opening_amount_minor',
                'idempotency_key',
                'fingerprint',
                'opened_at',
            ]
        ));

        $this->assertTrue(UserRole::Admin->canManageCashRegisters());
        $this->assertFalse(UserRole::Operator->canManageCashRegisters());
        $this->assertFalse(UserRole::Viewer->canManageCashRegisters());

        $this->assertTrue(UserRole::Admin->canOperateCashRegister());
        $this->assertTrue(UserRole::Operator->canOperateCashRegister());
        $this->assertFalse(UserRole::Viewer->canOperateCashRegister());

        $this->assertTrue(UserRole::Admin->canSuperviseCashRegisters());
        $this->assertFalse(UserRole::Operator->canSuperviseCashRegisters());
    }

    public function test_admin_creates_private_register_only_from_cash_account(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        [$otherOrganization, $otherAdmin] = $this->actor(UserRole::Admin);

        $this->actingAs($admin);

        $cash = app(FinancialAccountManager::class)->create(
            'Efectivo Caja 3',
            FinancialAccountType::CashBox,
            'ARS',
            $admin
        );

        $register = app(CashRegisterManager::class)->create(
            'Caja 3',
            $cash,
            $admin
        );

        $this->assertSame($organization->id, $register->organization_id);
        $this->assertSame($cash->id, $register->financial_account_id);
        $this->assertSame('caja3', $register->normalized_name);
        $this->assertTrue($register->active);

        $bank = app(FinancialAccountManager::class)->create(
            'Banco operativo',
            FinancialAccountType::BankAccount,
            'ARS',
            $admin
        );

        try {
            app(CashRegisterManager::class)->create(
                'Caja inválida',
                $bank,
                $admin
            );
            $this->fail('Una caja operativa no debe aceptar cuenta bancaria.');
        } catch (DomainException) {
            $this->assertDatabaseMissing('cash_registers', [
                'name' => 'Caja inválida',
            ]);
        }

        $this->actingAs($otherAdmin);

        $foreignCash = app(FinancialAccountManager::class)->create(
            'Efectivo externa',
            FinancialAccountType::CashBox,
            'ARS',
            $otherAdmin
        );

        $this->actingAs($admin);

        $this->expectException(DomainException::class);
        app(CashRegisterManager::class)->create(
            'Caja extranjera',
            $foreignCash,
            $admin
        );
    }

    public function test_operator_opens_idempotent_session_and_cannot_open_two(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member(
            $organization,
            UserRole::Operator
        );

        $this->actingAs($admin);

        $cash1 = app(FinancialAccountManager::class)->create(
            'Efectivo Caja 1',
            FinancialAccountType::CashBox,
            'ARS',
            $admin
        );
        $cash2 = app(FinancialAccountManager::class)->create(
            'Efectivo Caja 2',
            FinancialAccountType::CashBox,
            'ARS',
            $admin
        );

        $register1 = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash1,
            $admin
        );
        $register2 = app(CashRegisterManager::class)->create(
            'Caja 2',
            $cash2,
            $admin
        );

        $this->actingAs($operator);
        app(CurrentOrganization::class)->forget($operator);

        $manager = app(CashRegisterSessionManager::class);

        $first = $manager->open(
            $register1,
            5000000,
            'cash-open:operator:1',
            $operator
        );
        $retry = $manager->open(
            $register1,
            5000000,
            'cash-open:operator:1',
            $operator
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(
            CashRegisterSessionStatus::Open,
            $first->status
        );
        $this->assertSame(5000000, $first->opening_amount_minor);
        $this->assertSame('ARS', $first->currency_code);
        $this->assertSame($register1->id, $first->cash_register_id);
        $this->assertSame($operator->id, $first->opened_by_user_id);
        $this->assertSame(
            $first->id,
            $manager->currentFor($operator)?->id
        );

        try {
            $manager->open(
                $register1,
                5100000,
                'cash-open:operator:1',
                $operator
            );
            $this->fail('El retry distinto debe rechazarse.');
        } catch (DomainException) {
            $this->assertDatabaseCount('cash_register_sessions', 1);
        }

        $this->expectException(DomainException::class);
        $manager->open(
            $register2,
            0,
            'cash-open:operator:2',
            $operator
        );
    }

    public function test_register_account_and_open_session_are_protected(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = app(FinancialAccountManager::class)->create(
            'Efectivo protegida',
            FinancialAccountType::CashBox,
            'ARS',
            $admin
        );

        $register = app(CashRegisterManager::class)->create(
            'Caja protegida',
            $cash,
            $admin
        );

        $this->actingAs($operator);
        app(CurrentOrganization::class)->forget($operator);

        $session = app(CashRegisterSessionManager::class)->open(
            $register,
            1000000,
            'cash-open:protected',
            $operator
        );

        $this->actingAs($admin);
        app(CurrentOrganization::class)->forget($admin);

        try {
            app(CashRegisterManager::class)->toggleActive(
                $register,
                $admin
            );
            $this->fail('No debe inactivarse una caja con turno abierto.');
        } catch (DomainException) {
            $this->assertTrue($register->refresh()->active);
        }

        try {
            app(FinancialAccountManager::class)->toggleActive(
                $cash,
                $admin
            );
            $this->fail('No debe inactivarse la cuenta de una caja activa.');
        } catch (DomainException) {
            $this->assertTrue($cash->refresh()->active);
        }

        try {
            $register->delete();
            $this->fail('No debe existir borrado físico de caja.');
        } catch (DomainException) {
            $this->assertDatabaseHas('cash_registers', [
                'id' => $register->id,
            ]);
        }

        $this->expectException(QueryException::class);
        DB::table('cash_register_sessions')
            ->where('id', $session->id)
            ->update(['opening_amount_minor' => 1]);
    }

    public function test_database_rejects_cross_tenant_register_binding(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        [$otherOrganization, $otherAdmin] = $this->actor(UserRole::Admin);

        $this->actingAs($otherAdmin);

        $foreignCash = app(FinancialAccountManager::class)->create(
            'Caja ajena DB',
            FinancialAccountType::CashBox,
            'ARS',
            $otherAdmin
        );

        $this->expectException(QueryException::class);

        DB::table('cash_registers')->insert([
            'organization_id' => $organization->id,
            'public_id' => (string) Str::uuid(),
            'financial_account_id' => $foreignCash->id,
            'name' => 'Caja inválida DB',
            'normalized_name' => 'cajainvalidadb',
            'active' => true,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{Organization, User} */
    private function actor(UserRole $role): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org cash '.$suffix,
            'slug' => 'org-cash-'.$suffix,
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
