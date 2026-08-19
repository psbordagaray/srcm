<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsaaCmsDigestPolicy;
use App\Enums\FiscalEnvironment;
use App\Enums\WsaaCmsDigestAlgorithm;
use DomainException;

final class OfficialWsaaCmsDigestPolicy implements WsaaCmsDigestPolicy
{
    public function forEnvironment(
        FiscalEnvironment $environment
    ): WsaaCmsDigestAlgorithm {
        if ($environment !== FiscalEnvironment::Homologation) {
            throw new DomainException(
                'La política de digest WSAA de producción permanece bloqueada hasta validación provider-real.'
            );
        }

        return WsaaCmsDigestAlgorithm::Sha1;
    }
}
