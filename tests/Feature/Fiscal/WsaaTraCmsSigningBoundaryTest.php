<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\WsaaCmsDigestPolicy;
use App\Domain\Fiscal\WsaaCmsSigner;
use App\Domain\Fiscal\WsaaCredentialMaterial;
use App\Domain\Fiscal\WsaaCredentialMaterialProvider;
use App\Domain\Fiscal\WsaaCredentialMaterialReference;
use App\Domain\Fiscal\WsaaLoginTicketRequestBuilder;
use App\Domain\Fiscal\WsaaSignedCms;
use App\Domain\Fiscal\WsaaTra;
use App\Domain\Fiscal\WsaaTraBuilder;
use App\Domain\Fiscal\WsaaTraClock;
use App\Domain\Fiscal\WsaaTraUniqueIdProvider;
use App\Domain\Fiscal\WsaaTraWindowPolicy;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Enums\FiscalEnvironment;
use App\Enums\WsaaCmsDigestAlgorithm;
use Carbon\CarbonImmutable;
use DomainException;
use ReflectionClass;
use Tests\TestCase;

class WsaaTraCmsSigningBoundaryTest extends TestCase
{
    public function test_service_identity_matches_official_xsd_across_scope_objects(): void
    {
        foreach (
            [
                'abc',
                'wsfe',
                'wsfe_v1',
                'a' . str_repeat('1', 31),
            ] as $valid
        ) {
            $request = new WsaaAccessTicketRequest(
                7,
                FiscalEnvironment::Homologation,
                $valid,
                '20123456786'
            );

            $this->assertSame(
                $valid,
                $request->service
            );
        }

        foreach (
            [
                'ab',
                '1wsfe',
                'wsfe-explicit',
                'wsfe.dot',
                'a' . str_repeat('1', 32),
            ] as $invalid
        ) {
            $this->assertServiceRejected(
                $invalid
            );
        }
    }

    public function test_tra_builder_emits_only_required_v1_fields_with_utc_times(): void
    {
        $request = new WsaaAccessTicketRequest(
            7,
            FiscalEnvironment::Homologation,
            'wsfe',
            '20123456786'
        );

        $builder = new WsaaLoginTicketRequestBuilder(
            new FixedWsaaTraClock(
                CarbonImmutable::parse(
                    '2026-08-19T12:00:00-03:00'
                )
            ),
            new FixedWsaaTraUniqueIdProvider(
                WsaaTra::MAX_UNIQUE_ID
            ),
            new WsaaTraWindowPolicy(
                generationBackSeconds: 600,
                expirationForwardSeconds: 1200,
            )
        );

        $this->assertInstanceOf(
            WsaaTraBuilder::class,
            $builder
        );

        $tra = $builder->build(
            $request
        );

        $this->assertSame(
            WsaaTra::MAX_UNIQUE_ID,
            $tra->uniqueId
        );
        $this->assertSame(
            '2026-08-19T14:50:00+00:00',
            $tra->generationTime->format(
                'Y-m-d\TH:i:sP'
            )
        );
        $this->assertSame(
            '2026-08-19T15:20:00+00:00',
            $tra->expirationTime->format(
                'Y-m-d\TH:i:sP'
            )
        );
        $this->assertSame(
            'wsfe',
            $tra->service
        );

        $expected =
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<loginTicketRequest version="1.0">'
            . '<header>'
            . '<uniqueId>4294967295</uniqueId>'
            . '<generationTime>2026-08-19T14:50:00+00:00</generationTime>'
            . '<expirationTime>2026-08-19T15:20:00+00:00</expirationTime>'
            . '</header>'
            . '<service>wsfe</service>'
            . '</loginTicketRequest>';

        $this->assertSame(
            $expected,
            $tra->xml()
        );
        $this->assertStringNotContainsString(
            'source',
            $tra->xml()
        );
        $this->assertStringNotContainsString(
            'destination',
            $tra->xml()
        );
        $this->assertStringNotContainsString(
            '20123456786',
            $tra->xml()
        );
    }

    public function test_tra_unique_id_and_window_policy_fail_closed(): void
    {
        foreach (
            [
                -1,
                WsaaTra::MAX_UNIQUE_ID + 1,
            ] as $invalidUniqueId
        ) {
            try {
                new WsaaTra(
                    $invalidUniqueId,
                    CarbonImmutable::parse(
                        '2026-08-19T15:00:00Z'
                    ),
                    CarbonImmutable::parse(
                        '2026-08-19T15:10:00Z'
                    ),
                    'wsfe'
                );

                $this->fail(
                    'uniqueId inválido debía fallar.'
                );
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }

        foreach (
            [
                [-1, 600],
                [86401, 600],
                [600, 0],
                [600, 86401],
            ] as [$back, $forward]
        ) {
            try {
                new WsaaTraWindowPolicy(
                    $back,
                    $forward
                );

                $this->fail(
                    'Ventana TRA inválida debía fallar.'
                );
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_tra_requires_expiration_after_generation(): void
    {
        $this->expectException(
            DomainException::class
        );

        new WsaaTra(
            10,
            CarbonImmutable::parse(
                '2026-08-19T15:00:00Z'
            ),
            CarbonImmutable::parse(
                '2026-08-19T15:00:00Z'
            ),
            'wsfe'
        );
    }

    public function test_cms_digest_choice_is_explicit_and_provider_acceptance_is_not_encoded(): void
    {
        $this->assertSame(
            'sha1',
            WsaaCmsDigestAlgorithm::Sha1->value
        );
        $this->assertSame(
            'sha256',
            WsaaCmsDigestAlgorithm::Sha256->value
        );

        $this->assertTrue(
            (
                new ReflectionClass(
                    WsaaCmsDigestPolicy::class
                )
            )->isInterface()
        );
        $this->assertTrue(
            (
                new ReflectionClass(
                    WsaaCmsSigner::class
                )
            )->isInterface()
        );

        $this->assertFalse(
            app()->bound(
                WsaaCmsDigestPolicy::class
            )
        );
        $this->assertFalse(
            app()->bound(
                WsaaCmsSigner::class
            )
        );
    }

    public function test_signed_cms_is_base64_only_redacted_and_not_serializable(): void
    {
        $raw =
            'synthetic-cms-payload';

        $base64 =
            base64_encode(
                $raw
            );

        $signed =
            new WsaaSignedCms(
                $base64,
                WsaaCmsDigestAlgorithm::Sha256
            );

        $this->assertSame(
            $base64,
            $signed->loginCmsInput()
        );
        $this->assertSame(
            WsaaCmsDigestAlgorithm::Sha256,
            $signed->digest
        );

        $debug =
            print_r(
                $signed,
                true
            );

        $this->assertStringNotContainsString(
            $base64,
            $debug
        );
        $this->assertStringContainsString(
            '[REDACTED]',
            $debug
        );

        $this->expectException(
            DomainException::class
        );

        serialize($signed);
    }

    public function test_signed_cms_rejects_wrapped_or_invalid_input(): void
    {
        foreach (
            [
                '',
                "YWJj\n",
                '-----BEGIN CMS-----YWJj',
                'not base64!',
            ] as $invalid
        ) {
            try {
                new WsaaSignedCms(
                    $invalid,
                    WsaaCmsDigestAlgorithm::Sha1
                );

                $this->fail(
                    'CMS inválido debía fallar.'
                );
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_signing_execution_boundaries_remain_unbound_after_material_resolution(): void
    {
        $this->assertFalse(
            app()->bound(
                WsaaTraBuilder::class
            )
        );
        $this->assertFalse(
            app()->bound(
                WsaaTraClock::class
            )
        );
        $this->assertFalse(
            app()->bound(
                WsaaTraUniqueIdProvider::class
            )
        );
        $this->assertTrue(
            app()->bound(
                WsaaCredentialMaterialProvider::class
            )
        );
        $this->assertFalse(
            app()->bound(
                WsaaCmsDigestPolicy::class
            )
        );
        $this->assertFalse(
            app()->bound(
                WsaaCmsSigner::class
            )
        );
    }

    public function test_new_boundary_contains_no_login_cms_or_network_execution(): void
    {
        foreach (
            [
                'Domain/Fiscal/WsaaTra.php',
                'Domain/Fiscal/WsaaLoginTicketRequestBuilder.php',
                'Domain/Fiscal/WsaaCmsSigner.php',
                'Domain/Fiscal/WsaaCmsDigestPolicy.php',
                'Domain/Fiscal/WsaaSignedCms.php',
            ] as $relative
        ) {
            $source =
                file_get_contents(
                    app_path(
                        $relative
                    )
                );

            $this->assertIsString(
                $source
            );

            foreach (
                [
                    'LoginCms(',
                    'SoapClient',
                    'Http::',
                    'curl_',
                    'openssl_',
                    'proc_open',
                    'shell_exec',
                    'exec(',
                ] as $forbidden
            ) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source
                );
            }
        }
    }

    private function assertServiceRejected(
        string $service
    ): void {
        foreach (
            [
                fn () =>
                    new WsaaAccessTicketRequest(
                        7,
                        FiscalEnvironment::Homologation,
                        $service,
                        '20123456786'
                    ),
                fn () =>
                    new WsaaCredentialMaterialReference(
                        organizationId: 7,
                        environment:
                            FiscalEnvironment::Homologation,
                        service: $service,
                        issuerCuit:
                            '20123456786',
                        certificateReference:
                            'vault://certificate',
                        privateKeyReference:
                            'vault://private-key',
                    ),
                fn () =>
                    new WsaaCredentialMaterial(
                        organizationId: 7,
                        environment:
                            FiscalEnvironment::Homologation,
                        service: $service,
                        issuerCuit:
                            '20123456786',
                        certificatePem:
                            "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----",
                        privateKeyPem:
                            "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----",
                    ),
            ] as $factory
        ) {
            try {
                $factory();

                $this->fail(
                    'El servicio fuera de XSD debía fallar: '
                    . $service
                );
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }
}

final readonly class FixedWsaaTraClock implements
    WsaaTraClock
{
    public function __construct(
        private CarbonImmutable $instant
    ) {
    }

    public function now(): CarbonImmutable
    {
        return $this->instant;
    }
}

final readonly class FixedWsaaTraUniqueIdProvider implements
    WsaaTraUniqueIdProvider
{
    public function __construct(
        private int $value
    ) {
    }

    public function next(): int
    {
        return $this->value;
    }
}
