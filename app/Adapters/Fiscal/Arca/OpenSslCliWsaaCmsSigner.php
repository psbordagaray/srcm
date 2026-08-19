<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsaaCmsSigner;
use App\Domain\Fiscal\WsaaCredentialMaterial;
use App\Domain\Fiscal\WsaaSignedCms;
use App\Domain\Fiscal\WsaaTra;
use App\Enums\FiscalEnvironment;
use App\Enums\WsaaCmsDigestAlgorithm;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class OpenSslCliWsaaCmsSigner implements
    WsaaCmsSigner
{
    private const OPENSSL_BINARY = 'openssl';
    private const SIGN_TIMEOUT_SECONDS = 30;
    private const ACL_TIMEOUT_SECONDS = 30;
    private const MAX_INPUT_BYTES = 1048576;
    private const MAX_CMS_BYTES = 4194304;

    public function __construct(
        private readonly WsaaCmsProcessRunner $processes
    ) {
    }

    public function sign(
        WsaaTra $tra,
        WsaaCredentialMaterial $material,
        WsaaCmsDigestAlgorithm $digest
    ): WsaaSignedCms {
        if (
            $material->environment
                !== FiscalEnvironment::Homologation
        ) {
            throw new RuntimeException(
                'El signing CMS WSAA de producción permanece bloqueado.'
            );
        }

        if ($material->service !== $tra->service) {
            throw new RuntimeException(
                'El material WSAA no corresponde al servicio del TRA.'
            );
        }

        $workspace = null;
        $signedCms = null;
        $failed = false;

        try {
            $workspace =
                $this->createWorkspace();

            $traPath =
                $this->writeWorkspaceFile(
                    $workspace,
                    'tra.xml',
                    $tra->xml(),
                    self::MAX_INPUT_BYTES
                );

            $certificatePath =
                $this->writeWorkspaceFile(
                    $workspace,
                    'certificate.pem',
                    $material->certificatePem(),
                    self::MAX_INPUT_BYTES
                );

            $privateKeyPath =
                $this->writeWorkspaceFile(
                    $workspace,
                    'private.key',
                    $material->privateKeyPem(),
                    self::MAX_INPUT_BYTES
                );

            $cmsPath =
                $workspace
                . DIRECTORY_SEPARATOR
                . 'signed.der';

            $passphraseEnvironmentKey =
                'SRCM_WSAA_CMS_PASS_'
                . strtoupper(
                    bin2hex(
                        random_bytes(12)
                    )
                );

            $environment =
                $this->currentEnvironment();

            $environment[
                $passphraseEnvironmentKey
            ] =
                $material->privateKeyPassphrase()
                ?? '';

            try {
                $this->processes->run(
                    [
                        self::OPENSSL_BINARY,
                        'cms',
                        '-sign',
                        '-in',
                        $traPath,
                        '-signer',
                        $certificatePath,
                        '-inkey',
                        $privateKeyPath,
                        '-passin',
                        'env:'
                            . $passphraseEnvironmentKey,
                        '-nodetach',
                        '-binary',
                        '-outform',
                        'DER',
                        '-md',
                        $digest->value,
                        '-out',
                        $cmsPath,
                    ],
                    $environment,
                    self::SIGN_TIMEOUT_SECONDS,
                    'openssl-cms-sign'
                );
            } finally {
                unset(
                    $environment[
                        $passphraseEnvironmentKey
                    ]
                );
            }

            $this->hardenFile(
                $cmsPath
            );

            $cmsDer =
                $this->readCmsDer(
                    $cmsPath
                );

            $signedCms =
                new WsaaSignedCms(
                    base64_encode(
                        $cmsDer
                    ),
                    $digest
                );
        } catch (Throwable) {
            $failed = true;
        }

        if ($workspace !== null) {
            try {
                $this->cleanupWorkspace(
                    $workspace
                );
            } catch (Throwable) {
                throw new RuntimeException(
                    'No se pudo limpiar el workspace sensible WSAA.'
                );
            }
        }

        if (
            $failed
            || ! $signedCms instanceof WsaaSignedCms
        ) {
            throw new RuntimeException(
                'No se pudo producir el CMS WSAA.'
            );
        }

        return $signedCms;
    }

    private function createWorkspace(): string
    {
        $tempRoot =
            realpath(
                sys_get_temp_dir()
            );

        $repository =
            realpath(
                base_path()
            );

        if (
            ! is_string($tempRoot)
            || ! is_string($repository)
            || $this->pathsOverlap(
                $tempRoot,
                $repository
            )
        ) {
            throw new RuntimeException(
                'No existe una raíz temporal segura para signing WSAA.'
            );
        }

        $workspace =
            $tempRoot
            . DIRECTORY_SEPARATOR
            . 'srcm_wsaa_cms_'
            . bin2hex(
                random_bytes(16)
            );

        if (
            ! mkdir(
                $workspace,
                0700,
                false
            )
            || ! is_dir($workspace)
        ) {
            throw new RuntimeException(
                'No se pudo crear el workspace sensible WSAA.'
            );
        }

        try {
            $resolved =
                realpath(
                    $workspace
                );

            if (
                ! is_string($resolved)
                || ! $this->isWithin(
                    $resolved,
                    $tempRoot
                )
                || $this->pathsOverlap(
                    $resolved,
                    $repository
                )
            ) {
                throw new RuntimeException(
                    'El workspace sensible WSAA no es seguro.'
                );
            }

            $this->hardenDirectory(
                $resolved
            );

            return $resolved;
        } catch (Throwable) {
            $this->bestEffortCleanup(
                $workspace
            );

            throw new RuntimeException(
                'No se pudo asegurar el workspace sensible WSAA.'
            );
        }
    }

    private function writeWorkspaceFile(
        string $workspace,
        string $name,
        string $contents,
        int $maxBytes
    ): string {
        $length =
            strlen(
                $contents
            );

        if (
            $length < 1
            || $length > $maxBytes
            || preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',
                $name
            ) !== 1
        ) {
            throw new RuntimeException(
                'El input temporal WSAA es inválido.'
            );
        }

        $path =
            $workspace
            . DIRECTORY_SEPARATOR
            . $name;

        $written =
            @file_put_contents(
                $path,
                $contents,
                LOCK_EX
            );

        if (
            ! is_int($written)
            || $written !== $length
            || ! is_file($path)
        ) {
            throw new RuntimeException(
                'No se pudo escribir un input temporal WSAA.'
            );
        }

        $this->hardenFile(
            $path
        );

        return $path;
    }

    private function readCmsDer(
        string $path
    ): string {
        clearstatcache(
            true,
            $path
        );

        $size =
            @filesize(
                $path
            );

        if (
            ! is_int($size)
            || $size < 1
            || $size > self::MAX_CMS_BYTES
        ) {
            throw new RuntimeException(
                'El CMS WSAA generado posee un tamaño inválido.'
            );
        }

        $contents =
            @file_get_contents(
                $path
            );

        if (
            ! is_string($contents)
            || strlen($contents) !== $size
        ) {
            throw new RuntimeException(
                'No se pudo leer el CMS WSAA generado.'
            );
        }

        return $contents;
    }

    private function hardenDirectory(
        string $path
    ): void {
        if (PHP_OS_FAMILY === 'Windows') {
            $identity =
                $this->windowsIdentity();

            $this->processes->run(
                [
                    'icacls.exe',
                    $path,
                    '/inheritance:r',
                    '/grant:r',
                    $identity
                        . ':(OI)(CI)F',
                ],
                $this->currentEnvironment(),
                self::ACL_TIMEOUT_SECONDS,
                'acl-directory'
            );

            return;
        }

        if (
            ! @chmod(
                $path,
                0700
            )
        ) {
            throw new RuntimeException(
                'No se pudo restringir el workspace sensible WSAA.'
            );
        }
    }

    private function hardenFile(
        string $path
    ): void {
        if (
            ! is_file($path)
        ) {
            throw new RuntimeException(
                'El archivo temporal WSAA no existe.'
            );
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $identity =
                $this->windowsIdentity();

            $this->processes->run(
                [
                    'icacls.exe',
                    $path,
                    '/inheritance:r',
                    '/grant:r',
                    $identity . ':F',
                ],
                $this->currentEnvironment(),
                self::ACL_TIMEOUT_SECONDS,
                'acl-file'
            );

            return;
        }

        if (
            ! @chmod(
                $path,
                0600
            )
        ) {
            throw new RuntimeException(
                'No se pudo restringir un archivo temporal WSAA.'
            );
        }
    }

    private function windowsIdentity(): string
    {
        $user =
            getenv(
                'USERNAME'
            );

        $domain =
            getenv(
                'USERDOMAIN'
            );

        if (
            ! is_string($user)
            || $user === ''
            || $user !== trim($user)
            || str_contains($user, "\0")
            || str_contains($user, "\r")
            || str_contains($user, "\n")
        ) {
            throw new RuntimeException(
                'No se pudo determinar la identidad local para ACL WSAA.'
            );
        }

        if (
            is_string($domain)
            && $domain !== ''
            && $domain === trim($domain)
            && ! str_contains($domain, "\0")
            && ! str_contains($domain, "\r")
            && ! str_contains($domain, "\n")
        ) {
            return
                $domain
                . '\\'
                . $user;
        }

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function currentEnvironment(): array
    {
        $environment = [];
        $raw = getenv();

        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (
                    is_string($key)
                    && $key !== ''
                    && is_string($value)
                ) {
                    $environment[$key] =
                        $value;
                }
            }
        }

        foreach (
            [
                $_SERVER,
                $_ENV,
            ] as $source
        ) {
            foreach ($source as $key => $value) {
                if (
                    is_string($key)
                    && $key !== ''
                    && is_string($value)
                    && ! array_key_exists(
                        $key,
                        $environment
                    )
                ) {
                    $environment[$key] =
                        $value;
                }
            }
        }

        return $environment;
    }

    private function cleanupWorkspace(
        string $workspace
    ): void {
        $this->bestEffortCleanup(
            $workspace
        );

        clearstatcache(
            true,
            $workspace
        );

        if (file_exists($workspace)) {
            throw new RuntimeException(
                'Persistieron artefactos sensibles WSAA.'
            );
        }
    }

    private function bestEffortCleanup(
        string $workspace
    ): void {
        if (! is_dir($workspace)) {
            return;
        }

        $iterator =
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $workspace,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir(
                    $entry->getPathname()
                );
            } else {
                @unlink(
                    $entry->getPathname()
                );
            }
        }

        @rmdir(
            $workspace
        );
    }

    private function pathsOverlap(
        string $first,
        string $second
    ): bool {
        return
            $this->isWithin(
                $first,
                $second
            )
            || $this->isWithin(
                $second,
                $first
            );
    }

    private function isWithin(
        string $candidate,
        string $root
    ): bool {
        $candidate =
            $this->comparisonPath(
                $candidate
            );

        $root =
            $this->comparisonPath(
                $root
            );

        return
            $candidate === $root
            || str_starts_with(
                $candidate,
                $root . '/'
            );
    }

    private function comparisonPath(
        string $path
    ): string {
        $path =
            rtrim(
                str_replace(
                    '\\',
                    '/',
                    $path
                ),
                '/'
            );

        return
            PHP_OS_FAMILY === 'Windows'
                ? strtolower($path)
                : $path;
    }
}
