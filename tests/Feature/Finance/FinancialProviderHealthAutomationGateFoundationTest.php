<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialProviderAutomationGate;
use App\Domain\Finance\FinancialProviderCompatibilityRegistry;
use App\Domain\Finance\FinancialProviderConnectionCompatibilityManager;
use App\Domain\Finance\FinancialProviderConnectionHealthManager;
use App\Domain\Finance\FinancialProviderConnectionManager;
use App\Domain\Finance\FinancialProviderHealthObservation;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderCompatibilityStatus;
use App\Enums\FinancialProviderConnectionHealthStatus;
use App\Enums\UserRole;
use App\Models\FinancialProviderCompatibility;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialProviderHealthAutomationGateFoundationTest
    extends TestCase
{
    use RefreshDatabase;

    public function test_health_schema_is_tenant_scoped_append_only_and_secret_free(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'financial_provider_connection_health_checks',
                [
                    'organization_id',
                    'financial_provider_connection_id',
                    'financial_provider_connection_compatibility_binding_id',
                    'capability',
                    'health_status',
                    'source_key',
                    'diagnostic_code',
                    'latency_ms',
                    'checked_at',
                    'created_at',
                ]
            )
        );

        foreach ([
            'access_token',
            'refresh_token',
            'client_secret',
            'api_key',
            'webhook_secret',
            'authorization',
            'raw_payload',
            'response_body',
        ] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn(
                    'financial_provider_connection_health_checks',
                    $forbidden
                )
            );
        }
    }

    public function test_health_checks_append_and_latest_is_bound_to_current_contract(): void
    {
        [, $admin] = $this->organizationWithUsers();
        $this->actingAs($admin);

        [$connection, $compatibility] =
            $this->mercadoPagoConnectionAndCompatibility($admin);

        $binding = app(
            FinancialProviderConnectionCompatibilityManager::class
        )->bind(
            $connection,
            $compatibility,
            $admin
        );

        $health = app(
            FinancialProviderConnectionHealthManager::class
        );

        $first = $health->record(
            $connection,
            $this->observation(
                FinancialProviderCapability::Read,
                FinancialProviderConnectionHealthStatus::Degraded,
                'provider:read',
                'slow_response',
                1200,
                '2026-08-14 21:00:00'
            )
        );

        $second = $health->record(
            $connection,
            $this->observation(
                FinancialProviderCapability::Read,
                FinancialProviderConnectionHealthStatus::Healthy,
                'provider:read',
                'ok',
                180,
                '2026-08-14 21:01:00'
            )
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(
            $binding->id,
            $first
                ->financial_provider_connection_compatibility_binding_id
        );
        $this->assertSame(
            $binding->id,
            $second
                ->financial_provider_connection_compatibility_binding_id
        );

        $latest = $health->latestForBinding(
            $connection,
            FinancialProviderCapability::Read,
            $binding->id
        );

        $this->assertSame($second->id, $latest?->id);
        $this->assertDatabaseCount(
            'financial_provider_connection_health_checks',
            2
        );
    }

    public function test_diagnostics_are_codes_not_raw_provider_messages(): void
    {
        $this->assertDomainFailure(
            fn () => new FinancialProviderHealthObservation(
                FinancialProviderCapability::Read,
                FinancialProviderConnectionHealthStatus::Unavailable,
                now(),
                'provider:read',
                'Bearer secret value',
                10
            )
        );

        $this->assertDomainFailure(
            fn () => new FinancialProviderHealthObservation(
                FinancialProviderCapability::Read,
                FinancialProviderConnectionHealthStatus::Unavailable,
                now(),
                'provider read body',
                'timeout',
                10
            )
        );

        $this->assertDomainFailure(
            fn () => new FinancialProviderHealthObservation(
                FinancialProviderCapability::Read,
                FinancialProviderConnectionHealthStatus::Unavailable,
                now(),
                'provider:read',
                'timeout',
                600001
            )
        );
    }

    public function test_automation_requires_current_binding_capability_and_healthy_check(): void
    {
        [, $admin] = $this->organizationWithUsers();
        $this->actingAs($admin);

        [$connection, $compatibility] =
            $this->mercadoPagoConnectionAndCompatibility($admin);

        app(
            FinancialProviderConnectionCompatibilityManager::class
        )->bind(
            $connection,
            $compatibility,
            $admin
        );

        $gate = app(
            FinancialProviderAutomationGate::class
        );

        $before = $gate->evaluate(
            $connection,
            FinancialProviderCapability::Read
        );

        $this->assertFalse($before->allowed);
        $this->assertSame(
            'health_unknown',
            $before->reasonCode
        );

        app(
            FinancialProviderConnectionHealthManager::class
        )->record(
            $connection,
            $this->observation(
                FinancialProviderCapability::Read,
                FinancialProviderConnectionHealthStatus::Healthy,
                'provider:read',
                'ok',
                100,
                '2026-08-14 21:02:00'
            )
        );

        $after = $gate->evaluate(
            $connection,
            FinancialProviderCapability::Read
        );

        $this->assertTrue($after->allowed);
        $this->assertSame('allowed', $after->reasonCode);
    }

    public function test_degraded_or_unavailable_health_blocks_only_provider_automation(): void
    {
        [, $admin] = $this->organizationWithUsers();
        $this->actingAs($admin);

        [$connection, $compatibility] =
            $this->mercadoPagoConnectionAndCompatibility($admin);

        app(
            FinancialProviderConnectionCompatibilityManager::class
        )->bind(
            $connection,
            $compatibility,
            $admin
        );

        $health = app(
            FinancialProviderConnectionHealthManager::class
        );
        $gate = app(FinancialProviderAutomationGate::class);

        $health->record(
            $connection,
            $this->observation(
                FinancialProviderCapability::Webhook,
                FinancialProviderConnectionHealthStatus::Unavailable,
                'provider:webhook',
                'transport_down',
                null,
                '2026-08-14 21:03:00'
            )
        );

        $blocked = $gate->evaluate(
            $connection,
            FinancialProviderCapability::Webhook
        );

        $this->assertFalse($blocked->allowed);
        $this->assertSame(
            'health_unavailable',
            $blocked->reasonCode
        );

        $this->assertTrue($connection->refresh()->active);
        $this->assertTrue(
            $connection->account()->firstOrFail()->active
        );

        $health->record(
            $connection,
            $this->observation(
                FinancialProviderCapability::Webhook,
                FinancialProviderConnectionHealthStatus::Healthy,
                'provider:webhook',
                'ok',
                90,
                '2026-08-14 21:04:00'
            )
        );

        $this->assertTrue(
            $gate->evaluate(
                $connection,
                FinancialProviderCapability::Webhook
            )->allowed
        );
    }

    public function test_contract_unknown_or_new_binding_without_new_health_fails_closed(): void
    {
        [, $admin] = $this->organizationWithUsers();
        $this->actingAs($admin);

        [$connection, $v1] =
            $this->mercadoPagoConnectionAndCompatibility($admin);

        $bindings = app(
            FinancialProviderConnectionCompatibilityManager::class
        );
        $health = app(
            FinancialProviderConnectionHealthManager::class
        );
        $gate = app(FinancialProviderAutomationGate::class);

        $bindings->bind($connection, $v1, $admin);

        $health->record(
            $connection,
            $this->observation(
                FinancialProviderCapability::Read,
                FinancialProviderConnectionHealthStatus::Healthy,
                'provider:read',
                'ok',
                80,
                '2026-08-14 21:05:00'
            )
        );

        $this->assertTrue(
            $gate->evaluate(
                $connection,
                FinancialProviderCapability::Read
            )->allowed
        );

        $v2 = $this->registerMercadoPagoV2();
        $bindings->bind($connection, $v2, $admin);

        $afterCutover = $gate->evaluate(
            $connection,
            FinancialProviderCapability::Read
        );

        $this->assertFalse($afterCutover->allowed);
        $this->assertSame(
            'health_unknown',
            $afterCutover->reasonCode
        );

        $health->record(
            $connection,
            $this->observation(
                FinancialProviderCapability::Read,
                FinancialProviderConnectionHealthStatus::Healthy,
                'provider:read',
                'ok',
                70,
                '2026-08-14 21:06:00'
            )
        );

        $this->assertTrue(
            $gate->evaluate(
                $connection,
                FinancialProviderCapability::Read
            )->allowed
        );

        $refund = $gate->evaluate(
            $connection,
            FinancialProviderCapability::Refund
        );

        $this->assertFalse($refund->allowed);
        $this->assertSame(
            'capability_not_registered',
            $refund->reasonCode
        );
    }

    public function test_database_guards_and_model_immutability_protect_health_evidence(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();
        $this->actingAs($admin);

        [$connection, $compatibility] =
            $this->mercadoPagoConnectionAndCompatibility($admin);

        $binding = app(
            FinancialProviderConnectionCompatibilityManager::class
        )->bind(
            $connection,
            $compatibility,
            $admin
        );

        $health = app(
            FinancialProviderConnectionHealthManager::class
        )->record(
            $connection,
            $this->observation(
                FinancialProviderCapability::Read,
                FinancialProviderConnectionHealthStatus::Healthy,
                'provider:read',
                'ok',
                50,
                '2026-08-14 21:07:00'
            )
        );

        $this->assertDomainFailure(function () use (
            $health
        ): void {
            $health->forceFill([
                'diagnostic_code' => 'tampered',
            ])->save();
        });

        $this->assertQueryFailure(
            fn () => DB::table(
                'financial_provider_connection_health_checks'
            )
                ->where('id', $health->id)
                ->update([
                    'diagnostic_code' => 'tampered',
                ])
        );

        $foreign = Organization::query()->create([
            'name' => 'Foreign Health Org',
            'slug' => 'foreign-health-'.Str::lower(
                Str::random(6)
            ),
            'active' => true,
        ]);

        $this->assertQueryFailure(
            fn () => DB::table(
                'financial_provider_connection_health_checks'
            )->insert([
                'organization_id' => $foreign->id,
                'financial_provider_connection_id' =>
                    $connection->id,
                'financial_provider_connection_compatibility_binding_id' =>
                    $binding->id,
                'capability' => 'read',
                'health_status' => 'healthy',
                'source_key' => 'provider:read',
                'diagnostic_code' => 'ok',
                'latency_ms' => 50,
                'checked_at' => now(),
                'created_at' => now(),
            ])
        );

        $this->assertDatabaseHas(
            'financial_provider_connection_health_checks',
            [
                'id' => $health->id,
                'organization_id' => $organization->id,
                'financial_provider_connection_id' =>
                    $connection->id,
            ]
        );
    }

    private function mercadoPagoConnectionAndCompatibility(
        User $admin
    ): array {
        [$mercadoPago] = app(
            FinancialProviderCompatibilityRegistry::class
        )->seedReferenceRegistry();

        $account = app(FinancialAccountManager::class)
            ->create(
                'Mercado Pago P5.8.1',
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
            'mp-p581-'.Str::lower(Str::random(6))
        );

        return [$connection, $mercadoPago];
    }

    private function registerMercadoPagoV2(
    ): FinancialProviderCompatibility {
        return app(
            FinancialProviderCompatibilityRegistry::class
        )->register(
            registryKey:
                'mercado-pago:orders-v2:point-v2:p581test',
            providerKey: 'mercado-pago',
            providerLabel: 'Mercado Pago',
            providerContractVersion: 'orders-v2',
            providerContractReference:
                'Contrato V2 simulado para health binding P5.8.1.',
            adapterClass:
                'App\Adapters\Finance\MercadoPago\MercadoPagoExternalFinancialProviderAdapter',
            adapterContractVersion: 'point-v2',
            status:
                FinancialProviderCompatibilityStatus::Compatible,
            migrationRequired: false,
            srcmVersion: 'p5.8.1-test',
            verifiedAt: CarbonImmutable::parse(
                '2026-08-14 21:00:00',
                'UTC'
            ),
            capabilities: [
                [
                    'capability' =>
                        FinancialProviderCapability::Read,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' => true,
                    'evidence_reference' =>
                        'Harness contractual P5.8.1.',
                    'notes' => null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Webhook,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' => true,
                    'evidence_reference' =>
                        'Harness contractual P5.8.1.',
                    'notes' => null,
                ],
            ]
        );
    }

    private function observation(
        FinancialProviderCapability $capability,
        FinancialProviderConnectionHealthStatus $status,
        string $sourceKey,
        ?string $diagnosticCode,
        ?int $latencyMs,
        string $checkedAt
    ): FinancialProviderHealthObservation {
        return new FinancialProviderHealthObservation(
            $capability,
            $status,
            CarbonImmutable::parse($checkedAt, 'UTC'),
            $sourceKey,
            $diagnosticCode,
            $latencyMs
        );
    }

    /**
     * @return array{Organization, User, User}
     */
    private function organizationWithUsers(): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org P5.8.1 '.$suffix,
            'slug' => 'org-p581-'.$suffix,
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
                'current_organization_id' => $organization->id,
            ])->saveQuietly();

            app(CurrentOrganization::class)->forget($user);
        }

        return [
            $organization,
            $admin->refresh(),
            $operator->refresh(),
        ];
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail(
                'La operación debía fallar con DomainException.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertQueryFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail(
                'La operación DB debía fallar con QueryException.'
            );
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
