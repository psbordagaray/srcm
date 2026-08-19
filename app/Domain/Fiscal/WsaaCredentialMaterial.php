<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;
use DomainException;

final readonly class WsaaCredentialMaterial
{
    public function __construct(
        public int $organizationId,
        public FiscalEnvironment $environment,
        public string $service,
        public string $issuerCuit,
        private string $certificatePem,
        private string $privateKeyPem,
        private ?string $privateKeyPassphrase = null,
    ) {
        if ($this->organizationId <= 0) {
            throw new DomainException(
                'La organización del material WSAA debe ser positiva.'
            );
        }

        if (
            $this->service === ''
            || $this->service !== trim($this->service)
            || strlen($this->service) > 128
            || preg_match(
                '/^[A-Za-z0-9._:-]+$/D',
                $this->service
            ) !== 1
        ) {
            throw new DomainException(
                'El servicio ligado al material WSAA no es válido.'
            );
        }

        if (
            preg_match(
                '/^[0-9]{11}$/D',
                $this->issuerCuit
            ) !== 1
        ) {
            throw new DomainException(
                'El CUIT ligado al material WSAA debe contener 11 dígitos.'
            );
        }

        if (
            ! str_contains(
                $this->certificatePem,
                '-----BEGIN CERTIFICATE-----'
            )
            || ! str_contains(
                $this->certificatePem,
                '-----END CERTIFICATE-----'
            )
        ) {
            throw new DomainException(
                'El certificado WSAA no posee forma PEM reconocible.'
            );
        }

        if (
            preg_match(
                '/-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/',
                $this->privateKeyPem
            ) !== 1
            || preg_match(
                '/-----END (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/',
                $this->privateKeyPem
            ) !== 1
        ) {
            throw new DomainException(
                'La clave privada WSAA no posee forma PEM reconocible.'
            );
        }

        if (
            $this->privateKeyPassphrase !== null
            && (
                $this->privateKeyPassphrase === ''
                || strlen($this->privateKeyPassphrase) > 4096
                || str_contains(
                    $this->privateKeyPassphrase,
                    "\0"
                )
            )
        ) {
            throw new DomainException(
                'La passphrase de clave privada WSAA no es válida.'
            );
        }
    }

    public function certificatePem(): string
    {
        return $this->certificatePem;
    }

    public function privateKeyPem(): string
    {
        return $this->privateKeyPem;
    }

    public function privateKeyPassphrase(): ?string
    {
        return $this->privateKeyPassphrase;
    }

    public function __serialize(): array
    {
        throw new DomainException(
            'El material de credencial WSAA no es serializable.'
        );
    }

    public function __debugInfo(): array
    {
        return [
            'organizationId' => $this->organizationId,
            'environment' => $this->environment->value,
            'service' => $this->service,
            'issuerCuit' => $this->issuerCuit,
            'certificatePem' => '[REDACTED]',
            'privateKeyPem' => '[REDACTED]',
            'privateKeyPassphrase' =>
                $this->privateKeyPassphrase === null
                    ? null
                    : '[REDACTED]',
        ];
    }
}
