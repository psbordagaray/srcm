<?php

namespace Tests\Feature\Fiscal;

use App\Adapters\Fiscal\Arca\EnvironmentWsaaCredentialMaterialProvider;
use App\Adapters\Fiscal\Arca\EnvironmentWsaaCredentialMaterialReferenceStore;
use App\Adapters\Fiscal\Arca\OpenSslWsaaCredentialMaterialValidator;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsaaCredentialMaterialProvider;
use App\Domain\Fiscal\WsaaCredentialMaterialValidator;
use App\Enums\FiscalEnvironment;
use DomainException;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WsaaCredentialMaterialResolutionBoundaryTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach (
            [
                EnvironmentWsaaCredentialMaterialProvider::ROOT_ENVIRONMENT_KEY,
                EnvironmentWsaaCredentialMaterialReferenceStore::ENVIRONMENT_KEY,
                'SRCM_WSAA_TEST_PASSPHRASE',
            ] as $key
        ) {
            unset(
                $_SERVER[$key],
                $_ENV[$key]
            );
            putenv($key);
        }

        foreach ($this->temporaryRoots as $root) {
            $this->removeTree($root);
        }

        parent::tearDown();
    }

    public function test_container_binds_concrete_material_provider_and_validator(): void
    {
        $this->assertInstanceOf(
            EnvironmentWsaaCredentialMaterialProvider::class,
            app(WsaaCredentialMaterialProvider::class)
        );
        $this->assertInstanceOf(
            OpenSslWsaaCredentialMaterialValidator::class,
            app(WsaaCredentialMaterialValidator::class)
        );
    }

    public function test_provider_resolves_relative_files_under_external_root_without_signing(): void
    {
        $root = $this->temporaryRoot();
        $this->writeSyntheticShapes($root);
        $this->configureReferences(
            'file:certs/certificate.pem',
            'file:keys/private.key',
            null
        );

        $validator = new RecordingWsaaCredentialMaterialValidator;
        $provider = new EnvironmentWsaaCredentialMaterialProvider(
            app(\App\Domain\Fiscal\WsaaCredentialMaterialReferenceStore::class),
            $validator
        );

        $material = $provider->forRequest(
            $this->request()
        );

        $this->assertSame(1, $validator->calls);
        $this->assertSame(
            "-----BEGIN CERTIFICATE-----\nSYNTHETIC\n-----END CERTIFICATE-----",
            $material->certificatePem()
        );
        $this->assertSame(
            "-----BEGIN PRIVATE KEY-----\nSYNTHETIC\n-----END PRIVATE KEY-----",
            $material->privateKeyPem()
        );
        $this->assertNull(
            $material->privateKeyPassphrase()
        );
        $this->assertSame(7, $material->organizationId);
        $this->assertSame('wsfe', $material->service);
        $this->assertSame('20123456786', $material->issuerCuit);
    }

    public function test_passphrase_is_resolved_only_from_env_reference(): void
    {
        $root = $this->temporaryRoot();
        $this->writeSyntheticShapes($root);
        $this->setEnv(
            'SRCM_WSAA_TEST_PASSPHRASE',
            'synthetic-passphrase'
        );
        $this->configureReferences(
            'file:certs/certificate.pem',
            'file:keys/private.key',
            'env:SRCM_WSAA_TEST_PASSPHRASE'
        );

        $validator = new RecordingWsaaCredentialMaterialValidator;
        $provider = new EnvironmentWsaaCredentialMaterialProvider(
            app(\App\Domain\Fiscal\WsaaCredentialMaterialReferenceStore::class),
            $validator
        );

        $material = $provider->forRequest(
            $this->request()
        );

        $this->assertSame(
            'synthetic-passphrase',
            $validator->passphrase
        );
        $this->assertSame(
            'synthetic-passphrase',
            $material->privateKeyPassphrase()
        );

        $debug = print_r($material, true);
        $this->assertStringNotContainsString(
            'synthetic-passphrase',
            $debug
        );
    }

    public function test_missing_passphrase_reference_fails_without_leaking_key_name(): void
    {
        $root = $this->temporaryRoot();
        $this->writeSyntheticShapes($root);
        $this->configureReferences(
            'file:certs/certificate.pem',
            'file:keys/private.key',
            'env:SRCM_WSAA_TEST_PASSPHRASE'
        );

        try {
            $this->providerWithRecordingValidator()
                ->forRequest($this->request());

            $this->fail('La passphrase ausente debía fallar.');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString(
                'SRCM_WSAA_TEST_PASSPHRASE',
                $exception->getMessage()
            );
        }
    }

    public function test_unsupported_absolute_and_traversal_references_fail_closed(): void
    {
        $root = $this->temporaryRoot();
        $this->writeSyntheticShapes($root);

        foreach (
            [
                ['vault://secret/cert', 'file:keys/private.key'],
                ['file:C:/secret/cert.pem', 'file:keys/private.key'],
                ['file:../certificate.pem', 'file:keys/private.key'],
                ['file:certs//certificate.pem', 'file:keys/private.key'],
                ['file:certs/certificate.pem', 'file:../private.key'],
            ] as [$certificate, $privateKey]
        ) {
            $this->configureReferences(
                $certificate,
                $privateKey,
                null
            );

            try {
                $this->providerWithRecordingValidator()
                    ->forRequest($this->request());

                $this->fail('La referencia insegura debía fallar.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_root_inside_or_around_repository_is_rejected_before_file_read(): void
    {
        foreach (
            [
                base_path(),
                dirname(base_path()),
            ] as $unsafeRoot
        ) {
            $this->setEnv(
                EnvironmentWsaaCredentialMaterialProvider::ROOT_ENVIRONMENT_KEY,
                $unsafeRoot
            );
            $this->configureReferences(
                'file:.env.example',
                'file:.env.example',
                null,
                false
            );

            try {
                $this->providerWithRecordingValidator()
                    ->forRequest($this->request());

                $this->fail('La raíz solapada con repo debía fallar.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_production_resolution_remains_blocked(): void
    {
        $this->expectException(DomainException::class);

        app(WsaaCredentialMaterialProvider::class)->forRequest(
            new WsaaAccessTicketRequest(
                7,
                FiscalEnvironment::Production,
                'wsfe',
                '20123456786'
            )
        );
    }

    public function test_openssl_validator_rejects_malformed_material_without_echoing_it(): void
    {
        $validator = new OpenSslWsaaCredentialMaterialValidator;

        try {
            $validator->assertValid(
                "-----BEGIN CERTIFICATE-----\nSENSITIVE_SYNTHETIC\n-----END CERTIFICATE-----",
                "-----BEGIN PRIVATE KEY-----\nSENSITIVE_SYNTHETIC\n-----END PRIVATE KEY-----",
                'synthetic-passphrase'
            );

            $this->fail('OpenSSL debía rechazar material sintético inválido.');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString(
                'SENSITIVE_SYNTHETIC',
                $exception->getMessage()
            );
            $this->assertStringNotContainsString(
                'synthetic-passphrase',
                $exception->getMessage()
            );
        }
    }

    public function test_resolution_boundary_has_no_cms_login_or_network_execution(): void
    {
        $providerSource = file_get_contents(
            app_path(
                'Adapters/Fiscal/Arca/EnvironmentWsaaCredentialMaterialProvider.php'
            )
        );
        $validatorSource = file_get_contents(
            app_path(
                'Adapters/Fiscal/Arca/OpenSslWsaaCredentialMaterialValidator.php'
            )
        );

        $this->assertIsString($providerSource);
        $this->assertIsString($validatorSource);

        foreach (
            [
                'LoginCms(',
                'SoapClient',
                'Http::',
                'curl_',
                'openssl_cms_sign',
                'proc_open',
                'shell_exec',
                'exec(',
            ] as $forbidden
        ) {
            $this->assertStringNotContainsString(
                $forbidden,
                $providerSource
            );
            $this->assertStringNotContainsString(
                $forbidden,
                $validatorSource
            );
        }

        foreach (
            [
                'openssl_x509_read',
                'openssl_pkey_get_private',
                'openssl_x509_check_private_key',
            ] as $required
        ) {
            $this->assertStringContainsString(
                $required,
                $validatorSource
            );
        }
    }

    public function test_env_example_and_gitignore_expose_only_safe_configuration_shape(): void
    {
        $envExample = file_get_contents(
            base_path('.env.example')
        );
        $gitignore = file_get_contents(
            base_path('.gitignore')
        );

        $this->assertIsString($envExample);
        $this->assertIsString($gitignore);

        $this->assertStringContainsString(
            'SRCM_ARCA_WSAA_CREDENTIAL_ROOT=',
            $envExample
        );
        $this->assertStringContainsString(
            'file:certificates/org-1.pem',
            $envExample
        );
        $this->assertStringContainsString(
            'env:SRCM_ARCA_WSAA_ORG_1_KEY_PASSPHRASE',
            $envExample
        );
        $this->assertStringNotContainsString(
            '-----BEGIN PRIVATE KEY-----',
            $envExample
        );

        foreach (
            [
                '*.pem',
                '*.key',
                '*.p12',
                '*.pfx',
                '*.crt',
                '*.cer',
            ] as $pattern
        ) {
            $this->assertStringContainsString(
                $pattern,
                $gitignore
            );
        }
    }

    private function providerWithRecordingValidator(): EnvironmentWsaaCredentialMaterialProvider
    {
        return new EnvironmentWsaaCredentialMaterialProvider(
            app(\App\Domain\Fiscal\WsaaCredentialMaterialReferenceStore::class),
            new RecordingWsaaCredentialMaterialValidator
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

    private function temporaryRoot(): string
    {
        $root = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'srcm_wsaa_resolution_'
            . bin2hex(random_bytes(8));

        mkdir($root, 0700, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'certs', 0700, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'keys', 0700, true);

        $this->temporaryRoots[] = $root;

        $this->setEnv(
            EnvironmentWsaaCredentialMaterialProvider::ROOT_ENVIRONMENT_KEY,
            $root
        );

        return $root;
    }

    private function writeSyntheticShapes(string $root): void
    {
        file_put_contents(
            $root
                . DIRECTORY_SEPARATOR
                . 'certs'
                . DIRECTORY_SEPARATOR
                . 'certificate.pem',
            "-----BEGIN CERTIFICATE-----\nSYNTHETIC\n-----END CERTIFICATE-----"
        );
        file_put_contents(
            $root
                . DIRECTORY_SEPARATOR
                . 'keys'
                . DIRECTORY_SEPARATOR
                . 'private.key',
            "-----BEGIN PRIVATE KEY-----\nSYNTHETIC\n-----END PRIVATE KEY-----"
        );
    }

    private function configureReferences(
        string $certificate,
        string $privateKey,
        ?string $passphrase,
        bool $ensureService = true
    ): void {
        if ($ensureService) {
            Config::set(
                'services.arca.homologation.service_name',
                'wsfe'
            );
        } else {
            Config::set(
                'services.arca.homologation.service_name',
                'wsfe'
            );
        }

        $_SERVER[
            EnvironmentWsaaCredentialMaterialReferenceStore::ENVIRONMENT_KEY
        ] = json_encode(
            [
                '7' => [
                    'service' => 'wsfe',
                    'issuer_cuit' => '20123456786',
                    'certificate_reference' => $certificate,
                    'private_key_reference' => $privateKey,
                    'private_key_passphrase_reference' => $passphrase,
                ],
            ],
            JSON_THROW_ON_ERROR
        );
    }

    private function setEnv(string $key, string $value): void
    {
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }
}

final class RecordingWsaaCredentialMaterialValidator implements
    WsaaCredentialMaterialValidator
{
    public int $calls = 0;
    public ?string $passphrase = null;

    public function assertValid(
        string $certificatePem,
        string $privateKeyPem,
        ?string $privateKeyPassphrase
    ): void {
        $this->calls++;
        $this->passphrase = $privateKeyPassphrase;
    }
}
