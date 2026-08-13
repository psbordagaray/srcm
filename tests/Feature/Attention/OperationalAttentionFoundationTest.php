<?php

namespace Tests\Feature\Attention;

use App\Domain\Attention\OperationalAttentionReader;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\CashSecurityDropManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashSecurityDropReason;
use App\Enums\FinancialAccountType;
use App\Enums\UserRole;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalAttentionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_request_reaches_admin_and_approval_reaches_operator_without_module_hunting(): void
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

        app(CashRegisterSessionManager::class)->open(
            $register,
            5000000,
            'attention:open',
            $operator
        );

        $drop = app(CashSecurityDropManager::class)->request(
            $treasury,
            100000,
            CashSecurityDropReason::ExcessCash,
            'Sobre Attention',
            'attention:request',
            $operator
        );

        $operatorAttention = app(OperationalAttentionReader::class)
            ->read($operator);

        $this->assertSame(0, $operatorAttention['count']);

        $this->actingAs($admin);

        $adminAttention = app(OperationalAttentionReader::class)
            ->read($admin);

        $this->assertSame(1, $adminAttention['count']);
        $this->assertSame(
            'Autorizar retiro de seguridad',
            $adminAttention['items']->first()['title']
        );

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Requiere tu atención')
            ->assertSee('Autorizar retiro de seguridad')
            ->assertSee('data-operational-attention-bell', false)
            ->assertSee('data-operational-attention-count', false);

        app(CashSecurityDropManager::class)->approve(
            $drop,
            'Autorizado desde atención',
            'attention:approve',
            $admin
        );

        $this->assertSame(
            0,
            app(OperationalAttentionReader::class)
                ->read($admin)['count']
        );

        $this->actingAs($operator);

        $operatorAttention = app(OperationalAttentionReader::class)
            ->read($operator);

        $this->assertSame(1, $operatorAttention['count']);
        $this->assertSame(
            'Retiro autorizado · ejecutá la extracción',
            $operatorAttention['items']->first()['title']
        );

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Retiro autorizado · ejecutá la extracción')
            ->assertSee('Caja fuerte');

        $this->get(route(
            'cash-registers.index',
            ['operation' => 'security_drop']
        ))
            ->assertOk()
            ->assertSee("operation: 'security_drop'", false)
            ->assertSee('Ejecutar retiro autorizado');

        app(CashSecurityDropManager::class)->execute(
            $drop->refresh(),
            'attention:execute',
            $operator
        );

        $this->assertSame(
            0,
            app(OperationalAttentionReader::class)
                ->read($operator)['count']
        );
    }

    public function test_rejected_result_is_visible_until_actor_acknowledges_it(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);

        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            'Efectivo Caja',
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
            'Caja Atención',
            $cash,
            $admin
        );

        $this->actingAs($operator);

        app(CashRegisterSessionManager::class)->open(
            $register,
            1000000,
            'attention:reject:open',
            $operator
        );

        $drop = app(CashSecurityDropManager::class)->request(
            $treasury,
            100000,
            CashSecurityDropReason::SupervisorRequest,
            'Solicitud a rechazar',
            'attention:reject:request',
            $operator
        );

        $this->actingAs($admin);

        app(CashSecurityDropManager::class)->reject(
            $drop,
            'Importe no justificado.',
            'attention:reject:decision',
            $admin
        );

        $this->actingAs($operator);

        $attention = app(OperationalAttentionReader::class)
            ->read($operator);

        $this->assertSame(1, $attention['result_count']);

        $item = $attention['items']->first();

        $this->assertSame(
            'Retiro de seguridad rechazado',
            $item['title']
        );
        $this->assertTrue($item['acknowledgeable']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Retiro de seguridad rechazado')
            ->assertSee('Importe no justificado.')
            ->assertSee('Marcar visto');

        $this->post(
            route('operational-attention.acknowledge'),
            ['attention_key' => $item['key']]
        )
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('operational_attention_receipts', [
            'organization_id' => $organization->id,
            'user_id' => $operator->id,
            'attention_key' => $item['key'],
            'source_type' => 'cash_security_drop',
            'source_public_id' => $drop->public_id,
        ]);

        $this->assertSame(
            0,
            app(OperationalAttentionReader::class)
                ->read($operator)['count']
        );
    }

    public function test_attention_is_tenant_private_and_receipt_cannot_ack_foreign_or_action_item(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $operator = $this->member($organization, UserRole::Operator);
        [$foreignOrganization, $foreignAdmin] = $this->actor(
            UserRole::Admin
        );
        $foreignOperator = $this->member(
            $foreignOrganization,
            UserRole::Operator
        );

        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            'Caja local',
            FinancialAccountType::CashBox,
            'ARS'
        );
        $treasury = $this->account(
            $admin,
            'Tesorería local',
            FinancialAccountType::CashReserve,
            'ARS'
        );
        $register = app(CashRegisterManager::class)->create(
            'Caja local',
            $cash,
            $admin
        );

        $this->actingAs($operator);

        app(CashRegisterSessionManager::class)->open(
            $register,
            1000000,
            'attention:tenant:open',
            $operator
        );

        $drop = app(CashSecurityDropManager::class)->request(
            $treasury,
            100000,
            CashSecurityDropReason::ScheduledDrop,
            null,
            'attention:tenant:request',
            $operator
        );

        $this->actingAs($admin);

        $localItem = app(OperationalAttentionReader::class)
            ->read($admin)['items']
            ->first();

        $this->assertNotNull($localItem);
        $this->assertFalse($localItem['acknowledgeable']);

        $this->actingAs($foreignAdmin);

        $this->assertSame(
            0,
            app(OperationalAttentionReader::class)
                ->read($foreignAdmin)['count']
        );

        $this->post(
            route('operational-attention.acknowledge'),
            ['attention_key' => $localItem['key']]
        )
            ->assertRedirect()
            ->assertSessionHasErrors('attention');

        $this->assertDatabaseCount('operational_attention_receipts', 0);

        $this->actingAs($foreignOperator);

        $this->assertSame(
            0,
            app(OperationalAttentionReader::class)
                ->read($foreignOperator)['count']
        );

        $this->assertDatabaseHas('cash_security_drop_requests', [
            'id' => $drop->id,
            'organization_id' => $organization->id,
        ]);
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
            'name' => 'Org Attention '.$suffix,
            'slug' => 'org-attention-'.$suffix,
            'active' => true,
        ]);

        return [$organization, $this->member($organization, $role)];
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
