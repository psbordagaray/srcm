<?php

namespace Tests\Feature\Fiscal;

use App\Domain\Fiscal\ArcaSoapEndpointMap;
use App\Domain\Fiscal\ArcaSoapEndpointSet;
use App\Domain\Fiscal\OfficialArcaSoapEndpointMap;
use App\Enums\FiscalEnvironment;
use DomainException;
use ReflectionClass;
use Tests\TestCase;

class ArcaEnvironmentEndpointMapTest extends TestCase
{
    public function test_map_is_explicit_contract(): void
    {
        $this->assertTrue(
            (new ReflectionClass(ArcaSoapEndpointMap::class))->isInterface()
        );

        $this->assertInstanceOf(
            ArcaSoapEndpointMap::class,
            new OfficialArcaSoapEndpointMap
        );
    }

    public function test_homologation_resolves_official_endpoints(): void
    {
        $set = (new OfficialArcaSoapEndpointMap)
            ->for(FiscalEnvironment::Homologation);

        $this->assertSame(
            'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
            $set->wsaaLoginCmsUrl
        );
        $this->assertSame(
            'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
            $set->wsfeServiceUrl
        );
        $this->assertSame(
            'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL',
            $set->wsfeWsdlUrl
        );
    }

    public function test_production_resolves_official_endpoints_without_enabling_it(): void
    {
        $set = (new OfficialArcaSoapEndpointMap)
            ->for(FiscalEnvironment::Production);

        $this->assertSame(
            'https://wsaa.afip.gov.ar/ws/services/LoginCms',
            $set->wsaaLoginCmsUrl
        );
        $this->assertSame(
            'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
            $set->wsfeServiceUrl
        );
        $this->assertSame(
            'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL',
            $set->wsfeWsdlUrl
        );
    }

    public function test_endpoint_set_fails_closed_on_insecure_or_ambiguous_urls(): void
    {
        foreach ([
            [
                'http://wsaahomo.afip.gov.ar/ws/services/LoginCms',
                'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
            ],
            [
                'https://user:pass@example.test/LoginCms',
                'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
            ],
            [
                'https://wsaahomo.afip.gov.ar/ws/services/LoginCms#frag',
                'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
            ],
            [
                'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
                'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL',
            ],
        ] as [$wsaa, $wsfe]) {
            try {
                new ArcaSoapEndpointSet($wsaa, $wsfe);
                $this->fail('El endpoint inseguro o ambiguo debía fallar.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_map_has_no_network_auth_or_transport_behavior(): void
    {
        $source = file_get_contents(
            (string) (new ReflectionClass(
                OfficialArcaSoapEndpointMap::class
            ))->getFileName()
        );

        $this->assertIsString($source);

        foreach ([
            'SoapClient',
            'Http::',
            'curl_',
            'token()',
            'sign()',
            'WsaaAccessTicketProvider',
            'FiscalAuthorizationTransport',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }
}
