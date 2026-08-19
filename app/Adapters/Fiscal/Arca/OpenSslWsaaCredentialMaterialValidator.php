<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsaaCredentialMaterialValidator;
use DomainException;

final class OpenSslWsaaCredentialMaterialValidator implements
    WsaaCredentialMaterialValidator
{
    public function assertValid(
        string $certificatePem,
        string $privateKeyPem,
        ?string $privateKeyPassphrase
    ): void {
        $this->drainErrors();

        $certificate = @openssl_x509_read(
            $certificatePem
        );

        if ($certificate === false) {
            $this->drainErrors();

            throw new DomainException(
                'El certificado WSAA no puede ser interpretado por OpenSSL.'
            );
        }

        $privateKey = @openssl_pkey_get_private(
            $privateKeyPem,
            $privateKeyPassphrase ?? ''
        );

        if ($privateKey === false) {
            $this->drainErrors();

            throw new DomainException(
                'La clave privada WSAA no puede ser interpretada por OpenSSL.'
            );
        }

        $matches = @openssl_x509_check_private_key(
            $certificate,
            $privateKey
        );

        $this->drainErrors();

        if ($matches !== true) {
            throw new DomainException(
                'El certificado y la clave privada WSAA no forman el mismo par criptográfico.'
            );
        }
    }

    private function drainErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Intencionalmente descartado: nunca exponer material ni detalles OpenSSL.
        }
    }
}
