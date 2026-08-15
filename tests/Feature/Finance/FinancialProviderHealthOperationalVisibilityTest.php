<?php

namespace Tests\Feature\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoConnectionSecrets;
use App\Contracts\Finance\MercadoPagoConnectionSecretStore;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialProviderCompatibilityRegistry;
use App\Domain\Finance\FinancialProviderConnectionCompatibilityManager;
use App\Domain\Finance\FinancialProviderConnectionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\UserRole;
use App\Models\FinancialProviderConnection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialProviderHealthOperationalVisibilityTest
    extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_admin_sees_contract_health_and_can_run_safe_read_probe(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        [$connection, $registryKey] =
            $this->boundMercadoPagoConnection($admin);

        $before = $this->get(
            route('financial-accounts.index')
        );

        $before->assertOk()
            ->assertSee($registryKey)
            ->assertSee('Sin verificación')
            ->assertSee('Verificar lectura');

        $secrets = $this->fakeSecrets();

        $this->app->instance(
            MercadoPagoConnectionSecretStore::class,
            new class($secrets)
                implements MercadoPagoConnectionSecretStore {
                public function __construct(
                    private readonly MercadoPagoConnectionSecrets $secrets
                ) {
                }

                public function forConnection(
                    FinancialProviderConnection $connection
                ): MercadoPagoConnectionSecrets {
                    return $this->secrets;
                }
            }
        );

        Http::fake([
            'https://api.mercadolibre.com/users/me' =>
                Http::response([
                    'id' => (int) $secrets->userId,
                    'raw' => 'must-not-render',
                ], 200),
        ]);

        $response = $this->post(
            route(
                'financial-provider-connections.health.read',
                $connection
            )
        );

        $response->assertRedirect(
            route('financial-accounts.index')
        );

        $this->assertDatabaseHas(
            'financial_provider_connection_health_checks',
            [
                'organization_id' => $organization->id,
                'financial_provider_connection_id' =>
                    $connection->id,
                'capability' => 'read',
                'health_status' => 'healthy',
                'diagnostic_code' => 'ok',
            ]
        );

        $after = $this->get(
            route('financial-accounts.index')
        );

        $after->assertOk()
            ->assertSee('healthy')
            ->assertSee('Habilitada')
            ->assertDontSee('must-not-render')
            ->assertDontSee($secrets->accessToken)
            ->assertDontSee($secrets->webhookSecret);
    }

    public function test_probe_route_is_admin_only_and_tenant_private(): void
    {
        [$organization, $admin, $operator] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        [$connection] =
            $this->boundMercadoPagoConnection($admin);

        $this->actingAs($operator);

        $this->post(
            route(
                'financial-provider-connections.health.read',
                $connection
            )
        )->assertForbidden();

        $foreignOrganization = Organization::query()
            ->create([
                'name' => 'Foreign P5.8.2',
                'slug' => 'foreign-p582-'.Str::lower(
                    Str::random(6)
                ),
                'active' => true,
            ]);

        $foreignAdmin = User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::query()->create([
            'organization_id' => $foreignOrganization->id,
            'user_id' => $foreignAdmin->id,
            'role' => UserRole::Admin,
            'active' => true,
        ]);

        $foreignAdmin->forceFill([
            'current_organization_id' =>
                $foreignOrganization->id,
        ])->saveQuietly();

        app(CurrentOrganization::class)->forget(
            $foreignAdmin
        );

        $this->actingAs($foreignAdmin);

        $this->post(
            route(
                'financial-provider-connections.health.read',
                $connection
            )
        )->assertNotFound();

        $this->assertDatabaseCount(
            'financial_provider_connection_health_checks',
            0
        );
    }

    private function boundMercadoPagoConnection(
        User $admin
    ): array {
        [$compatibility] = app(
            FinancialProviderCompatibilityRegistry::class
        )->seedReferenceRegistry();

        $account = app(FinancialAccountManager::class)
            ->create(
                'MP visible P5.8.2',
                FinancialAccountType::DigitalWallet,
                'ARS',
                $admin,
                'Mercado Pago'
            );

        $connection = app(
            FinancialProviderConnectionManager::class
        )->connect(
            $account,
            'mercado-pago',
            $admin,
            '123456789'
        );

        app(
            FinancialProviderConnectionCompatibilityManager::class
        )->bind(
            $connection,
            $compatibility,
            $admin
        );

        return [
            $connection,
            $compatibility->registry_key,
        ];
    }

    private function fakeSecrets(): MercadoPagoConnectionSecrets
    {
        return new MercadoPagoConnectionSecrets(
            webhookSecret: 'whsec-p582-ui-test',
            accessToken: 'test-token-p582-ui-never-render',
            applicationId: '987654321',
            userId: '123456789',
            liveMode: false
        );
    }

    /**
     * @return array{Organization, User, User}
     */
    private function organizationWithUsers(): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org UI P5.8.2 '.$suffix,
            'slug' => 'org-ui-p582-'.$suffix,
            'active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
            'email_verified_at' => now(),
        ]);

        foreach ([
            [$admin, UserRole::Admin],
            [$operator, UserRole::Operator],
        ] as [$user, $role]) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $role,
                'active' => true,
            ]);

            $user->forceFill([
                'current_organization_id' =>
                    $organization->id,
            ])->saveQuietly();

            app(CurrentOrganization::class)->forget($user);
        }

        return [
            $organization,
            $admin->refresh(),
            $operator->refresh(),
        ];
    }
}
