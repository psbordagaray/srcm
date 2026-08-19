<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;

final class OfficialArcaSoapEndpointMap implements ArcaSoapEndpointMap
{
    public function for(
        FiscalEnvironment $environment
    ): ArcaSoapEndpointSet {
        return match ($environment) {
            FiscalEnvironment::Homologation =>
                new ArcaSoapEndpointSet(
                    wsaaLoginCmsUrl:
                        'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
                    wsfeServiceUrl:
                        'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
                ),
            FiscalEnvironment::Production =>
                new ArcaSoapEndpointSet(
                    wsaaLoginCmsUrl:
                        'https://wsaa.afip.gov.ar/ws/services/LoginCms',
                    wsfeServiceUrl:
                        'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
                ),
        };
    }
}
