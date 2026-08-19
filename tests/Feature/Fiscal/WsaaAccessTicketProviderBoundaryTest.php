<?php

namespace Tests\Feature\Fiscal;

use App\Adapters\Fiscal\Arca\EncryptedCacheWsaaAccessTicketProvider;
use App\Adapters\Fiscal\Arca\OfficialWsaaCmsDigestPolicy;
use App\Adapters\Fiscal\Arca\RandomWsaaTraUniqueIdProvider;
use App\Adapters\Fiscal\Arca\SystemWsaaTraClock;
use App\Domain\Fiscal\WsaaAccessTicket;
use App\Domain\Fiscal\WsaaAccessTicketProvider;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsaaCmsDigestPolicy;
use App\Domain\Fiscal\WsaaCmsSigner;
use App\Domain\Fiscal\WsaaCredentialMaterial;
use App\Domain\Fiscal\WsaaCredentialMaterialProvider;
use App\Domain\Fiscal\WsaaLoginCmsTransport;
use App\Domain\Fiscal\WsaaSignedCms;
use App\Domain\Fiscal\WsaaTra;
use App\Domain\Fiscal\WsaaTraBuilder;
use App\Domain\Fiscal\WsaaTraClock;
use App\Domain\Fiscal\WsaaTraUniqueIdProvider;
use App\Enums\FiscalEnvironment;
use App\Enums\WsaaCmsDigestAlgorithm;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Encryption\Encrypter;
use RuntimeException;
use Tests\TestCase;

class WsaaAccessTicketProviderBoundaryTest extends TestCase
{
    public function test_container_binds_complete_provider_orchestration_without_enabling_production(): void
    {
        $this->assertInstanceOf(
            EncryptedCacheWsaaAccessTicketProvider::class,
            app(WsaaAccessTicketProvider::class)
        );
        $this->assertInstanceOf(
            OfficialWsaaCmsDigestPolicy::class,
            app(WsaaCmsDigestPolicy::class)
        );
        $this->assertInstanceOf(
            SystemWsaaTraClock::class,
            app(WsaaTraClock::class)
        );
        $this->assertInstanceOf(
            RandomWsaaTraUniqueIdProvider::class,
            app(WsaaTraUniqueIdProvider::class)
        );
        $this->assertTrue(app()->bound(WsaaTraBuilder::class));
    }

    public function test_official_digest_policy_is_sha1_in_homologation_and_production_fails_closed(): void
    {
        $policy = new OfficialWsaaCmsDigestPolicy;

        $this->assertSame(
            WsaaCmsDigestAlgorithm::Sha1,
            $policy->forEnvironment(FiscalEnvironment::Homologation)
        );

        $this->expectException(DomainException::class);
        $policy->forEnvironment(FiscalEnvironment::Production);
    }

    public function test_valid_ticket_is_encrypted_cached_and_reused_until_true_expiration(): void
    {
        $clock = new MutableWsaaTraClock(
            CarbonImmutable::parse('2026-08-19T18:00:00Z')
        );
        $cache = new Repository(new ArrayStore);
        $transport = new QueueWsaaLoginCmsTransport([
            $this->ticket(
                expiration: '2026-08-20T06:00:00Z',
                token: 'secret-token-a',
                sign: 'secret-sign-a'
            ),
        ]);
        $signer = new CountingWsaaCmsSigner;
        $provider = $this->provider(
            $cache,
            $clock,
            $transport,
            $signer
        );
        $request = $this->request();

        $first = $provider->ticketFor($request);
        $second = $provider->ticketFor($request);

        $this->assertSame('secret-token-a', $first->token());
        $this->assertSame('secret-token-a', $second->token());
        $this->assertSame(1, $transport->calls);
        $this->assertSame(1, $signer->calls);

        $key = $this->cacheKey($request);
        $ciphertext = $cache->get($key);

        $this->assertIsString($ciphertext);
        $this->assertStringNotContainsString('secret-token-a', $ciphertext);
        $this->assertStringNotContainsString('secret-sign-a', $ciphertext);
        $this->assertStringNotContainsString('20123456786', $key);
        $this->assertMatchesRegularExpression(
            '/^srcm:wsaa:ta:v1:[a-f0-9]{64}$/D',
            $key
        );
    }

    public function test_expired_ticket_is_not_refreshed_early_but_is_replaced_after_true_expiration(): void
    {
        $clock = new MutableWsaaTraClock(
            CarbonImmutable::parse('2026-08-19T18:00:00Z')
        );
        $transport = new QueueWsaaLoginCmsTransport([
            $this->ticket(
                expiration: '2026-08-19T18:01:00Z',
                token: 'token-one',
                sign: 'sign-one'
            ),
            $this->ticket(
                generation: '2026-08-19T18:02:00Z',
                expiration: '2026-08-20T06:02:00Z',
                token: 'token-two',
                sign: 'sign-two'
            ),
        ]);
        $provider = $this->provider(
            new Repository(new ArrayStore),
            $clock,
            $transport,
            new CountingWsaaCmsSigner
        );

        $this->assertSame(
            'token-one',
            $provider->ticketFor($this->request())->token()
        );

        $clock->at = CarbonImmutable::parse('2026-08-19T18:00:59Z');
        $this->assertSame(
            'token-one',
            $provider->ticketFor($this->request())->token()
        );
        $this->assertSame(1, $transport->calls);

        $clock->at = CarbonImmutable::parse('2026-08-19T18:02:00Z');
        $this->assertSame(
            'token-two',
            $provider->ticketFor($this->request())->token()
        );
        $this->assertSame(2, $transport->calls);
    }

    public function test_corrupted_cache_fails_closed_before_signing_or_login_cms(): void
    {
        $clock = new MutableWsaaTraClock(
            CarbonImmutable::parse('2026-08-19T18:00:00Z')
        );
        $cache = new Repository(new ArrayStore);
        $transport = new QueueWsaaLoginCmsTransport([
            $this->ticket(),
        ]);
        $signer = new CountingWsaaCmsSigner;
        $provider = $this->provider(
            $cache,
            $clock,
            $transport,
            $signer
        );
        $request = $this->request();
        $key = $this->cacheKey($request);
        $cache->put($key, 'not-a-valid-ciphertext', 3600);

        try {
            $provider->ticketFor($request);
            $this->fail('Cache corrupto debía fallar cerrado.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'no pudo descifrarse o validarse',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $transport->calls);
        $this->assertSame(0, $signer->calls);
    }

    public function test_production_fails_before_cache_signing_or_transport(): void
    {
        $cache = new RecordingRepository(new ArrayStore);
        $transport = new QueueWsaaLoginCmsTransport([]);
        $signer = new CountingWsaaCmsSigner;
        $provider = $this->provider(
            $cache,
            new MutableWsaaTraClock(
                CarbonImmutable::parse('2026-08-19T18:00:00Z')
            ),
            $transport,
            $signer
        );

        try {
            $provider->ticketFor(
                new WsaaAccessTicketRequest(
                    7,
                    FiscalEnvironment::Production,
                    'wsfe',
                    '20123456786'
                )
            );
            $this->fail('Producción debía permanecer bloqueada.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, $cache->gets);
        $this->assertSame(0, $transport->calls);
        $this->assertSame(0, $signer->calls);
    }

    public function test_cache_write_failure_after_new_ticket_fails_closed_without_retry(): void
    {
        $transport = new QueueWsaaLoginCmsTransport([
            $this->ticket(),
        ]);
        $provider = $this->provider(
            new FailingPutRepository(new ArrayStore),
            new MutableWsaaTraClock(
                CarbonImmutable::parse('2026-08-19T18:00:00Z')
            ),
            $transport,
            new CountingWsaaCmsSigner
        );

        $this->expectException(RuntimeException::class);

        try {
            $provider->ticketFor($this->request());
        } finally {
            $this->assertSame(1, $transport->calls);
        }
    }

    public function test_provider_source_has_no_retry_loop_plaintext_cache_or_sha256_digest_default(): void
    {
        $source = file_get_contents(
            app_path(
                'Adapters/Fiscal/Arca/EncryptedCacheWsaaAccessTicketProvider.php'
            )
        );
        $policy = file_get_contents(
            app_path(
                'Adapters/Fiscal/Arca/OfficialWsaaCmsDigestPolicy.php'
            )
        );

        $this->assertIsString($source);
        $this->assertIsString($policy);
        $this->assertStringContainsString('decrypt(', $source);
        $this->assertStringContainsString('encrypt(', $source);
        $this->assertStringNotContainsString('serialize(', $source);
        $this->assertStringNotContainsString('unserialize(', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('WsaaCmsDigestAlgorithm::Sha256', $policy);
    }

    private function provider(
        Repository $cache,
        MutableWsaaTraClock $clock,
        QueueWsaaLoginCmsTransport $transport,
        CountingWsaaCmsSigner $signer
    ): EncryptedCacheWsaaAccessTicketProvider {
        return new EncryptedCacheWsaaAccessTicketProvider(
            $cache,
            new Encrypter(str_repeat('K', 32), 'AES-256-CBC'),
            $clock,
            new SyntheticWsaaTraBuilder($clock),
            new SyntheticWsaaCredentialMaterialProvider,
            new OfficialWsaaCmsDigestPolicy,
            $signer,
            $transport,
        );
    }

    private function request(): WsaaAccessTicketRequest
    {
        return new WsaaAccessTicketRequest(
            7,
            FiscalEnvironment::Homologation,
            'wsfe',
            '20123456786'
        );
    }

    private function ticket(
        string $generation = '2026-08-19T17:59:00Z',
        string $expiration = '2026-08-20T06:00:00Z',
        string $token = 'secret-token',
        string $sign = 'secret-sign'
    ): WsaaAccessTicket {
        return new WsaaAccessTicket(
            7,
            FiscalEnvironment::Homologation,
            'wsfe',
            '20123456786',
            $token,
            $sign,
            CarbonImmutable::parse($generation),
            CarbonImmutable::parse($expiration),
        );
    }

    private function cacheKey(
        WsaaAccessTicketRequest $request
    ): string {
        $scope = [
            'organization_id' => $request->organizationId,
            'environment' => $request->environment->value,
            'service' => $request->service,
            'issuer_cuit' => $request->issuerCuit,
        ];

        return 'srcm:wsaa:ta:v1:' . hash(
            'sha256',
            json_encode(
                $scope,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            )
        );
    }
}

final class MutableWsaaTraClock implements WsaaTraClock
{
    public function __construct(
        public CarbonImmutable $at
    ) {
    }

    public function now(): CarbonImmutable
    {
        return $this->at;
    }
}

final class SyntheticWsaaTraBuilder implements WsaaTraBuilder
{
    public function __construct(
        private readonly MutableWsaaTraClock $clock
    ) {
    }

    public function build(
        WsaaAccessTicketRequest $request
    ): WsaaTra {
        return new WsaaTra(
            123,
            $this->clock->now()->subMinute(),
            $this->clock->now()->addMinutes(10),
            $request->service
        );
    }
}

final class SyntheticWsaaCredentialMaterialProvider implements
    WsaaCredentialMaterialProvider
{
    public function forRequest(
        WsaaAccessTicketRequest $request
    ): WsaaCredentialMaterial {
        return new WsaaCredentialMaterial(
            $request->organizationId,
            $request->environment,
            $request->service,
            $request->issuerCuit,
            "-----BEGIN CERTIFICATE-----\nSYNTHETIC\n-----END CERTIFICATE-----",
            "-----BEGIN PRIVATE KEY-----\nSYNTHETIC\n-----END PRIVATE KEY-----",
        );
    }
}

final class CountingWsaaCmsSigner implements WsaaCmsSigner
{
    public int $calls = 0;

    public function sign(
        WsaaTra $tra,
        WsaaCredentialMaterial $material,
        WsaaCmsDigestAlgorithm $digest
    ): WsaaSignedCms {
        $this->calls++;

        return new WsaaSignedCms(
            base64_encode('synthetic-cms-' . $this->calls),
            $digest
        );
    }
}

final class QueueWsaaLoginCmsTransport implements WsaaLoginCmsTransport
{
    public int $calls = 0;

    /** @param list<WsaaAccessTicket> $tickets */
    public function __construct(
        private array $tickets
    ) {
    }

    public function exchange(
        WsaaAccessTicketRequest $request,
        WsaaSignedCms $signedCms
    ): WsaaAccessTicket {
        $this->calls++;
        $ticket = array_shift($this->tickets);

        if (! $ticket instanceof WsaaAccessTicket) {
            throw new RuntimeException('No synthetic ticket queued.');
        }

        return $ticket;
    }
}

class RecordingRepository extends Repository
{
    public int $gets = 0;

    public function get($key, $default = null): mixed
    {
        $this->gets++;

        return parent::get($key, $default);
    }
}

final class FailingPutRepository extends Repository
{
    public function put($key, $value, $ttl = null)
    {
        return false;
    }
}
