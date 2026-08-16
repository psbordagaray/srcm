<?php

namespace Tests\Feature\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoConnectionSecrets;
use App\Adapters\Finance\MercadoPago\MercadoPagoPointRefundAdapter;
use App\Contracts\Finance\MercadoPagoConnectionSecretStore;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialProviderAutomationGate;
use App\Domain\Finance\FinancialProviderCompatibilityRegistry;
use App\Domain\Finance\FinancialProviderConnectionCompatibilityManager;
use App\Domain\Finance\FinancialProviderConnectionManager;
use App\Domain\Finance\FinancialProviderRefundAdapterRegistry;
use App\Domain\Finance\MercadoPagoPointRefundActivationManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderConnectionHealthStatus;
use App\Enums\UserRole;
use App\Models\FinancialProviderConnection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MercadoPagoPointRefundControlledActivationTest
    extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_container_wires_refund_adapter_but_legacy_binding_still_blocks_refund(): void
    {
        [$organization, $admin] =
            $this->organizationWithMember(
                UserRole::Admin
            );

        $this->actingAs($admin);

        [$connection] =
            $this->legacyMercadoPagoConnection(
                $organization,
                $admin
            );

        $adapter =
            app(
                FinancialProviderRefundAdapterRegistry::class
            )->adapterFor(
                'mercado-pago'
            );

        $this->assertInstanceOf(
            MercadoPagoPointRefundAdapter::class,
            $adapter
        );

        $decision =
            app(
                FinancialProviderAutomationGate::class
            )->evaluate(
                $connection,
                FinancialProviderCapability::Refund
            );

        $this->assertFalse(
            $decision->allowed
        );

        $this->assertSame(
            'capability_unknown',
            $decision->reasonCode
        );

        Http::assertNothingSent();
    }

    public function test_admin_preflight_migrates_binding_and_records_degraded_refund_health_without_enabling_money(): void
    {
        [$organization, $admin] =
            $this->organizationWithMember(
                UserRole::Admin
            );

        $this->actingAs($admin);

        [$connection, $legacyBinding] =
            $this->legacyMercadoPagoConnection(
                $organization,
                $admin
            );

        $this->bindSecrets(
            $this->fakeSecrets()
        );

        Http::fake([
            'https://api.mercadolibre.com/users/me' =>
                Http::response([
                    'id' => 123456789,
                    'nickname' =>
                        'provider-field-not-persisted',
                ], 200),
        ]);

        $result =
            app(
                MercadoPagoPointRefundActivationManager::class
            )->prepare(
                $connection,
                $admin
            );

        $this->assertSame(
            'mercado-pago:orders-v1:point-refund-v1:p8.4.3.3',
            $result
                ->binding
                ->compatibility
                ->registry_key
        );

        $this->assertSame(
            $legacyBinding->id,
            $result
                ->binding
                ->previous_binding_id
        );

        $this->assertSame(
            FinancialProviderCapability::Refund,
            $result
                ->health
                ->capability
        );

        $this->assertSame(
            FinancialProviderConnectionHealthStatus::Degraded,
            $result
                ->health
                ->health_status
        );

        $this->assertSame(
            'refund_smoke_required',
            $result
                ->health
                ->diagnostic_code
        );

        $this->assertSame(
            $result->binding->id,
            $result
                ->health
                ->financial_provider_connection_compatibility_binding_id
        );

        $this->assertFalse(
            $result
                ->decision
                ->allowed
        );

        $this->assertSame(
            'health_degraded',
            $result
                ->decision
                ->reasonCode
        );

        $this->assertDatabaseCount(
            'financial_provider_compatibilities',
            3
        );

        $this->assertDatabaseCount(
            'financial_provider_connection_compatibility_bindings',
            2
        );

        $this->assertDatabaseCount(
            'financial_provider_connection_health_checks',
            1
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            0
        );

        $this->assertDatabaseCount(
            'commerce_post_sale_external_refund_dispatches',
            0
        );

        Http::assertSentCount(1);

        Http::assertSent(
            fn (ClientRequest $request): bool =>
                $request->method() === 'GET'
                && $request->url()
                    === 'https://api.mercadolibre.com/users/me'
        );
    }

    public function test_prepare_is_idempotent_after_readiness_health_and_does_not_probe_again(): void
    {
        [$organization, $admin] =
            $this->organizationWithMember(
                UserRole::Admin
            );

        $this->actingAs($admin);

        [$connection] =
            $this->legacyMercadoPagoConnection(
                $organization,
                $admin
            );

        $this->bindSecrets(
            $this->fakeSecrets()
        );

        Http::fake([
            'https://api.mercadolibre.com/users/me' =>
                Http::response([
                    'id' => 123456789,
                ], 200),
        ]);

        $manager =
            app(
                MercadoPagoPointRefundActivationManager::class
            );

        $first =
            $manager->prepare(
                $connection,
                $admin
            );

        $second =
            $manager->prepare(
                $connection,
                $admin
            );

        $this->assertSame(
            $first->binding->id,
            $second->binding->id
        );

        $this->assertSame(
            $first->health->id,
            $second->health->id
        );

        $this->assertDatabaseCount(
            'financial_provider_connection_health_checks',
            1
        );

        Http::assertSentCount(1);
    }

    public function test_failed_readiness_preflight_does_not_migrate_binding_or_register_refund_snapshot(): void
    {
        [$organization, $admin] =
            $this->organizationWithMember(
                UserRole::Admin
            );

        $this->actingAs($admin);

        [$connection, $legacyBinding] =
            $this->legacyMercadoPagoConnection(
                $organization,
                $admin
            );

        $this->app->instance(
            MercadoPagoConnectionSecretStore::class,
            new class implements MercadoPagoConnectionSecretStore {
                public function forConnection(
                    FinancialProviderConnection $connection
                ): MercadoPagoConnectionSecrets {
                    throw new DomainException(
                        'raw credential failure'
                    );
                }
            }
        );

        $this->assertDomainFailure(
            fn () => app(
                MercadoPagoPointRefundActivationManager::class
            )->prepare(
                $connection,
                $admin
            )
        );

        $current =
            app(
                FinancialProviderConnectionCompatibilityManager::class
            )->currentBinding(
                $connection
            );

        $this->assertSame(
            $legacyBinding->id,
            $current?->id
        );

        $this->assertDatabaseCount(
            'financial_provider_compatibilities',
            2
        );

        $this->assertDatabaseCount(
            'financial_provider_connection_compatibility_bindings',
            1
        );

        $this->assertDatabaseCount(
            'financial_provider_connection_health_checks',
            0
        );

        Http::assertNothingSent();
    }

    public function test_operator_and_non_mercado_pago_connection_fail_closed(): void
    {
        [$organization, $admin] =
            $this->organizationWithMember(
                UserRole::Admin
            );

        [, $operator] =
            $this->organizationWithMember(
                UserRole::Operator,
                $organization
            );

        $this->actingAs($operator);

        [$connection] =
            $this->legacyMercadoPagoConnection(
                $organization,
                $admin
            );

        $this->assertDomainFailure(
            fn () => app(
                MercadoPagoPointRefundActivationManager::class
            )->prepare(
                $connection,
                $operator
            )
        );

        $this->actingAs($admin);

        $paywayAccount =
            app(
                FinancialAccountManager::class
            )->create(
                'Payway activation guard',
                FinancialAccountType::CardProcessor,
                'ARS',
                $admin,
                'Payway'
            );

        $payway =
            app(
                FinancialProviderConnectionManager::class
            )->connect(
                $paywayAccount,
                'payway',
                $admin,
                'payway-p8434'
            );

        $this->assertDomainFailure(
            fn () => app(
                MercadoPagoPointRefundActivationManager::class
            )->prepare(
                $payway,
                $admin
            )
        );

        Http::assertNothingSent();
    }

    /**
     * @return array{
     *     FinancialProviderConnection,
     *     \App\Models\FinancialProviderConnectionCompatibilityBinding
     * }
     */
    private function legacyMercadoPagoConnection(
        Organization $organization,
        User $admin
    ): array {
        [$legacy] =
            app(
                FinancialProviderCompatibilityRegistry::class
            )->seedReferenceRegistry();

        $account =
            app(
                FinancialAccountManager::class
            )->create(
                'Mercado Pago P8.4.3.4 '
                    .Str::lower(
                        Str::random(6)
                    ),
                FinancialAccountType::DigitalWallet,
                'ARS',
                $admin,
                'Mercado Pago'
            );

        $connection =
            app(
                FinancialProviderConnectionManager::class
            )->connect(
                $account,
                'mercado-pago',
                $admin,
                '123456789'
            );

        $binding =
            app(
                FinancialProviderConnectionCompatibilityManager::class
            )->bind(
                $connection,
                $legacy,
                $admin
            );

        return [
            $connection,
            $binding,
        ];
    }

    private function fakeSecrets():
        MercadoPagoConnectionSecrets {
        return new MercadoPagoConnectionSecrets(
            webhookSecret:
                'whsec-p8434-safe-test',
            accessToken:
                'test-token-p8434-never-persist',
            applicationId:
                '987654321',
            userId:
                '123456789',
            liveMode:
                false
        );
    }

    private function bindSecrets(
        MercadoPagoConnectionSecrets $secrets
    ): void {
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
    }

    /**
     * @return array{Organization, User}
     */
    private function organizationWithMember(
        UserRole $role,
        ?Organization $organization = null
    ): array {
        $suffix =
            Str::lower(
                Str::random(8)
            );

        $organization ??=
            Organization::query()
                ->create([
                    'name' =>
                        'Org P8.4.3.4 '
                        .$suffix,
                    'slug' =>
                        'org-p8434-'
                        .$suffix,
                    'active' =>
                        true,
                ]);

        $user =
            User::factory()->create([
                'role' =>
                    $role,
                'email_verified_at' =>
                    now(),
            ]);

        OrganizationMembership::query()
            ->create([
                'organization_id' =>
                    $organization->id,
                'user_id' =>
                    $user->id,
                'role' =>
                    $role,
                'active' =>
                    true,
            ]);

        $user->forceFill([
            'current_organization_id' =>
                $organization->id,
        ])->saveQuietly();

        app(
            CurrentOrganization::class
        )->forget(
            $user
        );

        return [
            $organization,
            $user->refresh(),
        ];
    }

    private function assertDomainFailure(
        callable $callback
    ): void {
        try {
            $callback();

            $this->fail(
                'Se esperaba DomainException.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
