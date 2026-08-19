<?php

namespace Tests\Feature\Fiscal;

use App\Adapters\Fiscal\Arca\EnvironmentWsaaCredentialMaterialProvider;
use App\Adapters\Fiscal\Arca\EnvironmentWsaaCredentialMaterialReferenceStore;
use App\Domain\Fiscal\ArcaHomologationReadiness;
use App\Domain\Fiscal\FiscalAuthorizationCredentialStore;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsaaCredentialMaterial;
use App\Domain\Fiscal\WsaaCredentialMaterialProvider;
use App\Domain\Fiscal\WsaaCredentialMaterialReference;
use App\Domain\Fiscal\WsaaCredentialMaterialReferenceStore;
use App\Enums\FiscalEnvironment;
use DomainException;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use Tests\TestCase;

class WsaaCredentialMaterialBoundaryTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_SERVER[
                EnvironmentWsaaCredentialMaterialReferenceStore::ENVIRONMENT_KEY
            ],
            $_ENV[
                EnvironmentWsaaCredentialMaterialReferenceStore::ENVIRONMENT_KEY
            ]
        );

        putenv(
            EnvironmentWsaaCredentialMaterialReferenceStore::ENVIRONMENT_KEY
        );

        parent::tearDown();
    }

    public function test_reference_store_is_exactly_scoped(): void
    {
        Config::set(
            'services.arca.homologation.service_name',
            'wsfe'
        );
        $this->setReferenceMap();

        $store = app(
            WsaaCredentialMaterialReferenceStore::class
        );

        $this->assertInstanceOf(
            EnvironmentWsaaCredentialMaterialReferenceStore::class,
            $store
        );
        $this->assertTrue(
            $store->hasAny(
                FiscalEnvironment::Homologation
            )
        );
        $this->assertFalse(
            $store->hasAny(
                FiscalEnvironment::Production
            )
        );

        $reference = $store->forRequest(
            new WsaaAccessTicketRequest(
                organizationId: 7,
                environment:
                    FiscalEnvironment::Homologation,
                service: 'wsfe',
                issuerCuit: '20123456786',
            )
        );

        $this->assertSame(
            7,
            $reference->organizationId
        );
        $this->assertSame(
            FiscalEnvironment::Homologation,
            $reference->environment
        );
        $this->assertSame(
            'wsfe',
            $reference->service
        );
        $this->assertSame(
            '20123456786',
            $reference->issuerCuit
        );
        $this->assertSame(
            'vault://arca/org-7/certificate',
            $reference->certificateReference()
        );
        $this->assertSame(
            'vault://arca/org-7/private-key',
            $reference->privateKeyReference()
        );
        $this->assertNull(
            $reference->privateKeyPassphraseReference()
        );
    }

    public function test_wrong_org_service_cuit_and_production_fail_closed(): void
    {
        Config::set(
            'services.arca.homologation.service_name',
            'wsfe'
        );
        $this->setReferenceMap();
        $store = app(
            WsaaCredentialMaterialReferenceStore::class
        );

        foreach (
            [
                new WsaaAccessTicketRequest(
                    8,
                    FiscalEnvironment::Homologation,
                    'wsfe',
                    '20123456786'
                ),
                new WsaaAccessTicketRequest(
                    7,
                    FiscalEnvironment::Homologation,
                    'wsfev1',
                    '20123456786'
                ),
                new WsaaAccessTicketRequest(
                    7,
                    FiscalEnvironment::Homologation,
                    'wsfe',
                    '20999999991'
                ),
                new WsaaAccessTicketRequest(
                    7,
                    FiscalEnvironment::Production,
                    'wsfe',
                    '20123456786'
                ),
            ] as $request
        ) {
            $rejected = false;

            try {
                $store->forRequest($request);
            } catch (DomainException) {
                $rejected = true;
            }

            $this->assertTrue($rejected);
        }
    }

    public function test_malformed_reference_map_fails_closed(): void
    {
        Config::set(
            'services.arca.homologation.service_name',
            'wsfe'
        );

        $_SERVER[
            EnvironmentWsaaCredentialMaterialReferenceStore::ENVIRONMENT_KEY
        ] = '{"7":{"service":"wsfe"}}';

        $this->expectException(
            DomainException::class
        );

        app(
            WsaaCredentialMaterialReferenceStore::class
        )->hasAny(
            FiscalEnvironment::Homologation
        );
    }

    public function test_references_are_private_redacted_and_not_serializable(): void
    {
        $reference = new WsaaCredentialMaterialReference(
            organizationId: 7,
            environment:
                FiscalEnvironment::Homologation,
            service: 'wsfe',
            issuerCuit: '20123456786',
            certificateReference:
                'vault://secret/certificate',
            privateKeyReference:
                'vault://secret/private-key',
            privateKeyPassphraseReference:
                'vault://secret/passphrase',
        );

        $debug = print_r(
            $reference,
            true
        );

        $this->assertStringNotContainsString(
            'vault://secret/certificate',
            $debug
        );
        $this->assertStringNotContainsString(
            'vault://secret/private-key',
            $debug
        );
        $this->assertStringContainsString(
            '[REDACTED]',
            $debug
        );

        $this->expectException(
            DomainException::class
        );

        serialize($reference);
    }

    public function test_material_is_ephemeral_private_redacted_and_not_serializable(): void
    {
        $material = new WsaaCredentialMaterial(
            organizationId: 7,
            environment:
                FiscalEnvironment::Homologation,
            service: 'wsfe',
            issuerCuit: '20123456786',
            certificatePem:
                "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----",
            privateKeyPem:
                "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----",
            privateKeyPassphrase:
                'test-passphrase',
        );

        $debug = print_r(
            $material,
            true
        );

        $this->assertStringNotContainsString(
            'test-passphrase',
            $debug
        );
        $this->assertStringNotContainsString(
            'TEST',
            $debug
        );
        $this->assertStringContainsString(
            '[REDACTED]',
            $debug
        );

        $this->assertStringContainsString(
            'BEGIN CERTIFICATE',
            $material->certificatePem()
        );
        $this->assertStringContainsString(
            'BEGIN PRIVATE KEY',
            $material->privateKeyPem()
        );
        $this->assertSame(
            'test-passphrase',
            $material->privateKeyPassphrase()
        );

        $this->expectException(
            DomainException::class
        );

        serialize($material);
    }

    public function test_material_rejects_non_pem_shapes(): void
    {
        $this->expectException(
            DomainException::class
        );

        new WsaaCredentialMaterial(
            organizationId: 7,
            environment:
                FiscalEnvironment::Homologation,
            service: 'wsfe',
            issuerCuit: '20123456786',
            certificatePem: 'not-a-certificate',
            privateKeyPem: 'not-a-private-key',
        );
    }

    public function test_homologation_readiness_requires_reference_map(): void
    {
        Config::set(
            'services.arca.homologation.enabled',
            true
        );
        Config::set(
            'services.arca.homologation.wsaa_endpoint',
            'https://wsaahomo.afip.gov.ar/ws/services/LoginCms'
        );
        Config::set(
            'services.arca.homologation.business_endpoint',
            'https://wswhomo.afip.gov.ar/wsfev1/service.asmx'
        );
        Config::set(
            'services.arca.homologation.service_name',
            'wsfe'
        );
        Config::set(
            'services.arca.production.enabled',
            false
        );

        $rejected = false;

        try {
            app(
                ArcaHomologationReadiness::class
            )->assertReady();
        } catch (DomainException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);

        $this->setReferenceMap();

        app(
            ArcaHomologationReadiness::class
        )->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_production_hard_block_remains_after_reference_map(): void
    {
        Config::set(
            'services.arca.homologation.enabled',
            true
        );
        Config::set(
            'services.arca.homologation.wsaa_endpoint',
            'https://wsaahomo.afip.gov.ar/ws/services/LoginCms'
        );
        Config::set(
            'services.arca.homologation.business_endpoint',
            'https://wswhomo.afip.gov.ar/wsfev1/service.asmx'
        );
        Config::set(
            'services.arca.homologation.service_name',
            'wsfe'
        );
        Config::set(
            'services.arca.production.enabled',
            true
        );
        $this->setReferenceMap();

        $this->expectException(
            DomainException::class
        );

        app(
            ArcaHomologationReadiness::class
        )->assertReady();
    }

    public function test_material_provider_is_bound_but_old_credential_store_remains_unbound(): void
    {
        $this->assertTrue(
            (
                new ReflectionClass(
                    WsaaCredentialMaterialProvider::class
                )
            )->isInterface()
        );
        $this->assertTrue(
            app()->bound(
                WsaaCredentialMaterialProvider::class
            )
        );
        $this->assertInstanceOf(
            EnvironmentWsaaCredentialMaterialProvider::class,
            app(WsaaCredentialMaterialProvider::class)
        );
        $this->assertFalse(
            app()->bound(
                FiscalAuthorizationCredentialStore::class
            )
        );
    }

    public function test_new_adapter_does_not_dereference_sign_or_call_arca(): void
    {
        $source = file_get_contents(
            app_path(
                'Adapters/Fiscal/Arca/EnvironmentWsaaCredentialMaterialReferenceStore.php'
            )
        );

        $this->assertIsString($source);

        foreach (
            [
                'file_get_contents',
                'openssl_',
                'SoapClient',
                'LoginCms(',
                'Http::',
                'curl_',
                'pkcs7',
                'cms_sign',
            ] as $forbidden
        ) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source
            );
        }

        $envExample = file_get_contents(
            base_path('.env.example')
        );

        $this->assertIsString($envExample);
        $this->assertStringContainsString(
            'SRCM_ARCA_WSAA_CREDENTIAL_REFERENCES_JSON=',
            $envExample
        );
        $this->assertStringNotContainsString(
            '-----BEGIN CERTIFICATE-----',
            $envExample
        );
        $this->assertStringNotContainsString(
            '-----BEGIN PRIVATE KEY-----',
            $envExample
        );

        $services = file_get_contents(
            config_path('services.php')
        );

        $this->assertIsString($services);
        $this->assertStringNotContainsString(
            'ARCA_HOMOLOGATION_CERTIFICATE_REFERENCE',
            $services
        );
    }

    private function setReferenceMap(): void
    {
        $_SERVER[
            EnvironmentWsaaCredentialMaterialReferenceStore::ENVIRONMENT_KEY
        ] = json_encode(
            [
                '7' => [
                    'service' => 'wsfe',
                    'issuer_cuit' => '20123456786',
                    'certificate_reference' =>
                        'vault://arca/org-7/certificate',
                    'private_key_reference' =>
                        'vault://arca/org-7/private-key',
                    'private_key_passphrase_reference' =>
                        null,
                ],
            ],
            JSON_THROW_ON_ERROR
        );
    }
}
