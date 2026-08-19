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
                'El CUIT emisor ligado al Ticket de Acceso debe contener exactamente 11 dígitos.'
            );
        }
    }
}
