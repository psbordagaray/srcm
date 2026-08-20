<?php

namespace Tests\Feature\Fiscal;

use App\Adapters\Fiscal\Arca\DomWsfeCompUltimoAutorizadoSoapResponseParser;
use App\Adapters\Fiscal\Arca\DomWsfeCompUltimoAutorizadoSoapSerializer;
use App\Adapters\Fiscal\Arca\GuzzleWsfeCompUltimoAutorizadoSoapTransport;
use App\Domain\Fiscal\FiscalAuthorizationTransport;
use App\Domain\Fiscal\FiscalRemoteSequenceAuthority;
use App\Domain\Fiscal\FiscalRemoteSequenceQuery;
use App\Domain\Fiscal\WsaaAccessTicket;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoap11Call;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapResponseParser;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapSerializer;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapTransport;
use App\Enums\FiscalEnvironment;
use Carbon\CarbonImmutable;
use DomainException;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

class WsfeRemoteSequenceTransportBoundaryTest extends TestCase
{
    public function test_container_binds_only_remote_sequence_wire_boundary(): void
    {
        $this->assertInstanceOf(
            DomWsfeCompUltimoAutorizadoSoapSerializer::class,
            app(WsfeCompUltimoAutorizadoSoapSerializer::class)
        );
        $this->assertInstanceOf(
            DomWsfeCompUltimoAutorizadoSoapResponseParser::class,
            app(WsfeCompUltimoAutorizadoSoapResponseParser::class)
        );
        $this->assertInstanceOf(
            GuzzleWsfeCompUltimoAutorizadoSoapTransport::class,
            app(WsfeCompUltimoAutorizadoSoapTransport::class)
        );
        $this->assertFalse(app()->bound(FiscalRemoteSequenceAuthority::class));
        $this->assertFalse(app()->bound(FiscalAuthorizationTransport::class));
    }

    public function test_call_and_serializer_build_exact_auth_point_and_voucher_type(): void
    {
        $call = $this->sequenceCall();
        $parameters = $call->operationParameters();
        $this->assertSame(12, $parameters['PtoVta']);
        $this->assertSame(6, $parameters['CbteTipo']);
        $this->assertSame('20123456786', $parameters['Auth']['Cuit']);
        $this->assertSame('SYNTHETIC-TOKEN', $parameters['Auth']['Token']);
        $this->assertSame('SYNTHETIC-SIGN', $parameters['Auth']['Sign']);

        $serializer = new DomWsfeCompUltimoAutorizadoSoapSerializer;
        $this->assertSame(
            '"http://ar.gov.afip.dif.FEV1/FECompUltimoAutorizado"',
            $serializer->headers($call)['SOAPAction']
        );
        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($serializer->body($call)));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('soap', WsfeCompUltimoAutorizadoSoap11Call::ENVELOPE_NAMESPACE);
        $xpath->registerNamespace('ar', WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE);
        $this->assertSame(1, $xpath->query('/soap:Envelope/soap:Body/ar:FECompUltimoAutorizado/ar:Auth/ar:Token')?->length);
        $this->assertSame('12', trim((string) $xpath->evaluate('string(/soap:Envelope/soap:Body/ar:FECompUltimoAutorizado/ar:PtoVta)')));
        $this->assertSame('6', trim((string) $xpath->evaluate('string(/soap:Envelope/soap:Body/ar:FECompUltimoAutorizado/ar:CbteTipo)')));

        ob_start();
        var_dump($call);
        $debug = (string) ob_get_clean();
        $this->assertStringContainsString('[REDACTED]', $debug);
        $this->assertStringNotContainsString('SYNTHETIC-TOKEN', $debug);
        $this->expectException(DomainException::class);
        serialize($call);
    }

    public function test_parser_returns_exact_remote_state_and_zero_is_valid_boundary(): void
    {
        $parser = new DomWsfeCompUltimoAutorizadoSoapResponseParser;
        $state = $parser->parse(200, self::successSoap(41), $this->remoteSequenceQuery());
        $this->assertSame(FiscalEnvironment::Homologation, $state->environment);
        $this->assertSame(12, $state->pointOfSaleNumber);
        $this->assertSame(6, $state->voucherTypeCode);
        $this->assertSame(41, $state->lastAuthorizedNumber);

        $zero = $parser->parse(200, self::successSoap(null), $this->remoteSequenceQuery());
        $this->assertSame(0, $zero->lastAuthorizedNumber);
    }

    public function test_parser_fails_closed_on_identity_errors_and_malformed_xml(): void
    {
        $parser = new DomWsfeCompUltimoAutorizadoSoapResponseParser;
        foreach ([
            self::successSoap(41, 13, 6),
            self::errorSoap(),
            '<!DOCTYPE x><x/>',
            '<not-xml',
        ] as $xml) {
            try {
                $parser->parse(200, $xml, $this->remoteSequenceQuery());
                $this->fail('La respuesta inválida debía fallar cerrada.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_homologation_transport_uses_exact_endpoint_and_safe_options(): void
    {
        $seenRequest = null;
        $seenOptions = null;
        $calls = 0;
        $handler = static function (RequestInterface $request, array $options) use (&$seenRequest, &$seenOptions, &$calls) {
            $calls++;
            $seenRequest = $request;
            $seenOptions = $options;
            return new FulfilledPromise(new Response(200, ['Content-Type' => 'text/xml'], self::successSoap(41)));
        };
        $state = $this->transport($handler)->exchange($this->sequenceCall());
        $this->assertSame(41, $state->lastAuthorizedNumber);
        $this->assertSame(1, $calls);
        $this->assertSame('POST', $seenRequest?->getMethod());
        $this->assertSame('https://wswhomo.afip.gov.ar/wsfev1/service.asmx', (string) $seenRequest?->getUri());
        $this->assertFalse($seenOptions['allow_redirects']);
        $this->assertFalse($seenOptions['http_errors']);
        $this->assertTrue($seenOptions['verify']);
        $this->assertSame(5.0, $seenOptions['connect_timeout']);
        $this->assertSame(15.0, $seenOptions['timeout']);
        $this->assertTrue($seenOptions['stream']);
        $this->assertIsCallable($seenOptions['on_headers']);
    }

    public function test_production_rejects_before_http(): void
    {
        $calls = 0;
        $handler = static function (RequestInterface $request, array $options) use (&$calls) {
            $calls++;
            return new FulfilledPromise(new Response(200, [], self::successSoap(1)));
        };
        $query = new FiscalRemoteSequenceQuery(91, FiscalEnvironment::Production, 12, 6);
        try {
            $this->transport($handler)->exchange($this->sequenceCall($query));
            $this->fail('Producción debía permanecer bloqueada.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('producción permanece bloqueado', $exception->getMessage());
        }
        $this->assertSame(0, $calls);
    }

    private function remoteSequenceQuery(): FiscalRemoteSequenceQuery
    {
        return new FiscalRemoteSequenceQuery(91, FiscalEnvironment::Homologation, 12, 6);
    }

    private function sequenceCall(?FiscalRemoteSequenceQuery $query = null): WsfeCompUltimoAutorizadoSoap11Call
    {
        $query ??= $this->remoteSequenceQuery();
        $request = new WsaaAccessTicketRequest(
            $query->organizationId,
            $query->environment,
            'wsfe',
            '20123456786'
        );
        $now = CarbonImmutable::parse('2026-08-19T20:00:00+00:00');
        $ticket = new WsaaAccessTicket(
            $query->organizationId,
            $query->environment,
            'wsfe',
            '20123456786',
            'SYNTHETIC-TOKEN',
            'SYNTHETIC-SIGN',
            $now->subMinute(),
            $now->addHour(),
        );
        return new WsfeCompUltimoAutorizadoSoap11Call($query, $request, $ticket, $now);
    }

    private function transport(callable $handler): GuzzleWsfeCompUltimoAutorizadoSoapTransport
    {
        return new GuzzleWsfeCompUltimoAutorizadoSoapTransport(
            new DomWsfeCompUltimoAutorizadoSoapSerializer,
            new DomWsfeCompUltimoAutorizadoSoapResponseParser,
            new Client(['handler' => $handler]),
        );
    }

    private static function successSoap(?int $number, int $point = 12, int $type = 6): string
    {
        $cbte = $number === null ? '' : '<CbteNro>' . $number . '</CbteNro>';
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body><FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompUltimoAutorizadoResult>'
            . '<PtoVta>' . $point . '</PtoVta><CbteTipo>' . $type . '</CbteTipo>' . $cbte
            . '<Events><Evt><Code>1</Code><Msg>Synthetic event</Msg></Evt></Events>'
            . '</FECompUltimoAutorizadoResult></FECompUltimoAutorizadoResponse></soap:Body></soap:Envelope>';
    }

    private static function errorSoap(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body><FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompUltimoAutorizadoResult><PtoVta>12</PtoVta><CbteTipo>6</CbteTipo>'
            . '<Errors><Err><Code>11002</Code><Msg>Synthetic provider detail must remain hidden</Msg></Err></Errors>'
            . '</FECompUltimoAutorizadoResult></FECompUltimoAutorizadoResponse></soap:Body></soap:Envelope>';
    }
}
