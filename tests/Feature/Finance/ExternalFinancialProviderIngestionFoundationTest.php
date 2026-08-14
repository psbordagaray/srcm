<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\ExternalFinancialProviderIngestor;
use App\Domain\Finance\ExternalFinancialProviderObservation;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialProviderConnectionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use App\Enums\UserRole;
use App\Models\FinancialProviderConnection;
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

class ExternalFinancialProviderIngestionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_connection_schema_and_admin_contract_are_explicit(): void
    {
        [$organization, $admin, $operator] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = app(FinancialAccountManager::class)->create(
            'Mercado Pago principal',
            FinancialAccountType::DigitalWallet,
            'ARS',
            $admin,
            'Mercado Pago',
            'Cuenta recaudadora'
        );

        $manager = app(
            FinancialProviderConnectionManager::class
        );

        $connection = $manager->connect(
            $account,
            'Mercado Pago',
            $admin,
            'mp-user-100'
        );

        $retry = $manager->connect(
            $account,
            'mercado-pago',
            $admin,
            'mp-user-100'
        );

        $this->assertSame($connection->id, $retry->id);
        $this->assertSame(
            $organization->id,
            $connection->organization_id
        );
        $this->assertSame(
            $account->id,
            $connection->financial_account_id
        );
        $this->assertSame(
            'mercado-pago',
            $connection->provider_key
        );
        $this->assertSame(
            'mp-user-100',
            $connection->external_account_id
        );
        $this->assertTrue($connection->active);

        $this->assertTrue(
            Schema::hasColumns(
                'financial_provider_connections',
                [
                    'organization_id',
                    'financial_account_id',
                    'public_id',
                    'provider_key',
                    'external_account_id',
                    'active',
                    'created_by_user_id',
                    'updated_by_user_id',
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
                    'financial_provider_connections',
                    $secretColumn
                )
            );
        }

        $this->assertDatabaseCount(
            'financial_provider_connections',
            1
        );
        $this->assertDatabaseHas(
            'audit_logs',
            [
                'organization_id' => $organization->id,
                'event' =>
                    'financial_provider_connection_created',
                'auditable_id' => (string) $connection->id,
                'user_id' => $admin->id,
            ]
        );

        $this->actingAs($operator);

        $this->assertDomainFailure(
            fn () => $manager->connect(
                $account,
                'mercado-pago',
                $operator,
                'mp-user-100'
            )
        );
    }

    public function test_connection_rejects_cash_and_provider_mismatch(): void
    {
        [, $admin] = $this->organizationWithUsers();
        $this->actingAs($admin);

        $accounts = app(FinancialAccountManager::class);
        $connections = app(
            FinancialProviderConnectionManager::class
        );

        $cash = $accounts->create(
            'Caja externa inválida',
            FinancialAccountType::CashBox,
            'ARS',
            $admin,
            'Mercado Pago'
        );

        $this->assertDomainFailure(
            fn () => $connections->connect(
                $cash,
                'mercado-pago',
                $admin
            )
        );

        $payway = $accounts->create(
            'Payway principal',
            FinancialAccountType::CardProcessor,
            'ARS',
            $admin,
            'Payway'
        );

        $this->assertDomainFailure(
            fn () => $connections->connect(
                $payway,
                'mercado-pago',
                $admin
            )
        );

        $this->assertDatabaseCount(
            'financial_provider_connections',
            0
        );
    }

    public function test_connection_identity_and_linked_account_are_guarded(): void
    {
        [, $admin] = $this->organizationWithUsers();
        $this->actingAs($admin);

        $accounts = app(FinancialAccountManager::class);
        $connections = app(
            FinancialProviderConnectionManager::class
        );

        $account = $accounts->create(
            'Mercado Pago guard',
            FinancialAccountType::DigitalWallet,
            'ARS',
            $admin,
            'Mercado Pago'
        );

        $connection = $connections->connect(
            $account,
            'mercado-pago',
            $admin,
            'mp-guard'
        );

        $this->assertQueryFailure(
            fn () => DB::table(
                'financial_provider_connections'
            )
                ->where('id', $connection->id)
                ->update(['provider_key' => 'payway'])
        );

        $this->assertQueryFailure(
            fn () => DB::table(
                'financial_provider_connections'
            )
                ->where('id', $connection->id)
                ->delete()
        );

        $this->assertDomainFailure(
            fn () => $accounts->update(
                $account,
                $account->name,
                FinancialAccountType::DigitalWallet,
                'ARS',
                $admin,
                'Payway',
                $account->external_label
            )
        );

        $this->assertQueryFailure(
            fn () => DB::table('financial_accounts')
                ->where('id', $account->id)
                ->update(['provider' => 'Payway'])
        );

        $this->assertDomainFailure(
            fn () => $accounts->toggleActive(
                $account,
                $admin
            )
        );

        $connection = $connections->toggleActive(
            $connection,
            $admin
        );

        $this->assertFalse($connection->active);

        $account = $accounts->toggleActive(
            $account,
            $admin
        );

        $this->assertFalse($account->active);

        $this->assertDomainFailure(
            fn () => $connections->toggleActive(
                $connection,
                $admin
            )
        );
    }

    public function test_automated_ingestion_deduplicates_webhook_and_polling_without_human_actor(): void
    {
        [$organization, $admin] =
            $this->organizationWithUsers();

        $this->actingAs($admin);

        $account = app(FinancialAccountManager::class)
            ->create(
                'Mercado Pago P5 ingest',
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
            'mp-ingest'
        );

        auth()->logout();
        app(CurrentOrganization::class)->forget($admin);

        $ingestor = app(
            ExternalFinancialProviderIngestor::class
        );

        $occurredAt = CarbonImmutable::parse(
            '2026-08-13 20:00:00',
            'America/Argentina/Buenos_Aires'
        );

        $webhook = new ExternalFinancialProviderObservation(
            providerKey: 'mercado-pago',
            observationKey:
                'payment:83927461:posted:webhook-event-1',
            externalOperationId: '83927461',
            direction:
                FinancialMovementDirection::Credit,
            status:
                FinancialMovementStatus::Posted,
            currencyCode: 'ARS',
            grossAmountMinor: 6000000,
            netAmountMinor: 5682000,
            feeAmountMinor: 318000,
            rawReference:
                'Pago MP 83927461 aprobado',
            occurredAt: $occurredAt
        );

        $first = $ingestor->ingest(
            $connection,
            FinancialMovementSource::Webhook,
            $webhook
        );

        $retry = $ingestor->ingest(
            $connection,
            FinancialMovementSource::Webhook,
            $webhook
        );

        $polling = new ExternalFinancialProviderObservation(
            providerKey: 'Mercado Pago',
            observationKey:
                'payment:83927461:posted:poll-20260813',
            externalOperationId: '83927461',
            direction:
                FinancialMovementDirection::Credit,
            status:
                FinancialMovementStatus::Posted,
            currencyCode: 'ARS',
            grossAmountMinor: 6000000,
            netAmountMinor: 5682000,
            feeAmountMinor: 318000,
            rawReference:
                'Consulta API de la misma operación',
            occurredAt: $occurredAt->addMinute()
        );

        $crossChannel = $ingestor->ingest(
            $connection,
            FinancialMovementSource::Polling,
            $polling
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(
            $first->id,
            $crossChannel->id
        );
        $this->assertSame(
            $organization->id,
            $first->organization_id
        );
        $this->assertSame(
            $account->id,
            $first->financial_account_id
        );
        $this->assertSame(
            FinancialMovementSource::Webhook,
            $first->source
        );
        $this->assertSame(
            '83927461',
            $first->external_operation_id
        );
        $this->assertSame(
            6000000,
            $first->gross_amount_minor
        );
        $this->assertSame(
            318000,
            $first->fee_amount_minor
        );
        $this->assertSame(
            5682000,
            $first->net_amount_minor
        );
        $this->assertNull($first->created_by_user_id);
        $this->assertDatabaseCount(
            'financial_external_movements',
            1
        );

        $audit = DB::table('audit_logs')
            ->where(
                'event',
                'financial_external_movement_recorded'
            )
            ->where(
                'auditable_id',
                (string) $first->id
            )
            ->sole();

        $this->assertNull($audit->user_id);
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where(
                    'event',
                    'financial_external_movement_recorded'
                )
                ->where(
                    'auditable_id',
                    (string) $first->id
                )
                ->count()
        );
    }

    public function test_status_transitions_append_and_same_state_conflicts_fail_closed(): void
    {
        [, $admin] = $this->organizationWithUsers();
        $this->actingAs($admin);

        $account = app(FinancialAccountManager::class)
            ->create(
                'Payway lifecycle',
                FinancialAccountType::CardProcessor,
                'ARS',
                $admin,
                'Payway'
            );

        $connection = app(
            FinancialProviderConnectionManager::class
        )->connect(
            $account,
            'payway',
            $admin,
            'payway-merchant-1'
        );

        auth()->logout();
        app(CurrentOrganization::class)->forget($admin);

        $ingestor = app(
            ExternalFinancialProviderIngestor::class
        );

        $pending = $ingestor->ingest(
            $connection,
            FinancialMovementSource::Api,
            new ExternalFinancialProviderObservation(
                providerKey: 'payway',
                observationKey:
                    'payment:op-1001:pending',
                externalOperationId: 'op-1001',
                direction:
                    FinancialMovementDirection::Credit,
                status:
                    FinancialMovementStatus::Pending,
                currencyCode: 'ARS',
                grossAmountMinor: 1000000,
                netAmountMinor: 1000000
            )
        );

        $posted = $ingestor->ingest(
            $connection,
            FinancialMovementSource::Webhook,
            new ExternalFinancialProviderObservation(
                providerKey: 'payway',
                observationKey:
                    'payment:op-1001:posted',
                externalOperationId: 'op-1001',
                direction:
                    FinancialMovementDirection::Credit,
                status:
                    FinancialMovementStatus::Posted,
                currencyCode: 'ARS',
                grossAmountMinor: 1000000,
                netAmountMinor: 950000,
                feeAmountMinor: 50000
            )
        );

        $postedRetry = $ingestor->ingest(
            $connection,
            FinancialMovementSource::Polling,
            new ExternalFinancialProviderObservation(
                providerKey: 'payway',
                observationKey:
                    'payment:op-1001:posted:poll',
                externalOperationId: 'op-1001',
                direction:
                    FinancialMovementDirection::Credit,
                status:
                    FinancialMovementStatus::Posted,
                currencyCode: 'ARS',
                grossAmountMinor: 1000000,
                netAmountMinor: 950000,
                feeAmountMinor: 50000
            )
        );

        $this->assertNotSame($pending->id, $posted->id);
        $this->assertSame(
            $posted->id,
            $postedRetry->id
        );
        $this->assertDatabaseCount(
            'financial_external_movements',
            2
        );

        $this->assertDomainFailure(
            fn () => $ingestor->ingest(
                $connection,
                FinancialMovementSource::Polling,
                new ExternalFinancialProviderObservation(
                    providerKey: 'payway',
                    observationKey:
                        'payment:op-1001:posted:conflict',
                    externalOperationId: 'op-1001',
                    direction:
                        FinancialMovementDirection::Credit,
                    status:
                        FinancialMovementStatus::Posted,
                    currencyCode: 'ARS',
                    grossAmountMinor: 1100000,
                    netAmountMinor: 1050000,
                    feeAmountMinor: 50000
                )
            )
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            2
        );
    }

    public function test_provider_identity_and_operation_scope_are_explicit(): void
    {
        [, $admin] = $this->organizationWithUsers();
        $this->actingAs($admin);

        $accounts = app(FinancialAccountManager::class);
        $connections = app(
            FinancialProviderConnectionManager::class
        );

        $firstAccount = $accounts->create(
            'Mercado Pago comercio A',
            FinancialAccountType::DigitalWallet,
            'ARS',
            $admin,
            'Mercado Pago'
        );

        $secondAccount = $accounts->create(
            'Mercado Pago comercio B',
            FinancialAccountType::DigitalWallet,
            'ARS',
            $admin,
            'Mercado Pago'
        );

        $firstConnection = $connections->connect(
            $firstAccount,
            'mercado-pago',
            $admin,
            'mp-account-a'
        );

        $this->assertDomainFailure(
            fn () => $connections->connect(
                $secondAccount,
                'mercado-pago',
                $admin,
                'mp-account-a'
            )
        );

        $secondConnection = $connections->connect(
            $secondAccount,
            'mercado-pago',
            $admin,
            'mp-account-b'
        );

        auth()->logout();
        app(CurrentOrganization::class)->forget($admin);

        $ingestor = app(
            ExternalFinancialProviderIngestor::class
        );

        $operation = fn (string $key) =>
            new ExternalFinancialProviderObservation(
                providerKey: 'mercado-pago',
                observationKey: $key,
                externalOperationId: 'same-provider-op',
                direction:
                    FinancialMovementDirection::Credit,
                status:
                    FinancialMovementStatus::Posted,
                currencyCode: 'ARS',
                grossAmountMinor: 250000,
                netAmountMinor: 250000
            );

        $first = $ingestor->ingest(
            $firstConnection,
            FinancialMovementSource::Webhook,
            $operation('account-a:event-1')
        );

        $second = $ingestor->ingest(
            $secondConnection,
            FinancialMovementSource::Webhook,
            $operation('account-b:event-1')
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(
            $firstAccount->id,
            $first->financial_account_id
        );
        $this->assertSame(
            $secondAccount->id,
            $second->financial_account_id
        );
        $this->assertDatabaseCount(
            'financial_external_movements',
            2
        );

        $this->assertDomainFailure(
            fn () => $ingestor->ingest(
                $firstConnection,
                FinancialMovementSource::Webhook,
                new ExternalFinancialProviderObservation(
                    providerKey: 'payway',
                    observationKey: 'wrong-provider',
                    externalOperationId: 'wrong-provider',
                    direction:
                        FinancialMovementDirection::Credit,
                    status:
                        FinancialMovementStatus::Posted,
                    currencyCode: 'ARS',
                    grossAmountMinor: 10000,
                    netAmountMinor: 10000
                )
            )
        );

        $this->actingAs($admin);

        $firstConnection = $connections->toggleActive(
            $firstConnection,
            $admin
        );

        auth()->logout();

        $this->assertDomainFailure(
            fn () => $ingestor->ingest(
                $firstConnection,
                FinancialMovementSource::Polling,
                $operation('disabled:event')
            )
        );

        $this->assertDomainFailure(
            fn () => $ingestor->ingest(
                $secondConnection,
                FinancialMovementSource::Csv,
                $operation('csv:not-p5')
            )
        );
    }

    /**
     * @return array{Organization, User, User}
     */
    private function organizationWithUsers(): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org P5 '.$suffix,
            'slug' => 'org-p5-'.$suffix,
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
