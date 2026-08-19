<?php

namespace App\Domain\Fiscal;

use DOMDocument;
use DomainException;

final class WsaaLoginCmsSoap11Call
{
    public const OPERATION = 'loginCms';

    public const ENVELOPE_NAMESPACE =
        'http://schemas.xmlsoap.org/soap/envelope/';

    public const OPERATION_NAMESPACE =
        'http://wsaa.view.sua.dvadac.desein.afip.gov';

    public const CONTENT_TYPE =
        'text/xml; charset=utf-8';

    public const SOAP_ACTION_HEADER_VALUE =
        '""';

    private readonly WsaaAccessTicketRequest $request;

    private readonly WsaaSignedCms $signedCms;

    public function __construct(
        WsaaAccessTicketRequest $request,
        WsaaSignedCms $signedCms
    ) {
        $this->request = $request;
        $this->signedCms = $signedCms;
    }

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return [
            'Content-Type' =>
                self::CONTENT_TYPE,
            'SOAPAction' =>
                self::SOAP_ACTION_HEADER_VALUE,
            'Accept' =>
                'text/xml',
        ];
    }

    public function body(): string
    {
        $document = new DOMDocument(
            '1.0',
            'UTF-8'
        );
        $document->formatOutput = false;

        $envelope =
            $document->createElementNS(
                self::ENVELOPE_NAMESPACE,
                'soapenv:Envelope'
            );

        $document->appendChild(
            $envelope
        );

        $body =
            $document->createElementNS(
                self::ENVELOPE_NAMESPACE,
                'soapenv:Body'
            );

        $envelope->appendChild(
            $body
        );

        $loginCms =
            $document->createElementNS(
                self::OPERATION_NAMESPACE,
                'wsaa:loginCms'
            );

        $body->appendChild(
            $loginCms
        );

        $in0 =
            $document->createElementNS(
                self::OPERATION_NAMESPACE,
                'wsaa:in0'
            );

        $in0->appendChild(
            $document->createTextNode(
                $this->signedCms
                    ->loginCmsInput()
            )
        );

        $loginCms->appendChild(
            $in0
        );

        $xml =
            $document->saveXML();

        if (
            ! is_string($xml)
            || $xml === ''
        ) {
            throw new DomainException(
                'No se pudo serializar la llamada SOAP LoginCms.'
            );
        }

        return $xml;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new DomainException(
            'La llamada SOAP LoginCms con CMS no puede serializarse.'
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'operation' =>
                self::OPERATION,
            'organizationId' =>
                $this->request
                    ->organizationId,
            'environment' =>
                $this->request
                    ->environment
                    ->value,
            'service' =>
                $this->request
                    ->service,
            'issuerCuit' =>
                $this->request
                    ->issuerCuit,
            'digest' =>
                $this->signedCms
                    ->digest
                    ->value,
            'cms' =>
                '[REDACTED]',
        ];
    }
}
