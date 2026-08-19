<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\OfficialArcaSoapEndpointMap;
use App\Domain\Fiscal\WsaaAccessTicket;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsaaLoginCmsResponseParser;
use App\Domain\Fiscal\WsaaLoginCmsSoap11Call;
use App\Domain\Fiscal\WsaaLoginCmsTransport;
use App\Domain\Fiscal\WsaaSignedCms;
use App\Enums\FiscalEnvironment;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final class GuzzleWsaaLoginCmsTransport implements
    WsaaLoginCmsTransport
{
    private const CONNECT_TIMEOUT_SECONDS =
        5.0;

    private const TOTAL_TIMEOUT_SECONDS =
        15.0;

    private readonly ClientInterface $client;

    public function __construct(
        private readonly WsaaLoginCmsResponseParser $responses,
        ?ClientInterface $client = null
    ) {
        $this->client =
            $client
            ?? new Client;
    }

    public function exchange(
        WsaaAccessTicketRequest $request,
        WsaaSignedCms $signedCms
    ): WsaaAccessTicket {
        if (
            $request->environment
                !== FiscalEnvironment::Homologation
        ) {
            throw new RuntimeException(
                'El transporte WSAA LoginCms de producción permanece bloqueado.'
            );
        }

        $call =
            new WsaaLoginCmsSoap11Call(
                $request,
                $signedCms
            );

        $endpoint =
            (
                new OfficialArcaSoapEndpointMap
            )
                ->for(
                    $request->environment
                )
                ->wsaaLoginCmsUrl;

        try {
            $response =
                $this->client->request(
                    'POST',
                    $endpoint,
                    [
                        'headers' =>
                            $call->headers(),
                        'body' =>
                            $call->body(),
                        'allow_redirects' =>
                            false,
                        'http_errors' =>
                            false,
                        'verify' =>
                            true,
                        'connect_timeout' =>
                            self::
                                CONNECT_TIMEOUT_SECONDS,
                        'timeout' =>
                            self::
                                TOTAL_TIMEOUT_SECONDS,
                        'stream' =>
                            true,
                        'on_headers' =>
                            static function (
                                ResponseInterface $response
                            ): void {
                                $length =
                                    trim(
                                        $response
                                            ->getHeaderLine(
                                                'Content-Length'
                                            )
                                    );

                                if ($length === '') {
                                    return;
                                }

                                if (
                                    preg_match(
                                        '/^[0-9]+$/D',
                                        $length
                                    ) !== 1
                                    || (int) $length
                                        > DomWsaaLoginCmsResponseParser::
                                            MAX_SOAP_RESPONSE_BYTES
                                ) {
                                    throw new RuntimeException(
                                        'La respuesta WSAA excede el límite permitido.'
                                    );
                                }
                            },
                    ]
                );
        } catch (Throwable) {
            throw new RuntimeException(
                'No se pudo completar el transporte WSAA LoginCms.'
            );
        }

        $body =
            $this->readBounded(
                $response->getBody()
            );

        return $this->responses->parse(
            $response->getStatusCode(),
            $body,
            $request
        );
    }

    private function readBounded(
        StreamInterface $stream
    ): string {
        if (! $stream->isReadable()) {
            throw new RuntimeException(
                'La respuesta WSAA no es legible.'
            );
        }

        $limit =
            DomWsaaLoginCmsResponseParser::
                MAX_SOAP_RESPONSE_BYTES;

        $contents = '';

        while (! $stream->eof()) {
            $remaining =
                $limit
                + 1
                - strlen(
                    $contents
                );

            if ($remaining <= 0) {
                break;
            }

            $chunk =
                $stream->read(
                    min(
                        8192,
                        $remaining
                    )
                );

            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }

                throw new RuntimeException(
                    'La lectura de la respuesta WSAA no pudo completarse.'
                );
            }

            $contents .= $chunk;
        }

        if (
            $contents === ''
            || strlen($contents)
                > $limit
        ) {
            throw new RuntimeException(
                'La respuesta WSAA está vacía o excede el límite permitido.'
            );
        }

        return $contents;
    }
}
