<?php

namespace Tests\Feature\Fiscal;

use App\Adapters\Fiscal\Arca\DomWsfeFecaeSoapResponseParser;
use App\Adapters\Fiscal\Arca\DomWsfeFecaeSoapSerializer;
use App\Adapters\Fiscal\Arca\GuzzleWsfeFecaeSoapTransport;
use App\Domain\Fiscal\FiscalAuthorizationTransport;
use App\Domain\Fiscal\FiscalAuthorizationTransportRequest;
use App\Domain\Fiscal\FiscalRemoteSequenceAuthority;
use App\Domain\Fiscal\WsaaAccessTicket;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsfeFecaeDetailData;
use App\Domain\Fiscal\WsfeFecaeHeaderData;
use App\Domain\Fiscal\WsfeFecaeProviderResponseNormalizer;
use App\Domain\Fiscal\WsfeFecaeProviderResultConvergence;
use App\Domain\Fiscal\WsfeFecaeRequestData;
use App\Domain\Fiscal\WsfeFecaeSoap11Call;
use App\Domain\Fiscal\WsfeFecaeSoapResponseParser;
use App\Domain\Fiscal\WsfeFecaeSoapSerializer;
use App\Domain\Fiscal\WsfeFecaeSoapTransport;
use App\Enums\FiscalAuthorizationOutcome;
use App\Enums\FiscalEnvironment;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

class WsfeFecaeTransportBoundaryTest extends TestCase
{
    public function test_container_keeps_fecae_wire_boundary_with_runtime_binding(): void
    {
        $this->assertInstanceOf(
            DomWsfeFecaeSoapSerializer::class,
            app(WsfeFecaeSoapSerializer::class)
        );

        $this->assertInstanceOf(
            DomWsfeFecaeSoapResponseParser::class,
            app(WsfeFecaeSoapResponseParser::class)
        );

        $this->assertInstanceOf(
            GuzzleWsfeFecaeSoapTransport::class,
            app(WsfeFecaeSoapTransport::class)
        );

        $this->assertTrue(
            app()->bound(
                FiscalAuthorizationTransport::class
            )
        );

        $this->assertTrue(
            app()->bound(
                FiscalRemoteSequenceAuthority::class
            )
        );
    }

    public function test_serializer_builds_exact_soap11_auth_and_fecae_shape(): void
    {
        $serializer =
            new DomWsfeFecaeSoapSerializer;

        $call = $this->fecaeCall(
            FiscalEnvironment::Homologation
        );

        $headers =
            $serializer->headers(
                $call
            );

        $this->assertSame(
            'text/xml; charset=utf-8',
            $headers['Content-Type']
        );

        $this->assertSame(
            '"'
            . WsfeFecaeSoap11Call::SOAP_ACTION
            . '"',
            $headers['SOAPAction']
        );

        $document =
            new \DOMDocument;

        $this->assertTrue(
            $document->loadXML(
                $serializer->body(
                    $call
                )
            )
        );

        $xpath =
            new \DOMXPath(
                $document
            );

        $xpath->registerNamespace(
            'soap',
            WsfeFecaeSoap11Call::
                ENVELOPE_NAMESPACE
        );
        $xpath->registerNamespace(
            'fe',
            WsfeFecaeSoap11Call::
                SERVICE_NAMESPACE
        );

        $this->assertSame(
            'SYNTHETIC-TOKEN',
            trim(
                $xpath->evaluate(
                    'string(/soap:Envelope/soap:Body/fe:FECAESolicitar/fe:Auth/fe:Token)'
                )
            )
        );

        $this->assertSame(
            'SYNTHETIC-SIGN',
            trim(
                $xpath->evaluate(
                    'string(/soap:Envelope/soap:Body/fe:FECAESolicitar/fe:Auth/fe:Sign)'
                )
            )
        );

        $this->assertSame(
            '20123456786',
            trim(
                $xpath->evaluate(
                    'string(/soap:Envelope/soap:Body/fe:FECAESolicitar/fe:Auth/fe:Cuit)'
                )
            )
        );

        $this->assertSame(
            '1',
            trim(
                $xpath->evaluate(
                    'string(/soap:Envelope/soap:Body/fe:FECAESolicitar/fe:FeCAEReq/fe:FeCabReq/fe:CantReg)'
                )
            )
        );

        $this->assertSame(
            '55',
            trim(
                $xpath->evaluate(
                    'string(/soap:Envelope/soap:Body/fe:FECAESolicitar/fe:FeCAEReq/fe:FeDetReq/fe:FECAEDetRequest/fe:CbteDesde)'
                )
            )
        );

        $this->assertSame(
            1,
            $xpath->query(
                '/soap:Envelope/soap:Body/fe:FECAESolicitar/fe:FeCAEReq/fe:FeDetReq/fe:FECAEDetRequest/fe:Iva/fe:AlicIva'
            )?->length
        );
    }

    public function test_parser_round_trip_preserves_and_converges_synthetic_provider_result(): void
    {
        $parser =
            new DomWsfeFecaeSoapResponseParser;

        $result = $parser->parse(
            200,
            self::successSoap()
        );

        $preserved =
            $result->preservedResult();

        $this->assertSame(
            'A',
            $preserved['FeCabResp']
                ['Resultado']
        );

        $this->assertSame(
            'future-value',
            $preserved['FutureTopLevel']
                ['Value']
        );

        $this->assertCount(
            1,
            $preserved['FeDetResp']
                ['FECAEDetResponse']
        );

        $normalized =
            (
                new WsfeFecaeProviderResponseNormalizer
            )->normalize(
                $result
            );

        $this->assertSame(
            FiscalAuthorizationOutcome::Authorized,
            $normalized->outcome
        );

        $transportResult =
            (
                new WsfeFecaeProviderResultConvergence
            )->converge(
                $normalized
            );

        $this->assertSame(
            FiscalAuthorizationOutcome::Authorized,
            $transportResult->outcome
        );

        $this->assertSame(
            '74123456789012',
            $transportResult
                ->authorizationCode
        );

        $this->assertSame(
            '2026-08-29',
            $transportResult
                ->authorizationCodeExpiresOn
        );
    }

    public function test_homologation_transport_request_metadata_and_options_are_exact(): void
    {
        $calls = 0;
        $seenRequest = null;
        $seenOptions = null;

        $handler = static function (
            RequestInterface $request,
            array $options
        ) use (
            &$calls,
            &$seenRequest,
            &$seenOptions
        ) {
            $calls++;
            $seenRequest = $request;
            $seenOptions = $options;

            return new FulfilledPromise(
                new Response(
                    200,
                    [],
                    self::successSoap()
                )
            );
        };

        $result =
            $this->transport(
                $handler
            )->exchange(
                $this->fecaeCall(
                    FiscalEnvironment::Homologation
                )
            );

        $this->assertSame(
            1,
            $calls
        );

        $this->assertSame(
            'A',
            $result->header()
                ['Resultado']
        );

        $this->assertInstanceOf(
            RequestInterface::class,
            $seenRequest
        );

        $this->assertSame(
            'POST',
            $seenRequest->getMethod()
        );

        $this->assertSame(
            'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
            (string) $seenRequest->getUri()
        );

        $this->assertSame(
            WsfeFecaeSoap11Call::SOAP_ACTION,
            trim(
                $seenRequest->getHeaderLine(
                    'SOAPAction'
                ),
                '"'
            )
        );

        $this->assertFalse(
            $seenRequest->hasHeader(
                'Authorization'
            )
        );

        $body =
            (string) $seenRequest
                ->getBody();

        $this->assertStringContainsString(
            'SYNTHETIC-TOKEN',
            $body
        );
        $this->assertStringContainsString(
            'SYNTHETIC-SIGN',
            $body
        );

        $this->assertIsArray(
            $seenOptions
        );
        $this->assertFalse(
            $seenOptions['allow_redirects']
        );
        $this->assertFalse(
            $seenOptions['http_errors']
        );
        $this->assertTrue(
            $seenOptions['verify']
        );
        $this->assertSame(
            5.0,
            $seenOptions[
                'connect_timeout'
            ]
        );
        $this->assertSame(
            15.0,
            $seenOptions['timeout']
        );
        $this->assertTrue(
            $seenOptions['stream']
        );
        $this->assertIsCallable(
            $seenOptions['on_headers']
        );
    }

    public function test_production_is_rejected_before_http(): void
    {
        $calls = 0;

        $handler = static function (
            RequestInterface $request,
            array $options
        ) use (&$calls) {
            $calls++;

            return new FulfilledPromise(
                new Response(
                    200,
                    [],
                    self::successSoap()
                )
            );
        };

        try {
            $this->transport(
                $handler
            )->exchange(
                $this->fecaeCall(
                    FiscalEnvironment::Production
                )
            );

            $this->fail(
                'Producción debía permanecer bloqueada.'
            );
        } catch (
            RuntimeException $exception
        ) {
            $this->assertStringContainsString(
                'producción permanece bloqueado',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            0,
            $calls
        );
    }

    public function test_parser_rejects_doctype_malformed_and_oversize_payloads(): void
    {
        $parser =
            new DomWsfeFecaeSoapResponseParser;

        foreach (
            [
                '<!DOCTYPE x><x/>',
                '<soap:Envelope>',
                str_repeat(
                    'x',
                    DomWsfeFecaeSoapResponseParser::
                        MAX_SOAP_RESPONSE_BYTES
                    + 1
                ),
            ]
            as $invalid
        ) {
            try {
                $parser->parse(
                    200,
                    $invalid
                );

                $this->fail(
                    'La respuesta inválida debía fallar cerrada.'
                );
            } catch (RuntimeException) {
                $this->addToAssertionCount(
                    1
                );
            }
        }
    }

    public function test_connection_failure_is_sanitized_and_not_retried(): void
    {
        $calls = 0;

        $handler = static function (
            RequestInterface $request,
            array $options
        ) use (&$calls) {
            $calls++;

            return new RejectedPromise(
                new RuntimeException(
                    'synthetic upstream secret detail'
                )
            );
        };

        try {
            $this->transport(
                $handler
            )->exchange(
                $this->fecaeCall(
                    FiscalEnvironment::Homologation
                )
            );

            $this->fail(
                'El fallo de conexión debía propagarse sanitizado.'
            );
        } catch (
            RuntimeException $exception
        ) {
            $this->assertSame(
                'No se pudo completar el transporte WSFE FECAESolicitar.',
                $exception->getMessage()
            );

            $this->assertStringNotContainsString(
                'synthetic upstream secret detail',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            1,
            $calls
        );
    }

    private function transport(
        callable $handler
    ): GuzzleWsfeFecaeSoapTransport {
        return new GuzzleWsfeFecaeSoapTransport(
            new DomWsfeFecaeSoapSerializer,
            new DomWsfeFecaeSoapResponseParser,
            new Client([
                'handler' => $handler,
            ])
        );
    }

    private function fecaeCall(
        FiscalEnvironment $environment
    ): WsfeFecaeSoap11Call {
        $ticketRequest =
            new WsaaAccessTicketRequest(
                91,
                $environment,
                'wsfe',
                '20123456786'
            );

        $ticket =
            new WsaaAccessTicket(
                91,
                $environment,
                'wsfe',
                '20123456786',
                'SYNTHETIC-TOKEN',
                'SYNTHETIC-SIGN',
                CarbonImmutable::parse(
                    '2026-08-19T19:00:00-03:00'
                ),
                CarbonImmutable::parse(
                    '2026-08-19T21:00:00-03:00'
                )
            );

        return new WsfeFecaeSoap11Call(
            new FiscalAuthorizationTransportRequest(
                organizationId: 91,
                fiscalDocumentId: 501,
                environment: $environment,
                pointOfSaleNumber: 3,
                voucherTypeCode: 1,
                voucherNumber: 55,
                fecaeRequest:
                    $this->fecaeRequest(),
            ),
            $ticketRequest,
            $ticket,
            CarbonImmutable::parse(
                '2026-08-19T20:00:00-03:00'
            )
        );
    }

    private function fecaeRequest():
        WsfeFecaeRequestData {
        return new WsfeFecaeRequestData(
            new WsfeFecaeHeaderData(
                recordCount: 1,
                pointOfSaleNumber: 3,
                voucherTypeCode: 1,
            ),
            new WsfeFecaeDetailData(
                conceptCode: 1,
                documentTypeCode: 80,
                documentNumber:
                    '20123456786',
                voucherFrom: 55,
                voucherTo: 55,
                voucherDate:
                    '20260819',
                totalAmount:
                    '121.00',
                nonTaxedAmount:
                    '0.00',
                netTaxableAmount:
                    '100.00',
                exemptAmount:
                    '0.00',
                tributesAmount:
                    '0.00',
                vatAmount:
                    '21.00',
                serviceFrom: null,
                serviceTo: null,
                paymentDueDate: null,
                currencyId: 'PES',
                currencyQuotation:
                    '1.000000',
                sameCurrencySettlement:
                    'N',
                recipientVatConditionId:
                    1,
                associatedVouchers: [],
                associatedPeriod: null,
                tributes: [],
                vat: [
                    [
                        'Id' => 5,
                        'BaseImp' =>
                            '100.00',
                        'Importe' =>
                            '21.00',
                    ],
                ],
            )
        );
    }

    private static function successSoap():
        string {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">
      <FECAESolicitarResult>
        <FeCabResp>
          <Cuit>20123456786</Cuit>
          <PtoVta>3</PtoVta>
          <CbteTipo>1</CbteTipo>
          <Resultado>A</Resultado>
        </FeCabResp>
        <FeDetResp>
          <FECAEDetResponse>
            <Concepto>1</Concepto>
            <DocTipo>80</DocTipo>
            <DocNro>20123456786</DocNro>
            <CbteDesde>55</CbteDesde>
            <CbteHasta>55</CbteHasta>
            <Resultado>A</Resultado>
            <CAE>74123456789012</CAE>
            <CAEFchVto>20260829</CAEFchVto>
            <Observaciones>
              <Obs>
                <Code>100</Code>
                <Msg>synthetic observation</Msg>
              </Obs>
            </Observaciones>
          </FECAEDetResponse>
        </FeDetResp>
        <Events>
          <Evt>
            <Code>1</Code>
            <Msg>synthetic event</Msg>
          </Evt>
        </Events>
        <FutureTopLevel>
          <Value>future-value</Value>
        </FutureTopLevel>
      </FECAESolicitarResult>
    </FECAESolicitarResponse>
  </soap:Body>
</soap:Envelope>
XML;
    }
}
