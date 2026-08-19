<?php

namespace Tests\Feature\Fiscal;

use App\Adapters\Fiscal\Arca\NativeWsaaCmsProcessRunner;
use App\Adapters\Fiscal\Arca\OpenSslCliWsaaCmsSigner;
use App\Adapters\Fiscal\Arca\WsaaCmsProcessRunner;
use App\Domain\Fiscal\WsaaCmsDigestPolicy;
use App\Domain\Fiscal\WsaaCmsSigner;
use App\Domain\Fiscal\WsaaCredentialMaterial;
use App\Domain\Fiscal\WsaaTra;
use App\Enums\FiscalEnvironment;
use App\Enums\WsaaCmsDigestAlgorithm;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\TestCase;

class WsaaCmsSignerExecutionBoundaryTest extends TestCase
{
    public function test_container_binds_concrete_signer_but_not_digest_policy(): void
    {
        $this->assertInstanceOf(
            OpenSslCliWsaaCmsSigner::class,
            app(WsaaCmsSigner::class)
        );
        $this->assertInstanceOf(
            NativeWsaaCmsProcessRunner::class,
            app(WsaaCmsProcessRunner::class)
        );
        $this->assertFalse(
            app()->bound(
                WsaaCmsDigestPolicy::class
            )
        );
    }

    public function test_signer_uses_explicit_attached_der_digest_and_env_passphrase(): void
    {
        foreach (
            [
                WsaaCmsDigestAlgorithm::Sha1,
                WsaaCmsDigestAlgorithm::Sha256,
            ] as $digest
        ) {
            $runner =
                new RecordingWsaaCmsProcessRunner;

            $signer =
                new OpenSslCliWsaaCmsSigner(
                    $runner
                );

            $signed =
                $signer->sign(
                    $this->tra(),
                    $this->material(
                        'SYNTHETIC-PRIVATE-PASSPHRASE'
                    ),
                    $digest
                );

            $signCall =
                $runner->signCall();

            $command =
                $signCall['command'];

            $environment =
                $signCall['environment'];

            $this->assertSame(
                'openssl',
                $command[0]
            );
            $this->assertSame(
                'cms',
                $command[1]
            );
            $this->assertSame(
                '-sign',
                $command[2]
            );
            $this->assertContains(
                '-nodetach',
                $command
            );
            $this->assertContains(
                '-binary',
                $command
            );

            $this->assertOptionValue(
                $command,
                '-outform',
                'DER'
            );
            $this->assertOptionValue(
                $command,
                '-md',
                $digest->value
            );

            $passin =
                $this->optionValue(
                    $command,
                    '-passin'
                );

            $this->assertMatchesRegularExpression(
                '/^env:SRCM_WSAA_CMS_PASS_[A-F0-9]{24}$/D',
                $passin
            );

            $key =
                substr(
                    $passin,
                    strlen('env:')
                );

            $this->assertArrayHasKey(
                $key,
                $environment
            );
            $this->assertSame(
                'SYNTHETIC-PRIVATE-PASSPHRASE',
                $environment[$key]
            );

            $this->assertStringNotContainsString(
                'SYNTHETIC-PRIVATE-PASSPHRASE',
                implode(' ', $command)
            );

            $this->assertSame(
                base64_encode(
                    RecordingWsaaCmsProcessRunner::SYNTHETIC_DER
                ),
                $signed->loginCmsInput()
            );
            $this->assertSame(
                $digest,
                $signed->digest
            );

            $outputPath =
                $this->optionValue(
                    $command,
                    '-out'
                );

            $this->assertFileDoesNotExist(
                $outputPath
            );
            $this->assertDirectoryDoesNotExist(
                dirname($outputPath)
            );
        }
    }

    public function test_signer_rejects_production_and_service_mismatch_before_openssl(): void
    {
        $runner =
            new RecordingWsaaCmsProcessRunner;

        $signer =
            new OpenSslCliWsaaCmsSigner(
                $runner
            );

        try {
            $signer->sign(
                $this->tra(),
                new WsaaCredentialMaterial(
                    organizationId: 7,
                    environment:
                        FiscalEnvironment::Production,
                    service: 'wsfe',
                    issuerCuit: '20123456786',
                    certificatePem:
                        $this->certificatePem(),
                    privateKeyPem:
                        $this->privateKeyPem(),
                ),
                WsaaCmsDigestAlgorithm::Sha1
            );

            $this->fail(
                'Producción debía permanecer bloqueada.'
            );
        } catch (RuntimeException) {
            $this->assertSame(
                0,
                $runner->countOperation(
                    'openssl-cms-sign'
                )
            );
        }

        try {
            $signer->sign(
                $this->tra(),
                new WsaaCredentialMaterial(
                    organizationId: 7,
                    environment:
                        FiscalEnvironment::Homologation,
                    service: 'ws_sr_padron_a5',
                    issuerCuit: '20123456786',
                    certificatePem:
                        $this->certificatePem(),
                    privateKeyPem:
                        $this->privateKeyPem(),
                ),
                WsaaCmsDigestAlgorithm::Sha1
            );

            $this->fail(
                'El servicio inconsistente debía fallar.'
            );
        } catch (RuntimeException) {
            $this->assertSame(
                0,
                $runner->countOperation(
                    'openssl-cms-sign'
                )
            );
        }
    }

    public function test_workspace_is_removed_when_native_signing_fails(): void
    {
        $runner =
            new RecordingWsaaCmsProcessRunner(
                failSigning: true
            );

        $signer =
            new OpenSslCliWsaaCmsSigner(
                $runner
            );

        try {
            $signer->sign(
                $this->tra(),
                $this->material(),
                WsaaCmsDigestAlgorithm::Sha256
            );

            $this->fail(
                'La falla sintética de signing debía propagarse.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'No se pudo producir el CMS WSAA.',
                $exception->getMessage()
            );
        }

        $signCall =
            $runner->signCall();

        $outputPath =
            $this->optionValue(
                $signCall['command'],
                '-out'
            );

        $this->assertFileDoesNotExist(
            $outputPath
        );
        $this->assertDirectoryDoesNotExist(
            dirname($outputPath)
        );
    }

    public function test_empty_or_missing_cms_output_fails_closed_and_cleans_workspace(): void
    {
        foreach (
            [
                'empty',
                'missing',
            ] as $mode
        ) {
            $runner =
                new RecordingWsaaCmsProcessRunner(
                    outputMode: $mode
                );

            $signer =
                new OpenSslCliWsaaCmsSigner(
                    $runner
                );

            try {
                $signer->sign(
                    $this->tra(),
                    $this->material(),
                    WsaaCmsDigestAlgorithm::Sha1
                );

                $this->fail(
                    'El output CMS inválido debía fallar.'
                );
            } catch (RuntimeException) {
                $signCall =
                    $runner->signCall();

                $outputPath =
                    $this->optionValue(
                        $signCall['command'],
                        '-out'
                    );

                $this->assertDirectoryDoesNotExist(
                    dirname($outputPath)
                );
            }
        }
    }

    public function test_production_sources_have_no_login_cms_network_shell_or_digest_default(): void
    {
        $signerSource =
            file_get_contents(
                app_path(
                    'Adapters/Fiscal/Arca/OpenSslCliWsaaCmsSigner.php'
                )
            );

        $runnerSource =
            file_get_contents(
                app_path(
                    'Adapters/Fiscal/Arca/NativeWsaaCmsProcessRunner.php'
                )
            );

        $this->assertIsString(
            $signerSource
        );
        $this->assertIsString(
            $runnerSource
        );

        foreach (
            [
                'LoginCms(',
                'SoapClient',
                'Http::',
                'curl_',
                'shell_exec',
            ] as $forbidden
        ) {
            $this->assertStringNotContainsString(
                $forbidden,
                $signerSource
            );
            $this->assertStringNotContainsString(
                $forbidden,
                $runnerSource
            );
        }

        $this->assertStringNotContainsString(
            "'pipe'",
            $runnerSource
        );
        $this->assertStringContainsString(
            'proc_open',
            $runnerSource
        );
        $this->assertStringContainsString(
            "'bypass_shell' => true",
            $runnerSource
        );
        $this->assertStringContainsString(
            "'-nodetach'",
            $signerSource
        );
        $this->assertStringContainsString(
            "'-binary'",
            $signerSource
        );
        $this->assertStringContainsString(
            "'-md'",
            $signerSource
        );
        $this->assertStringContainsString(
            '$digest->value',
            $signerSource
        );
        $this->assertStringContainsString(
            "'env:'",
            $signerSource
        );
        $this->assertStringContainsString(
            'sys_get_temp_dir',
            $signerSource
        );
        $this->assertStringContainsString(
            'icacls.exe',
            $signerSource
        );
        $this->assertStringNotContainsString(
            'WsaaCmsDigestAlgorithm::Sha1',
            $signerSource
        );
        $this->assertStringNotContainsString(
            'WsaaCmsDigestAlgorithm::Sha256',
            $signerSource
        );
    }

    private function tra(): WsaaTra
    {
        return new WsaaTra(
            123456,
            CarbonImmutable::parse(
                '2026-08-19T15:00:00Z'
            ),
            CarbonImmutable::parse(
                '2026-08-19T15:10:00Z'
            ),
            'wsfe'
        );
    }

    private function material(
        ?string $passphrase = null
    ): WsaaCredentialMaterial {
        return new WsaaCredentialMaterial(
            organizationId: 7,
            environment:
                FiscalEnvironment::Homologation,
            service: 'wsfe',
            issuerCuit: '20123456786',
            certificatePem:
                $this->certificatePem(),
            privateKeyPem:
                $this->privateKeyPem(),
            privateKeyPassphrase:
                $passphrase,
        );
    }

    private function certificatePem(): string
    {
        return
            "-----BEGIN CERTIFICATE-----\n"
            . "SYNTHETIC\n"
            . "-----END CERTIFICATE-----";
    }

    private function privateKeyPem(): string
    {
        return
            "-----BEGIN PRIVATE KEY-----\n"
            . "SYNTHETIC\n"
            . "-----END PRIVATE KEY-----";
    }

    /**
     * @param  list<string>  $command
     */
    private function assertOptionValue(
        array $command,
        string $option,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            $this->optionValue(
                $command,
                $option
            )
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function optionValue(
        array $command,
        string $option
    ): string {
        $index =
            array_search(
                $option,
                $command,
                true
            );

        $this->assertIsInt(
            $index
        );
        $this->assertArrayHasKey(
            $index + 1,
            $command
        );

        return
            $command[
                $index + 1
            ];
    }
}

final class RecordingWsaaCmsProcessRunner implements
    WsaaCmsProcessRunner
{
    public const SYNTHETIC_DER =
        "\x30\x0fSRCM-SYNTHETIC-CMS";

    /** @var list<array{command:list<string>,environment:array<string,string>,timeout:int,operation:string}> */
    public array $calls = [];

    public function __construct(
        private readonly bool $failSigning = false,
        private readonly string $outputMode = 'normal',
    ) {
    }

    public function run(
        array $command,
        array $environment,
        int $timeoutSeconds,
        string $operation
    ): void {
        $this->calls[] = [
            'command' => $command,
            'environment' => $environment,
            'timeout' => $timeoutSeconds,
            'operation' => $operation,
        ];

        if ($operation !== 'openssl-cms-sign') {
            return;
        }

        if ($this->failSigning) {
            throw new RuntimeException(
                'synthetic signing failure'
            );
        }

        $outIndex =
            array_search(
                '-out',
                $command,
                true
            );

        if (
            ! is_int($outIndex)
            || ! isset(
                $command[
                    $outIndex + 1
                ]
            )
        ) {
            throw new RuntimeException(
                'synthetic output path missing'
            );
        }

        $path =
            $command[
                $outIndex + 1
            ];

        if ($this->outputMode === 'missing') {
            return;
        }

        if ($this->outputMode === 'empty') {
            file_put_contents(
                $path,
                ''
            );

            return;
        }

        file_put_contents(
            $path,
            self::SYNTHETIC_DER
        );
    }

    /**
     * @return array{command:list<string>,environment:array<string,string>,timeout:int,operation:string}
     */
    public function signCall(): array
    {
        foreach ($this->calls as $call) {
            if (
                $call['operation']
                === 'openssl-cms-sign'
            ) {
                return $call;
            }
        }

        throw new RuntimeException(
            'Synthetic sign call was not recorded.'
        );
    }

    public function countOperation(
        string $operation
    ): int {
        return count(
            array_filter(
                $this->calls,
                static fn (array $call): bool =>
                    $call['operation']
                    === $operation
            )
        );
    }
}
