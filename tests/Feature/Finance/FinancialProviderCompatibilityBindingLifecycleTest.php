<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialProviderCompatibilityLifecycleManager;
use App\Domain\Finance\FinancialProviderCompatibilityRegistry;
use App\Domain\Finance\FinancialProviderConnectionCompatibilityManager;
use App\Domain\Finance\FinancialProviderConnectionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderCompatibilityStatus;
use App\Enums\UserRole;
use App\Models\FinancialProviderCompatibility;
use App\Models\FinancialProviderConnection;
use App\Models\FinancialProviderConnectionCompatibilityBinding;
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

class FinancialProviderCompatibilityBindingLifecycleTest
    extends TestCase
{
    use RefreshDatabase;

    public function test_binding_and_retirement_schema_are_append_only_and_secret_free(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'financial_provider_connection_compatibility_bindings',
                [
                    'financial_provider_connection_id',
                    'financial_provider_compatibility_id',
                    'previous_binding_id',
                    'bound_by_user_id',
                    'bound_at',
                    'created_at',
                ]
            )
        );

        $this->assertTrue(
            Schema::hasColumns(
                'financial_provider_compatibility_retirements',
                [
                    'financial_provider_compatibility_id',
                    'reason',
                    'srcm_version',
                    'retired_at',
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
            'password',
        ] as $secretColumn) {
            $this->assertFalse(
                Schema::hasColumn(
                    'financial_provider_connection_compatibility_bindings',
                    $secretColumn
                )
            );

            $this->assertFalse(
                Schema::hasColumn(
                    'financial_provider_compatibility_retirements',
                    $secretColumn
                )
            );
        }
    }

    public function test_admin_binds_verified_snapshot_idempotently_and_audits(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        [$connection, $mercadoPago] =
            $this->mercadoPagoConnectionAndCompatibility($admin);

        $manager = app(
            FinancialProviderConnectionCompatibilityManager::class
        );

        $binding = $manager->bind(
            $connection,
            $mercadoPago,
            $admin
        );

        $retry = $manager->bind(
            $connection,
            $mercadoPago,
            $admin
        );

        $this->assertSame($binding->id, $retry->id);
        $this->assertNull($binding->previous_binding_id);
        $this->assertSame(
            $mercadoPago->id,
            $binding->financial_provider_compatibility_id
        );
        $this->assertSame(
            $admin->id,
            $binding->bound_by_user_id
        );

        $this->assertDatabaseCount(
            'financial_provider_connection_compatibility_bindings',
            1
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'organization_id' => $organization->id,
                'event' =>
                    'financial_provider_compatibility_bound',
                'auditable_id' => (string) $connection->id,
                'user_id' => $admin->id,
            ]
        );
    }

    public function test_unknown_or_cross_provider_snapshot_cannot_bind(): void
    {
        [, $admin] = $this->organizationWithUsers();
        $this->actingAs($admin);

        $registry = app(
            FinancialProviderCompatibilityRegistry::class
        );

        [$mercadoPago, $payway] =
            $registry->seedReferenceRegistry();

        $accounts = app(FinancialAccountManager::class);
        $connections = app(
            FinancialProviderConnectionManager::class
        );

        $mpAccount = $accounts->create(
            'MP binding guard',
            FinancialAccountType::DigitalWallet,
            'ARS',
            $admin,
            'Mercado Pago'
        );

        $mpConnection = $connections->connect(
            $mpAccount,
            'mercado-pago',
            $admin,
            'mp-binding-guard'
        );

        $bindingManager = app(
            FinancialProviderConnectionCompatibilityManager::class
        );

        $this->assertDomainFailure(
            fn () => $bindingManager->bind(
                $mpConnection,
                $payway,
                $admin
            )
        );

        $paywayAccount = $accounts->create(
            'Payway unknown binding',
            FinancialAccountType::CardProcessor,
            'ARS',
            $admin,
            'Payway'
        );

        $paywayConnection = $connections->connect(
            $paywayAccount,
            'payway',
            $admin,
            'payway-binding-guard'
        );

        $this->assertDomainFailure(
            fn () => $bindingManager->bind(
                $paywayConnection,
                $payway,
                $admin
            )
        );

        $this->assertDatabaseCount(
            'financial_provider_connection_compatibility_bindings',
            0
        );

        $this->assertSame(
            FinancialProviderCompatibilityStatus::Compatible,
            $mercadoPago->compatibility_status
        );
    }

    public function test_migration_appends_chain_and_old_snapshot_can_retire_after_cutover(): void
    {
        [, $admin] = $this->organizationWithUsers();
        $this->actingAs($admin);

        [$connection, $v1] =
            $this->mercadoPagoConnectionAndCompatibility($admin);

        $v2 = $this->registerMercadoPagoV2();

        $bindings = app(
            FinancialProviderConnectionCompatibilityManager::class
        );

        $first = $bindings->bind(
            $connection,
            $v1,
            $admin
        );

        $second = $bindings->bind(
            $connection,
            $v2,
            $admin
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(
            $first->id,
            $second->previous_binding_id
        );
        $this->assertSame(
            $v2->id,
            $bindings->currentBinding($connection)
                ?->financial_provider_compatibility_id
        );

        $retirement = app(
            FinancialProviderCompatibilityLifecycleManager::class
        )->retire(
            $v1,
            'Point V1 reemplazado por contrato V2 validado.',
            'p5.7.2-test',
            CarbonImmutable::parse('2026-08-14 20:00:00', 'UTC')
        );

        $this->assertSame(
            $v1->id,
            $retirement->financial_provider_compatibility_id
        );
        $this->assertDatabaseCount(
            'financial_provider_connection_compatibility_bindings',
            2
        );
        $this->assertDatabaseCount(
            'financial_provider_compatibility_retirements',
            1
        );
    }

    public function test_active_current_dependency_blocks_retirement_and_retired_current_binding_blocks_reactivation(): void
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

        $lifecycle = app(
            FinancialProviderCompatibilityLifecycleManager::class
        );

        $this->assertDomainFailure(
            fn () => $lifecycle->retire(
                $compatibility,
                'Retiro bloqueado por dependencia activa.',
                'p5.7.2-test',
                CarbonImmutable::parse(
                    '2026-08-14 20:01:00',
                    'UTC'
                )
            )
        );

        $this->assertQueryFailure(
            fn () => DB::table(
                'financial_provider_compatibility_retirements'
            )->insert([
                'financial_provider_compatibility_id' =>
                    $compatibility->id,
                'reason' => 'Bypass directo',
                'srcm_version' => 'p5.7.2-test',
                'retired_at' => now(),
                'created_at' => now(),
            ])
        );

        $connectionManager = app(
            FinancialProviderConnectionManager::class
        );

        $connection = $connectionManager->toggleActive(
            $connection,
            $admin
        );

        $this->assertFalse($connection->active);

        $lifecycle->retire(
            $compatibility,
            'Retiro luego de desactivar dependencia.',
            'p5.7.2-test',
            CarbonImmutable::parse(
                '2026-08-14 20:02:00',
                'UTC'
            )
        );

        $this->assertDomainFailure(
            fn () => $connectionManager->toggleActive(
                $connection,
                $admin
            )
        );

        $this->assertFalse($connection->refresh()->active);
    }

    public function test_database_guards_provider_chain_and_immutability(): void
    {
        [, $admin] = $this->organizationWithUsers();
        $this->actingAs($admin);

        [$connection, $mercadoPago] =
            $this->mercadoPagoConnectionAndCompatibility($admin);

        [, $payway] = app(
            FinancialProviderCompatibilityRegistry::class
        )->seedReferenceRegistry();

        $bindings = app(
            FinancialProviderConnectionCompatibilityManager::class
        );

        $binding = $bindings->bind(
            $connection,
            $mercadoPago,
            $admin
        );

        $this->assertQueryFailure(
            fn () => DB::table(
                'financial_provider_connection_compatibility_bindings'
            )->insert([
                'financial_provider_connection_id' =>
                    $connection->id,
                'financial_provider_compatibility_id' =>
                    $payway->id,
                'previous_binding_id' => $binding->id,
                'bound_by_user_id' => $admin->id,
                'bound_at' => now(),
                'created_at' => now(),
            ])
        );

        $this->assertDomainFailure(function () use (
            $binding
        ): void {
            $binding->forceFill([
                'bound_at' => now()->addMinute(),
            ])->save();
        });

        $this->assertQueryFailure(
            fn () => DB::table(
                'financial_provider_connection_compatibility_bindings'
            )
                ->where('id', $binding->id)
                ->update([
                    'bound_at' => now()->addMinute(),
                ])
        );

        $connection = app(
            FinancialProviderConnectionManager::class
        )->toggleActive(
            $connection,
            $admin
        );

        $retirement = app(
            FinancialProviderCompatibilityLifecycleManager::class
        )->retire(
            $mercadoPago,
            'Retiro para probar inmutabilidad.',
            'p5.7.2-test',
            CarbonImmutable::parse(
                '2026-08-14 20:03:00',
                'UTC'
            )
        );

        $this->assertQueryFailure(
            fn () => DB::table(
                'financial_provider_compatibility_retirements'
            )
                ->where('id', $retirement->id)
                ->delete()
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
                'Mercado Pago P5.7.2',
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
            'mp-p572-'.Str::lower(Str::random(6))
        );

        return [$connection, $mercadoPago];
    }

    private function registerMercadoPagoV2(
    ): FinancialProviderCompatibility {
        return app(
            FinancialProviderCompatibilityRegistry::class
        )->register(
            registryKey:
                'mercado-pago:orders-v2:point-v2:p572test',
            providerKey: 'mercado-pago',
            providerLabel: 'Mercado Pago',
            providerContractVersion: 'orders-v2',
            providerContractReference:
                'Contrato V2 simulado para probar coexistencia y cutover.',
            adapterClass:
                'App\Adapters\Finance\MercadoPago\MercadoPagoExternalFinancialProviderAdapter',
            adapterContractVersion: 'point-v2',
            status:
                FinancialProviderCompatibilityStatus::Compatible,
            migrationRequired: false,
            srcmVersion: 'p5.7.2-test',
            verifiedAt: CarbonImmutable::parse(
                '2026-08-14 20:00:00',
                'UTC'
            ),
            capabilities: [
                [
                    'capability' =>
                        FinancialProviderCapability::Create,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' => true,
                    'evidence_reference' =>
                        'Harness contractual P5.7.2.',
                    'notes' => null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Read,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' => true,
                    'evidence_reference' =>
                        'Harness contractual P5.7.2.',
                    'notes' => null,
                ],
                [
                    'capability' =>
                        FinancialProviderCapability::Webhook,
                    'status' =>
                        FinancialProviderCompatibilityStatus::Compatible,
                    'required' => true,
                    'evidence_reference' =>
                        'Harness contractual P5.7.2.',
                    'notes' => null,
                ],
            ]
        );
    }

    /**
     * @return array{Organization, User, User}
     */
    private function organizationWithUsers(): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org P5.7.2 '.$suffix,
            'slug' => 'org-p572-'.$suffix,
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
