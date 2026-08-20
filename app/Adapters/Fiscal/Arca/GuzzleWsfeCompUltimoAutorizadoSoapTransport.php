<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\FiscalRemoteSequenceState;
use App\Domain\Fiscal\OfficialArcaSoapEndpointMap;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoap11Call;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapResponseParser;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapSerializer;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapTransport;
use App\Enums\FiscalEnvironment;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final class GuzzleWsfeCompUltimoAutorizadoSoapTransport implements
    WsfeCompUltimoAutorizadoSoapTransport
{
    private const CONNECT_TIMEOUT_SECONDS = 5.0;
    private const TOTAL_TIMEOUT_SECONDS = 15.0;
    private readonly ClientInterface $client;

    public function __construct(
        private readonly WsfeCompUltimoAutorizadoSoapSerializer $serializer,
        private readonly WsfeCompUltimoAutorizadoSoapResponseParser $responses,
        ?ClientInterface $client = null,
    ) {
        $this->client = $client ?? new Client;
    }

    public function exchange(
        WsfeCompUltimoAutorizadoSoap11Call $call
    ): FiscalRemoteSequenceState {
        if ($call->environment() !== FiscalEnvironment::Homologation) {
            throw new RuntimeException(
                'El transporte WSFE FECompUltimoAutorizado de producción permanece bloqueado.'
            );
        }

        $endpoint = (new OfficialArcaSoapEndpointMap)
            ->for($call->environment())
            ->wsfeServiceUrl;

        try {
            $response = $this->client->request('POST', $endpoint, [
                'headers' => $this->serializer->headers($call),
                'body' => $this->serializer->body($call),
                'allow_redirects' => false,
                'http_errors' => false,
                'verify' => true,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::TOTAL_TIMEOUT_SECONDS,
                'stream' => true,
                'on_headers' => static function (ResponseInterface $response): void {
                    $length = trim($response->getHeaderLine('Content-Length'));
                    if ($length === '') {
                        return;
                    }
                    if (
                        preg_match('/^[0-9]+$/D', $length) !== 1
                        || (int) $length > DomWsfeCompUltimoAutorizadoSoapResponseParser::MAX_SOAP_RESPONSE_BYTES
                    ) {
                        throw new RuntimeException(
                            'La respuesta WSFE FECompUltimoAutorizado excede el límite permitido.'
                        );
                    }
                },
            ]);
        } catch (Throwable) {
            throw new RuntimeException(
                'No se pudo completar el transporte WSFE FECompUltimoAutorizado.'
            );
        }

        return $this->responses->parse(
            $response->getStatusCode(),
            $this->readBounded($response->getBody()),
            $call->query(),
        );
    }

    private function readBounded(StreamInterface $stream): string
    {
        if (! $stream->isReadable()) {
            throw new RuntimeException('La respuesta WSFE FECompUltimoAutorizado no es legible.');
        }
        $limit = DomWsfeCompUltimoAutorizadoSoapResponseParser::MAX_SOAP_RESPONSE_BYTES;
        $contents = '';
        while (! $stream->eof()) {
            $remaining = $limit + 1 - strlen($contents);
            if ($remaining <= 0) {
                break;
            }
            $chunk = $stream->read(min(8192, $remaining));
            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }
                throw new RuntimeException('La respuesta WSFE FECompUltimoAutorizado no pudo leerse completamente.');
            }
            $contents .= $chunk;
        }
        if (strlen($contents) > $limit || ! $stream->eof()) {
            throw new RuntimeException('La respuesta WSFE FECompUltimoAutorizado excede el límite permitido.');
        }
        return $contents;
    }
}
