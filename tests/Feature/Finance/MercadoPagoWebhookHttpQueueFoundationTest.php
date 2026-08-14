<?php

namespace Tests\Feature\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoConnectionSecrets;
use App\Adapters\Finance\MercadoPago\MercadoPagoPointWebhookResolver;
use App\Adapters\Finance\MercadoPago\MercadoPagoWebhookRequestParser;
use App\Contracts\Finance\MercadoPagoConnectionSecretStore;
use App\Domain\Finance\ExternalFinancialProviderIngestor;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialProviderConnectionManager;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementSource;
use App\Enums\UserRole;
use App\Jobs\ProcessMercadoPagoPointWebhook;
use App\Models\FinancialProviderConnection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MercadoPagoWebhookHttpQueueFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_stateless_route_authenticates_raw_data_dot_id_and_acks_after_enqueue_without_provider_get(): void
    {
        [, $connection] = $this->mercadoPagoConnection();
        [$secret, $token] = $this->bindSecrets($connection);

        Queue::fake();
        Http::fake();

        $resourceId = 'ORD01HTTPQUEUE001';
        $requestId = 'req-http-queue-001';
        $timestamp = '1742505638683';

        $response = $this->postJson(
            '/api/webhooks/finance/mercado-pago/'
                .$connection->public_id
                .'?data.id='.$resourceId
                .'&type=order',
            $this->body($resourceId),
            [
                'x-signature' => $this->signature(
                    $secret,
                    $resourceId,
                    $requestId,
                    $timestamp
                ),
                'x-request-id' => $requestId,
            ]
        );

        $response->assertOk();
        $this->assertSame('', $response->getContent());

        Queue::assertPushed(
            ProcessMercadoPagoPointWebhook::class,
            fn (ProcessMercadoPagoPointWebhook $job): bool =>
                $job->connectionPublicId
                    === strtolower((string) $connection->public_id)
                && $job->resourceId === $resourceId
                && $job->notificationId === '987654321'
        );

        Http::assertNothingSent();

        $serialized = serialize(
            new ProcessMercadoPagoPointWebhook(
                strtolower((string) $connection->public_id),
                $resourceId,
                '987654321'
            )
        );

        $this->assertStringNotContainsString(
            $secret,
            $serialized
        );
        $this->assertStringNotContainsString(
            $token,
            $serialized
        );
        $this->assertStringNotContainsString(
            'cliente@example.com',
            $serialized
        );
    }

    public function test_invalid_signature_or_spoofed_identity_never_acks_or_enqueues(): void
    {
        [, $connection] = $this->mercadoPagoConnection();
        [$secret] = $this->bindSecrets($connection);

        Queue::fake();
        Http::fake();

        $resourceId = 'ORD01HTTPQUEUE002';
        $requestId = 'req-http-queue-002';

        $invalid = $this->postJson(
            '/api/webhooks/finance/mercado-pago/'
                .$connection->public_id
                .'?data.id='.$resourceId
                .'&type=order',
            $this->body($resourceId),
            [
                'x-signature' =>
                    'ts=1742505638683,v1='.str_repeat('0', 64),
                'x-request-id' => $requestId,
            ]
        );

        $invalid->assertUnauthorized();
        Queue::assertNothingPushed();
        Http::assertNothingSent();

        $spoofed = $this->body($resourceId);
        $spoofed['application_id'] = '999999';

        $response = $this->postJson(
            '/api/webhooks/finance/mercado-pago/'
                .$connection->public_id
                .'?data.id='.$resourceId
                .'&type=order',
            $spoofed,
            [
                'x-signature' => $this->signature(
                    $secret,
                    $resourceId,
                    $requestId,
                    '1742505638683'
                ),
                'x-request-id' => $requestId,
            ]
        );

        $response->assertUnauthorized();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_raw_query_parser_preserves_data_dot_id_and_rejects_ambiguity(): void
    {
        $parser = app(MercadoPagoWebhookRequestParser::class);

        $query = $parser->query(
            'data.id=ORD01DOTQUERY001&type=order'
        );

        $this->assertSame(
            'ORD01DOTQUERY001',
            $query['data.id']
        );
        $this->assertSame('order', $query['type']);

        $this->assertDomainFailure(
            fn () => $parser->query(
                'data.id=ORD1&data.id=ORD2&type=order'
            )
        );

        $this->assertDomainFailure(
            fn () => $parser->query(
                'data_id=ORD1&type=order'
            )
        );
    }

    public function test_job_fetches_canonical_order_and_ingests_idempotently_without_serialized_secrets(): void
    {
        [$account, $connection] = $this->mercadoPagoConnection();
        [, $token] = $this->bindSecrets($connection);

        Http::fake([
            'https://api.mercadopago.com/v1/orders/ORD01JOB001'
                => Http::response([
                    'id' => 'ORD01JOB001',
                    'type' => 'point',
                    'status' => 'processed',
                    'status_detail' => 'accredited',
                    'country_code' => 'ARG',
                    'version' => 7,
                    'last_updated_date' =>
                        '2026-08-13T23:50:00Z',
                    'transactions' => [
                        'payments' => [[
                            'paid_amount' => '24.00',
                            'status' => 'processed',
                        ]],
                    ],
                ], 200),
        ]);

        $job = new ProcessMercadoPagoPointWebhook(
            strtolower((string) $connection->public_id),
            'ORD01JOB001',
            '987654322'
        );

        $job->handle(
            app(MercadoPagoConnectionSecretStore::class),
            app(MercadoPagoPointWebhookResolver::class),
            app(ExternalFinancialProviderIngestor::class)
        );

        $job->handle(
            app(MercadoPagoConnectionSecretStore::class),
            app(MercadoPagoPointWebhookResolver::class),
            app(ExternalFinancialProviderIngestor::class)
        );

        $this->assertDatabaseCount(
            'financial_external_movements',
            1
        );

        $this->assertDatabaseHas(
            'financial_external_movements',
            [
                'financial_account_id' => $account->id,
                'source_key' =>
                    'mercado-pago:point-order:ORD01JOB001:processed:v7',
                'source' => FinancialMovementSource::Webhook->value,
                'external_operation_id' => 'ORD01JOB001',
                'gross_amount_minor' => 2400,
                'net_amount_minor' => 2400,
                'fee_amount_minor' => 0,
            ]
        );

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool =>
            $request->method() === 'GET'
            && $request->url()
                === 'https://api.mercadopago.com/v1/orders/ORD01JOB001'
            && $request->hasHeader(
                'Authorization',
                'Bearer '.$token
            )
        );
    }

    public function test_environment_secret_store_is_connection_scoped_and_never_echoes_secrets(): void
    {
        [, $connection] = $this->mercadoPagoConnection();

        $variable =
            \App\Adapters\Finance\MercadoPago\EnvironmentMercadoPagoConnectionSecretStore::ENVIRONMENT_KEY;

        $oldServer = $_SERVER[$variable] ?? null;
        $oldEnv = $_ENV[$variable] ?? null;

        try {
            $secret = 'FAKE_P5_5_ENV_SECRET';
            $token = 'FAKE_P5_5_ENV_TOKEN';

            $_SERVER[$variable] = json_encode([
                strtolower((string) $connection->public_id) => [
                    'webhook_secret' => $secret,
                    'access_token' => $token,
                    'application_id' => '123456',
                    'user_id' => '654321',
                    'live_mode' => false,
                ],
            ], JSON_THROW_ON_ERROR);

            unset($_ENV[$variable]);

            $store = new \App\Adapters\Finance\MercadoPago\EnvironmentMercadoPagoConnectionSecretStore;

            $resolved = $store->forConnection($connection);

            $this->assertSame($secret, $resolved->webhookSecret);
            $this->assertSame($token, $resolved->accessToken);
            $this->assertSame('123456', $resolved->applicationId);
            $this->assertSame('654321', $resolved->userId);
            $this->assertFalse($resolved->liveMode);

            $_SERVER[$variable] = '{"bad":true}';

            try {
                $store->forConnection($connection);
                $this->fail('Se esperaba DomainException.');
            } catch (DomainException $exception) {
                $this->assertStringNotContainsString(
                    $secret,
                    $exception->getMessage()
                );
                $this->assertStringNotContainsString(
                    $token,
                    $exception->getMessage()
                );
            }
        } finally {
            if ($oldServer === null) {
                unset($_SERVER[$variable]);
            } else {
                $_SERVER[$variable] = $oldServer;
            }

            if ($oldEnv === null) {
                unset($_ENV[$variable]);
            } else {
                $_ENV[$variable] = $oldEnv;
            }
        }
    }

    /**
     * @return array{0:\App\Models\FinancialAccount,1:FinancialProviderConnection}
     */
    private function mercadoPagoConnection(): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org P5.5 '.$suffix,
            'slug' => 'org-p55-'.$suffix,
            'active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'role' => UserRole::Admin,
            'active' => true,
        ]);

        $admin->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        app(CurrentOrganization::class)->forget($admin);

        $this->actingAs($admin);

        $account = app(FinancialAccountManager::class)->create(
            'Mercado Pago P5.5',
            FinancialAccountType::DigitalWallet,
            'ARS',
            $admin,
            'Mercado Pago',
            'Webhook P5.5'
        );

        $connection = app(
            FinancialProviderConnectionManager::class
        )->connect(
            $account,
            'mercado-pago',
            $admin,
            '654321'
        );

        auth()->logout();
        app(CurrentOrganization::class)->forget($admin);

        return [$account, $connection->refresh()];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function bindSecrets(
        FinancialProviderConnection $connection
    ): array {
        $secret = 'P5_5_TEST_WEBHOOK_SECRET';
        $token = 'P5_5_TEST_ACCESS_TOKEN';

        $store = new class(
            strtolower((string) $connection->public_id),
            $secret,
            $token
        ) implements MercadoPagoConnectionSecretStore {
            public function __construct(
                private readonly string $publicId,
                private readonly string $secret,
                private readonly string $token
            ) {
            }

            public function forConnection(
                FinancialProviderConnection $connection
            ): MercadoPagoConnectionSecrets {
                if (
                    strtolower((string) $connection->public_id)
                        !== $this->publicId
                ) {
                    throw new DomainException(
                        'Conexión de prueba inesperada.'
                    );
                }

                return new MercadoPagoConnectionSecrets(
                    webhookSecret: $this->secret,
                    accessToken: $this->token,
                    applicationId: '123456',
                    userId: '654321',
                    liveMode: false
                );
            }
        };

        $this->app->instance(
            MercadoPagoConnectionSecretStore::class,
            $store
        );

        return [$secret, $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $resourceId): array
    {
        return [
            'action' => 'order.processed',
            'api_version' => 'v1',
            'application_id' => '123456',
            'date_created' => '2026-08-13T23:49:59Z',
            'id' => '987654321',
            'live_mode' => false,
            'type' => 'order',
            'user_id' => '654321',
            'data' => [
                'id' => $resourceId,
                'payer' => [
                    'email' => 'cliente@example.com',
                ],
            ],
        ];
    }

    private function signature(
        string $secret,
        string $dataId,
        string $requestId,
        string $timestamp
    ): string {
        $signedId = ctype_alnum($dataId)
            ? strtolower($dataId)
            : $dataId;

        $manifest = 'id:'.$signedId
            .';request-id:'.$requestId
            .';ts:'.$timestamp
            .';';

        return 'ts='.$timestamp
            .',v1='
            .hash_hmac('sha256', $manifest, $secret);
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba DomainException.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
