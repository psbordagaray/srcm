<?php

namespace Tests\Feature\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoConnectionSecrets;
use App\Adapters\Finance\MercadoPago\MercadoPagoReadOnlyConnectionHealthProbe;
use App\Contracts\Finance\MercadoPagoConnectionSecretStore;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Finance\FinancialProviderAutomationGate;
use App\Domain\Finance\FinancialProviderCompatibilityRegistry;
use App\Domain\Finance\FinancialProviderConnectionCompatibilityManager;
use App\Domain\Finance\FinancialProviderConnectionManager;
use App\Domain\Finance\FinancialProviderHealthProbeRegistry;
use App\Domain\Finance\FinancialProviderHealthProbeRunner;
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

class FinancialProviderReadOnlyHealthProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_mercado_pago_probe_is_get_only_authenticated_and_identity_checked(): void
    {
        [, $admin] = $this->organizationWithAdmin();
        $this->actingAs($admin);

        [$connection] = $this->mercadoPagoConnection($admin);
        $secrets = $this->fakeSecrets();

        $this->bindSecretStore($secrets);

        Http::fake([
            'https://api.mercadolibre.com/users/me' =>
                Http::response([
                    'id' => (int) $secrets->userId,
                    'nickname' => 'raw-provider-field',
                ], 200),
        ]);

        $observation = app(
            MercadoPagoReadOnlyConnectionHealthProbe::class
        )->probe($connection);

        $this->assertSame(
            FinancialProviderConnectionHealthStatus::Healthy,
            $observation->status
        );
        $this->assertSame('ok', $observation->diagnosticCode);
        $this->assertSame(
            'mercado-pago:users-me',
            $observation->sourceKey
        );

        Http::assertSent(
            function (ClientRequest $request) use (
                $secrets
            ): bool {
                return $request->method() === 'GET'
                    && $request->url()
                        === 'https://api.mercadolibre.com/users/me'
                    && $request->hasHeader(
                        'Authorization',
                        'Bearer '.$secrets->accessToken
                    )
                    && ! str_contains(
                        $request->url(),
                        $secrets->accessToken
                    );
            }
        );
    }

    public function test_probe_maps_provider_failures_to_safe_codes_without_raw_body(): void
    {
        [, $admin] = $this->organizationWithAdmin();
        $this->actingAs($admin);

        [$connection] = $this->mercadoPagoConnection($admin);
        $secrets = $this->fakeSecrets();

        $this->bindSecretStore($secrets);

        $cases = [
            [
                401,
                ['message' => 'provider-secret-message'],
                FinancialProviderConnectionHealthStatus::Unavailable,
                'authentication_failed',
            ],
            [
                429,
                ['message' => 'provider-secret-message'],
                FinancialProviderConnectionHealthStatus::Degraded,
                'rate_limited',
            ],
            [
                503,
                ['message' => 'provider-secret-message'],
                FinancialProviderConnectionHealthStatus::Unavailable,
                'provider_unavailable',
            ],
            [
                200,
                ['unexpected' => 'provider-secret-message'],
                FinancialProviderConnectionHealthStatus::Degraded,
                'invalid_provider_response',
            ],
        ];

        Http::fake([
            'https://api.mercadolibre.com/users/me' =>
                Http::sequence()
                    ->push($cases[0][1], $cases[0][0])
                    ->push($cases[1][1], $cases[1][0])
                    ->push($cases[2][1], $cases[2][0])
                    ->push($cases[3][1], $cases[3][0]),
        ]);

        foreach (
            $cases as [
                $status,
                $body,
                $expectedHealth,
                $expectedCode,
            ]
        ) {
            $observation = app(
                MercadoPagoReadOnlyConnectionHealthProbe::class
            )->probe($connection);

            $this->assertSame(
                $expectedHealth,
                $observation->status
            );
            $this->assertSame(
                $expectedCode,
                $observation->diagnosticCode
            );
            $this->assertStringNotContainsString(
                'provider-secret-message',
                json_encode([
                    $observation->sourceKey,
                    $observation->diagnosticCode,
                ], JSON_THROW_ON_ERROR)
            );
        }
    }

    public function test_runner_records_current_binding_health_and_enables_only_read_automation(): void
    {
        [, $admin] = $this->organizationWithAdmin();
        $this->actingAs($admin);

        [$connection, $compatibility] =
            $this->mercadoPagoConnection($admin);

        $binding = app(
            FinancialProviderConnectionCompatibilityManager::class
        )->bind(
            $connection,
            $compatibility,
            $admin
        );

        $secrets = $this->fakeSecrets();
        $this->bindSecretStore($secrets);

        Http::fake([
            'https://api.mercadolibre.com/users/me' =>
                Http::response([
                    'id' => (int) $secrets->userId,
                    'email' => 'must-not-persist@example.test',
                ], 200),
        ]);

        $check = app(
            FinancialProviderHealthProbeRunner::class
        )->run(
            $connection,
            FinancialProviderCapability::Read
        );

        $this->assertSame(
            $binding->id,
            $check
                ->financial_provider_connection_compatibility_binding_id
        );
        $this->assertSame(
            FinancialProviderConnectionHealthStatus::Healthy,
            $check->health_status
        );
        $this->assertTrue(
            app(FinancialProviderAutomationGate::class)
                ->evaluate(
                    $connection,
                    FinancialProviderCapability::Read
                )
                ->allowed
        );

        $row = DB::table(
            'financial_provider_connection_health_checks'
        )->where('id', $check->id)->first();

        $serialized = json_encode(
            $row,
            JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString(
            $secrets->accessToken,
            $serialized
        );
        $this->assertStringNotContainsString(
            $secrets->webhookSecret,
            $serialized
        );
        $this->assertStringNotContainsString(
            'must-not-persist@example.test',
            $serialized
        );
    }

    public function test_missing_credentials_becomes_safe_unavailable_health(): void
    {
        [, $admin] = $this->organizationWithAdmin();
        $this->actingAs($admin);

        [$connection] = $this->mercadoPagoConnection($admin);

        $this->app->instance(
            MercadoPagoConnectionSecretStore::class,
            new class implements MercadoPagoConnectionSecretStore {
                public function forConnection(
                    FinancialProviderConnection $connection
                ): MercadoPagoConnectionSecrets {
                    throw new DomainException(
                        'raw secret configuration detail'
                    );
                }
            }
        );

        $observation = app(
            MercadoPagoReadOnlyConnectionHealthProbe::class
        )->probe($connection);

        $this->assertSame(
            FinancialProviderConnectionHealthStatus::Unavailable,
            $observation->status
        );
        $this->assertSame(
            'credentials_unavailable',
            $observation->diagnosticCode
        );

        Http::assertNothingSent();
    }

    public function test_unsupported_provider_fails_before_network(): void
    {
        [, $admin] = $this->organizationWithAdmin();
        $this->actingAs($admin);

        $account = app(FinancialAccountManager::class)
            ->create(
                'Payway probe guard',
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
            'payway-p582'
        );

        try {
            app(FinancialProviderHealthProbeRegistry::class)
                ->resolve(
                    $connection,
                    FinancialProviderCapability::Read
                );

            $this->fail(
                'Payway no debe resolver un probe no implementado.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        Http::assertNothingSent();
    }

    private function mercadoPagoConnection(User $admin): array
    {
        [$mercadoPago] = app(
            FinancialProviderCompatibilityRegistry::class
        )->seedReferenceRegistry();

        $account = app(FinancialAccountManager::class)
            ->create(
                'Mercado Pago P5.8.2',
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

        return [$connection, $mercadoPago];
    }

    private function fakeSecrets(): MercadoPagoConnectionSecrets
    {
        return new MercadoPagoConnectionSecrets(
            webhookSecret: 'whsec-p582-safe-test',
            accessToken: 'test-token-p582-never-persist',
            applicationId: '987654321',
            userId: '123456789',
            liveMode: false
        );
    }

    private function bindSecretStore(
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
    private function organizationWithAdmin(): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org P5.8.2 '.$suffix,
            'slug' => 'org-p582-'.$suffix,
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

        return [
            $organization,
            $admin->refresh(),
        ];
    }
}
