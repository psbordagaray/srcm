<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;
use DomainException;

final readonly class WsaaCredentialMaterialReference
{
    public function __construct(
        public int $organizationId,
        public FiscalEnvironment $environment,
        public string $service,
        public string $issuerCuit,
        private string $certificateReference,
        private string $privateKeyReference,
        private ?string $privateKeyPassphraseReference = null,
    ) {
        if ($this->organizationId <= 0) {
            throw new DomainException(
                'La organización del material WSAA debe ser positiva.'
            );
        }

        WsaaServiceName::assertValid(
            $this->service
        );

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

        self::assertReference(
            $this->certificateReference,
            'certificado'
        );
        self::assertReference(
            $this->privateKeyReference,
            'clave privada'
        );

        if ($this->privateKeyPassphraseReference !== null) {
            self::assertReference(
                $this->privateKeyPassphraseReference,
                'passphrase'
            );
        }
    }

    public function certificateReference(): string
    {
        return $this->certificateReference;
    }

    public function privateKeyReference(): string
    {
        return $this->privateKeyReference;
    }

    public function privateKeyPassphraseReference(): ?string
    {
        return $this->privateKeyPassphraseReference;
    }

    public function __serialize(): array
    {
        throw new DomainException(
            'Las referencias de material WSAA no son serializables.'
        );
    }

    public function __debugInfo(): array
    {
        return [
            'organizationId' => $this->organizationId,
            'environment' => $this->environment->value,
            'service' => $this->service,
            'issuerCuit' => $this->issuerCuit,
            'certificateReference' => '[REDACTED]',
            'privateKeyReference' => '[REDACTED]',
            'privateKeyPassphraseReference' =>
                $this->privateKeyPassphraseReference === null
                    ? null
                    : '[REDACTED]',
        ];
    }

    private static function assertReference(
        string $reference,
        string $label
    ): void {
        if (
            $reference === ''
            || $reference !== trim($reference)
            || strlen($reference) > 4096
            || str_contains($reference, "\0")
            || str_contains($reference, "\r")
            || str_contains($reference, "\n")
            || str_contains($reference, '-----BEGIN ')
        ) {
            throw new DomainException(
                "La referencia de {$label} WSAA no es válida."
            );
        }
    }
}
