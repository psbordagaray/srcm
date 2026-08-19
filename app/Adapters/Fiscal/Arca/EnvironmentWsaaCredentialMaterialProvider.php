<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsaaCredentialMaterial;
use App\Domain\Fiscal\WsaaCredentialMaterialProvider;
use App\Domain\Fiscal\WsaaCredentialMaterialReferenceStore;
use App\Domain\Fiscal\WsaaCredentialMaterialValidator;
use App\Enums\FiscalEnvironment;
use DomainException;

final class EnvironmentWsaaCredentialMaterialProvider implements
    WsaaCredentialMaterialProvider
{
    public const ROOT_ENVIRONMENT_KEY =
        'SRCM_ARCA_WSAA_CREDENTIAL_ROOT';

    private const FILE_REFERENCE_PREFIX = 'file:';
    private const ENV_REFERENCE_PREFIX = 'env:';
    private const MAX_REFERENCE_PATH_LENGTH = 1024;
    private const MAX_CREDENTIAL_FILE_BYTES = 1048576;

    public function __construct(
        private readonly WsaaCredentialMaterialReferenceStore $references,
        private readonly WsaaCredentialMaterialValidator $validator,
    ) {
    }

    public function forRequest(
        WsaaAccessTicketRequest $request
    ): WsaaCredentialMaterial {
        if (
            $request->environment
                !== FiscalEnvironment::Homologation
        ) {
            throw new DomainException(
                'La resolución de material WSAA de producción permanece bloqueada.'
            );
        }

        $reference = $this->references->forRequest(
            $request
        );

        $root = $this->credentialRoot();

        $certificatePath = $this->resolveFileReference(
            $reference->certificateReference(),
            $root,
            'certificado'
        );

        $privateKeyPath = $this->resolveFileReference(
            $reference->privateKeyReference(),
            $root,
            'clave privada'
        );

        $privateKeyPassphrase =
            $this->resolvePassphraseReference(
                $reference->privateKeyPassphraseReference()
            );

        $certificatePem = $this->readCredentialFile(
            $certificatePath,
            'certificado'
        );

        $privateKeyPem = $this->readCredentialFile(
            $privateKeyPath,
            'clave privada'
        );

        $this->validator->assertValid(
            $certificatePem,
            $privateKeyPem,
            $privateKeyPassphrase
        );

        return new WsaaCredentialMaterial(
            organizationId: $reference->organizationId,
            environment: $reference->environment,
            service: $reference->service,
            issuerCuit: $reference->issuerCuit,
            certificatePem: $certificatePem,
            privateKeyPem: $privateKeyPem,
            privateKeyPassphrase: $privateKeyPassphrase,
        );
    }

    private function credentialRoot(): string
    {
        $raw = $this->environmentValue(
            self::ROOT_ENVIRONMENT_KEY
        );

        if (
            $raw === null
            || $raw === ''
            || $raw !== trim($raw)
            || str_contains($raw, "\0")
            || str_contains($raw, "\r")
            || str_contains($raw, "\n")
        ) {
            throw new DomainException(
                'La raíz externa de credenciales WSAA no está configurada.'
            );
        }

        $root = realpath($raw);

        if (
            ! is_string($root)
            || ! is_dir($root)
            || ! is_readable($root)
        ) {
            throw new DomainException(
                'La raíz externa de credenciales WSAA no está disponible.'
            );
        }

        $repository = realpath(base_path());

        if (! is_string($repository)) {
            throw new DomainException(
                'No se pudo resolver la raíz del repositorio para validar aislamiento WSAA.'
            );
        }

        if ($this->pathsOverlap($root, $repository)) {
            throw new DomainException(
                'La raíz de credenciales WSAA debe estar aislada del repositorio.'
            );
        }

        return $root;
    }

    private function resolveFileReference(
        string $reference,
        string $root,
        string $label
    ): string {
        if (! str_starts_with(
            $reference,
            self::FILE_REFERENCE_PREFIX
        )) {
            throw new DomainException(
                "La referencia de {$label} WSAA debe usar el esquema file: relativo."
            );
        }

        $relative = substr(
            $reference,
            strlen(self::FILE_REFERENCE_PREFIX)
        );

        if (
            $relative === ''
            || strlen($relative)
                > self::MAX_REFERENCE_PATH_LENGTH
            || str_contains($relative, "\\")
            || str_contains($relative, ':')
            || str_starts_with($relative, '/')
            || str_contains($relative, "\0")
            || str_contains($relative, "\r")
            || str_contains($relative, "\n")
        ) {
            throw new DomainException(
                "La referencia de {$label} WSAA no es una ruta relativa válida."
            );
        }

        $segments = explode('/', $relative);

        foreach ($segments as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match(
                    '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',
                    $segment
                ) !== 1
            ) {
                throw new DomainException(
                    "La referencia de {$label} WSAA contiene un segmento inválido."
                );
            }
        }

        $candidate =
            $root
            . DIRECTORY_SEPARATOR
            . implode(
                DIRECTORY_SEPARATOR,
                $segments
            );

        $resolved = realpath($candidate);

        if (
            ! is_string($resolved)
            || ! is_file($resolved)
            || ! is_readable($resolved)
            || ! $this->isWithin(
                $resolved,
                $root
            )
        ) {
            throw new DomainException(
                "No se pudo resolver de forma segura la referencia de {$label} WSAA."
            );
        }

        return $resolved;
    }

    private function resolvePassphraseReference(
        ?string $reference
    ): ?string {
        if ($reference === null) {
            return null;
        }

        if (! str_starts_with(
            $reference,
            self::ENV_REFERENCE_PREFIX
        )) {
            throw new DomainException(
                'La passphrase WSAA debe resolverse mediante una referencia env:.'
            );
        }

        $key = substr(
            $reference,
            strlen(self::ENV_REFERENCE_PREFIX)
        );

        if (
            preg_match(
                '/^[A-Z][A-Z0-9_]{0,127}$/D',
                $key
            ) !== 1
        ) {
            throw new DomainException(
                'La referencia env: de passphrase WSAA no es válida.'
            );
        }

        $value = $this->environmentValue($key);

        if (
            $value === null
            || $value === ''
            || strlen($value) > 4096
            || str_contains($value, "\0")
        ) {
            throw new DomainException(
                'La passphrase WSAA referenciada no está disponible.'
            );
        }

        return $value;
    }

    private function readCredentialFile(
        string $path,
        string $label
    ): string {
        $size = @filesize($path);

        if (
            ! is_int($size)
            || $size <= 0
            || $size > self::MAX_CREDENTIAL_FILE_BYTES
        ) {
            throw new DomainException(
                "El archivo de {$label} WSAA posee un tamaño inválido."
            );
        }

        $contents = @file_get_contents($path);

        if (
            ! is_string($contents)
            || $contents === ''
        ) {
            throw new DomainException(
                "No se pudo leer el archivo de {$label} WSAA."
            );
        }

        return $contents;
    }

    private function environmentValue(
        string $key
    ): ?string {
        foreach (
            [
                $_SERVER[$key] ?? null,
                $_ENV[$key] ?? null,
                getenv($key),
            ] as $value
        ) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function pathsOverlap(
        string $first,
        string $second
    ): bool {
        return
            $this->isWithin($first, $second)
            || $this->isWithin($second, $first);
    }

    private function isWithin(
        string $candidate,
        string $root
    ): bool {
        $candidate = $this->comparisonPath($candidate);
        $root = $this->comparisonPath($root);

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
        $path = rtrim(
            str_replace('\\', '/', $path),
            '/'
        );

        return PHP_OS_FAMILY === 'Windows'
            ? strtolower($path)
            : $path;
    }
}
