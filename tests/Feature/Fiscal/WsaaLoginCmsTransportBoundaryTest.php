<?php

namespace Tests\Feature\Fiscal;

use App\Adapters\Fiscal\Arca\DomWsaaLoginCmsResponseParser;
use App\Adapters\Fiscal\Arca\GuzzleWsaaLoginCmsTransport;
use App\Domain\Fiscal\WsaaAccessTicketProvider;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsaaCmsDigestPolicy;
use App\Domain\Fiscal\WsaaCmsSigner;
use App\Domain\Fiscal\WsaaLoginCmsFaultException;
use App\Domain\Fiscal\WsaaLoginCmsResponseParser;
use App\Domain\Fiscal\WsaaLoginCmsSoap11Call;
use App\Domain\Fiscal\WsaaLoginCmsTransport;
use App\Domain\Fiscal\WsaaSignedCms;
use App\Enums\FiscalEnvironment;
use App\Enums\WsaaCmsDigestAlgorithm;
use App\Enums\WsaaLoginCmsFaultDisposition;
use Carbon\CarbonImmutable;
use DomainException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

class WsaaLoginCmsTransportBoundaryTest extends TestCase
{
    public function test_container_binds_transport_and_parser_only(): void
    {
        $this->assertInstanceOf(
            DomWsaaLoginCmsResponseParser::class,
            app(WsaaLoginCmsResponseParser::class)
        );

        $this->assertInstanceOf(
            GuzzleWsaaLoginCmsTransport::class,
            app(WsaaLoginCmsTransport::class)
        );

        $this->assertTrue(
            app()->bound(WsaaCmsSigner::class)
        );

        $this->assertFalse(
            app()->bound(WsaaAccessTicketProvider::class)
        );

        $this->assertFalse(
            app()->bound(WsaaCmsDigestPolicy::class)
        );
    }

    public function test_soap_call_is_exact_ephemeral_and_redacted(): void
    {
        $cms = $this->signedCms();

        $call = new WsaaLoginCmsSoap11Call(
            $this->request(),
            $cms
        );

        $this->assertSame(
            'text/xml; charset=utf-8',
            $call->headers()['Content-Type']
        );

        $this->assertSame(
            '""',
            $call->headers()['SOAPAction']
        );

        $document = new \DOMDocument;

        $this->assertTrue(
            $document->loadXML($call->body())
        );

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace(
            'soap',
            WsaaLoginCmsSoap11Call::ENVELOPE_NAMESPACE
        );
        $xpath->registerNamespace(
            'wsaa',
            WsaaLoginCmsSoap11Call::OPERATION_NAMESPACE
        );

        $nodes = $xpath->query(
            '/soap:Envelope/soap:Body/wsaa:loginCms/wsaa:in0'
        );

        $this->assertSame(1, $nodes?->length);

        $this->assertSame(
            $cms->loginCmsInput(),
            trim(
                $nodes?->item(0)?->textContent
                ?? ''
            )
        );

        ob_start();
        var_dump($call);
        $debug = (string) ob_get_clean();

        $this->assertStringContainsString(
            '[REDACTED]',
            $debug
        );

        $this->assertStringNotContainsString(
            $cms->loginCmsInput(),
            $debug
        );

        $this->expectException(
            DomainException::class
        );

        serialize($call);
    }

    public function test_homologation_exchange_uses_safe_options_and_builds_ticket(): void
    {
        $seenRequest = null;
        $seenOptions = null;

        $handler = static function (
            RequestInterface $request,
            array $options
        ) use (
            &$seenRequest,
            &$seenOptions
        ) {
            $seenRequest = $request;
            $seenOptions = $options;

            return new FulfilledPromise(
                new Response(
                    200,
                    [
                        'Content-Type' =>
                            'text/xml; charset=utf-8',
                    ],
                    self::successSoap()
                )
            );
        };

        $cms = $this->signedCms();

        $ticket = $this->transport($handler)
            ->exchange(
                $this->request(),
                $cms
            );

        $this->assertSame(
            'SYNTHETIC-TOKEN-ONLY',
            $ticket->token()
        );

        $this->assertSame(
            'SYNTHETIC-SIGN-ONLY',
            $ticket->sign()
        );

        $ticket->assertUsableFor(
            $this->request(),
            CarbonImmutable::parse(
                '2026-08-19T20:00:00+00:00'
            )
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
            'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
            (string) $seenRequest->getUri()
        );

        $this->assertSame(
            'text/xml; charset=utf-8',
            $seenRequest->getHeaderLine(
                'Content-Type'
            )
        );

        $this->assertSame(
            '""',
            $seenRequest->getHeaderLine(
                'SOAPAction'
            )
        );

        $this->assertFalse(
            $seenRequest->hasHeader(
                'Authorization'
            )
        );

        $this->assertStringContainsString(
            $cms->loginCmsInput(),
            (string) $seenRequest->getBody()
        );

        $this->assertIsArray($seenOptions);
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
            $seenOptions['connect_timeout']
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
            $this->transport($handler)
                ->exchange(
                    new WsaaAccessTicketRequest(
                        91,
                        FiscalEnvironment::Production,
                        'wsfe',
                        '20123456786'
                    ),
                    $this->signedCms()
                );

            $this->fail(
                'Producción debía permanecer bloqueada.'
            );
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'producción permanece bloqueado',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $calls);
    }

    public function test_transient_fault_is_sanitized_and_not_retried(): void
    {
        $calls = 0;

        $handler = static function (
            RequestInterface $request,
            array $options
        ) use (&$calls) {
            $calls++;

            return new FulfilledPromise(
                new Response(
                    500,
                    [],
                    self::faultSoap(
                        'wsaa.unavailable: synthetic maintenance'
                    )
                )
            );
        };

        try {
            $this->transport($handler)
                ->exchange(
                    $this->request(),
                    $this->signedCms()
                );

            $this->fail(
                'SOAP Fault debía materializar excepción.'
            );
        } catch (
            WsaaLoginCmsFaultException $exception
        ) {
            $this->assertSame(
                'wsaa.unavailable',
                $exception->arcaCode
            );

            $this->assertSame(
                WsaaLoginCmsFaultDisposition::
                    TransientNotBefore60Seconds,
                $exception->disposition
            );

            $this->assertSame(
                60,
                $exception->retryNotBeforeSeconds()
            );

            $this->assertStringNotContainsString(
                'synthetic maintenance',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, $calls);
    }

    public function test_non_transient_fault_requires_action(): void
    {
        $handler = static fn (
            RequestInterface $request,
            array $options
        ) =>
            new FulfilledPromise(
                new Response(
                    500,
                    [],
                    self::faultSoap(
                        'cms.bad: invalid synthetic CMS'
                    )
                )
            );

        try {
            $this->transport($handler)
                ->exchange(
                    $this->request(),
                    $this->signedCms()
                );

            $this->fail(
                'cms.bad debía fallar.'
            );
        } catch (
            WsaaLoginCmsFaultException $exception
        ) {
            $this->assertSame(
                'cms.bad',
                $exception->arcaCode
            );

            $this->assertSame(
                WsaaLoginCmsFaultDisposition::
                    ActionRequiredNoAutomaticRetry,
                $exception->disposition
            );

            $this->assertNull(
                $exception->retryNotBeforeSeconds()
            );
        }
    }

    public function test_malformed_doctype_and_oversized_xml_fail_closed(): void
    {
        $parser = new DomWsaaLoginCmsResponseParser;

        foreach ([
            [200, '<not-soap/>'],
            [503, '<not-soap/>'],
            [
                200,
                '<!DOCTYPE x [<!ENTITY y "z">]><x>&y;</x>',
            ],
            [
                200,
                str_repeat(
                    'x',
                    DomWsaaLoginCmsResponseParser::
                        MAX_SOAP_RESPONSE_BYTES
                    + 1
                ),
            ],
        ] as [$status, $xml]) {
            try {
                $parser->parse(
                    $status,
                    $xml,
                    $this->request()
                );

                $this->fail(
                    'Respuesta inválida debía fallar.'
                );
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_network_failure_is_redacted(): void
    {
        $cms = $this->signedCms();

        $handler = static function (
            RequestInterface $request,
            array $options
        ) {
            return new RejectedPromise(
                new ConnectException(
                    'synthetic connect failure',
                    new Request(
                        'POST',
                        'https://wsaahomo.afip.gov.ar/ws/services/LoginCms'
                    )
                )
            );
        };

        try {
            $this->transport($handler)
                ->exchange(
                    $this->request(),
                    $cms
                );

            $this->fail(
                'Fallo sintético de red debía fallar.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'No se pudo completar el transporte WSAA LoginCms.',
                $exception->getMessage()
            );

            $this->assertNull(
                $exception->getPrevious()
            );

            $this->assertStringNotContainsString(
                $cms->loginCmsInput(),
                (string) $exception
            );
        }
    }

    private function transport(
        callable $handler
    ): GuzzleWsaaLoginCmsTransport {
        return new GuzzleWsaaLoginCmsTransport(
            new DomWsaaLoginCmsResponseParser,
            new Client([
                'handler' => $handler,
            ])
        );
    }

    private function request(): WsaaAccessTicketRequest
    {
        return new WsaaAccessTicketRequest(
            91,
            FiscalEnvironment::Homologation,
            'wsfe',
            '20123456786'
        );
    }

    private function signedCms(): WsaaSignedCms
    {
        return new WsaaSignedCms(
            base64_encode(
                'synthetic-cms-login-cms-boundary'
            ),
            WsaaCmsDigestAlgorithm::Sha256
        );
    }

    private static function successSoap(): string
    {
        $ticket =
            <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<loginTicketResponse version="1.0">
  <header>
    <source>cn=wsaahomo,o=afip,c=ar</source>
    <destination>cn=srcm-synthetic,o=srcm,c=ar</destination>
    <uniqueId>123456</uniqueId>
    <generationTime>2026-08-19T19:45:00+00:00</generationTime>
    <expirationTime>2026-08-20T07:45:00+00:00</expirationTime>
  </header>
  <credentials>
    <token>SYNTHETIC-TOKEN-ONLY</token>
    <sign>SYNTHETIC-SIGN-ONLY</sign>
  </credentials>
</loginTicketResponse>
XML;

        return
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope '
            . 'xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
            . 'xmlns:wsaa="http://wsaa.view.sua.dvadac.desein.afip.gov">'
            . '<soapenv:Body>'
            . '<wsaa:loginCmsResponse>'
            . '<wsaa:loginCmsReturn>'
            . htmlspecialchars(
                $ticket,
                ENT_XML1 | ENT_QUOTES,
                'UTF-8'
            )
            . '</wsaa:loginCmsReturn>'
            . '</wsaa:loginCmsResponse>'
            . '</soapenv:Body>'
            . '</soapenv:Envelope>';
    }

    private static function faultSoap(
        string $faultString
    ): string {
        return
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope '
            . 'xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soapenv:Body>'
            . '<soapenv:Fault>'
            . '<faultcode>soapenv:Server</faultcode>'
            . '<faultstring>'
            . htmlspecialchars(
                $faultString,
                ENT_XML1 | ENT_QUOTES,
                'UTF-8'
            )
            . '</faultstring>'
            . '<detail/>'
            . '</soapenv:Fault>'
            . '</soapenv:Body>'
            . '</soapenv:Envelope>';
    }
}
