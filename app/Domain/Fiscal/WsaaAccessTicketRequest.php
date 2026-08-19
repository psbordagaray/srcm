<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;
use DomainException;

final readonly class WsaaAccessTicketRequest
{
    public function __construct(
        public int $organizationId,
        public FiscalEnvironment $environment,
        public string $service,
        public string $issuerCuit,
    ) {
        if ($this->organizationId <= 0) {
            throw new DomainException(
                'La organización del Ticket de Acceso WSAA debe ser positiva.'
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
                'El servicio WSAA debe ser una identidad explícita, no vacía y sin espacios.'
            );
        }

        if (
            preg_match(
                '/^[0-9]{11}$/D',
                $this->issuerCuit
            ) !== 1
        ) {
            throw new DomainException(
                'El CUIT emisor ligado al Ticket de Acceso debe contener exactamente 11 dígitos.'
            );
        }
    }
}
