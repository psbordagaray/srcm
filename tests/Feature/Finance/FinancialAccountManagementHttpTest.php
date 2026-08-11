<?php

namespace Tests\Feature\Finance;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialAccountManagementHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_routes_and_permissions_are_explicit(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);

        foreach ([
            'financial-accounts.index' => ['GET', 'can:use-financial-accounts'],
            'financial-accounts.create' => ['GET', 'can:manage-financial-accounts'],
            'financial-accounts.store' => ['POST', 'can:manage-financial-accounts'],
            'financial-accounts.edit' => ['GET', 'can:manage-financial-accounts'],
            'financial-accounts.update' => ['PUT', 'can:manage-financial-accounts'],
            'financial-accounts.toggle-active' => [
                'PATCH',
                'can:manage-financial-accounts',
            ],
        ] as $name => [$method, $ability]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertContains($method, $route->methods());
            $this->assertContains(
                RequireOrganization::class,
                $route->gatherMiddleware()
            );
            $this->assertContains(
                $ability,
                $route->gatherMiddleware()
            );
        }

        $this->actingAs($operator)
            ->get(route('financial-accounts.index'))
            ->assertOk()
            ->assertSee('Cuentas financieras');

        $this->actingAs($operator)
            ->get(route('financial-accounts.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('financial-accounts.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('financial-accounts.create'))
            ->assertOk()
            ->assertSee('Nueva cuenta financiera');

        $this->assertNull(
            app('router')->getRoutes()->getByName(
                'financial-accounts.destroy'
            )
        );
    }

    public function test_admin_creates_updates_and_toggles_scoped_account(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);

        $this->actingAs($admin)
            ->post(route('financial-accounts.store'), [
                'name' => ' Mercado Pago principal ',
                'type' => FinancialAccountType::DigitalWallet->value,
                'currency_code' => 'ars',
                'provider' => ' Mercado Pago ',
                'external_label' => ' Recaudadora online ',
            ])
            ->assertRedirect(route('financial-accounts.index'))
            ->assertSessionHasNoErrors();

        $account = FinancialAccount::query()
            ->forOrganization($organization)
            ->sole();

        $this->assertSame('Mercado Pago principal', $account->name);
        $this->assertSame('ARS', $account->currency_code);
        $this->assertSame(
            FinancialAccountType::DigitalWallet,
            $account->type
        );
        $this->assertTrue($account->active);

        $this->actingAs($admin)
            ->put(route('financial-accounts.update', $account), [
                'name' => 'Mercado Pago ventas',
                'type' => FinancialAccountType::DigitalWallet->value,
                'currency_code' => 'ARS',
                'provider' => 'Mercado Pago',
                'external_label' => 'Cuenta principal',
            ])
            ->assertRedirect(route('financial-accounts.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'Mercado Pago ventas',
            $account->fresh()->name
        );

        $this->actingAs($admin)
            ->patch(route('financial-accounts.toggle-active', $account))
            ->assertRedirect(route('financial-accounts.index'));

        $this->assertFalse($account->fresh()->active);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'event' => 'financial_account_created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'event' => 'financial_account_updated',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'event' => 'financial_account_toggled',
        ]);
    }

    public function test_foreign_account_is_hidden_from_management_routes(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);

        $suffix = Str::lower(Str::random(8));
        $foreignOrganization = Organization::query()->create([
            'name' => 'Org extranjera '.$suffix,
            'slug' => 'org-extranjera-'.$suffix,
            'active' => true,
        ]);
        $foreignAdmin = $this->user(
            $foreignOrganization,
            UserRole::Admin
        );

        $foreignAccount = FinancialAccount::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Banco extranjero',
            'normalized_name' => 'bancoextranjero',
            'type' => FinancialAccountType::BankAccount,
            'currency_code' => 'ARS',
            'active' => true,
            'created_by_user_id' => $foreignAdmin->id,
            'updated_by_user_id' => $foreignAdmin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('financial-accounts.edit', $foreignAccount))
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(route('financial-accounts.update', $foreignAccount), [
                'name' => 'Intento',
                'type' => FinancialAccountType::BankAccount->value,
                'currency_code' => 'ARS',
            ])
            ->assertNotFound();

        $this->actingAs($admin)
            ->patch(route(
                'financial-accounts.toggle-active',
                $foreignAccount
            ))
            ->assertNotFound();

        $this->assertTrue($foreignAccount->fresh()->active);
        $this->assertSame(
            'Banco extranjero',
            $foreignAccount->fresh()->name
        );
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role,
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                ['role' => $role, 'active' => true]
            )
        );

        app(CurrentOrganization::class)->forget($user);

        return $user->refresh();
    }
}
