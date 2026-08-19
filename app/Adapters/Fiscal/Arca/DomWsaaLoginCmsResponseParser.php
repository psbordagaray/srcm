<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsaaAccessTicket;
use App\Domain\Fiscal\WsaaAccessTicketRequest;
use App\Domain\Fiscal\WsaaLoginCmsFaultException;
use App\Domain\Fiscal\WsaaLoginCmsResponseParser;
use App\Domain\Fiscal\WsaaLoginCmsSoap11Call;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use RuntimeException;
use Throwable;

final class DomWsaaLoginCmsResponseParser implements WsaaLoginCmsResponseParser
{
    public const MAX_SOAP_RESPONSE_BYTES = 1048576;
    public const MAX_LOGIN_TICKET_XML_BYTES = 524288;

    public function parse(
        int $httpStatus,
        string $soapXml,
        WsaaAccessTicketRequest $request
    ): WsaaAccessTicket {
        if (
            $httpStatus < 100
            || $httpStatus > 599
            || $soapXml === ''
            || strlen($soapXml) > self::MAX_SOAP_RESPONSE_BYTES
        ) {
            throw new RuntimeException(
                'La respuesta WSAA está fuera de los límites admitidos.'
            );
        }

        $document = $this->secureDom($soapXml);
        $envelope = $document->documentElement;

        if (
            ! $envelope instanceof DOMElement
            || $envelope->localName !== 'Envelope'
            || $envelope->namespaceURI
                !== WsaaLoginCmsSoap11Call::ENVELOPE_NAMESPACE
        ) {
            throw new RuntimeException(
                'La respuesta WSAA no es SOAP 1.1 válido.'
            );
        }

        $body = $this->singleChild(
            $envelope,
            'Body',
            WsaaLoginCmsSoap11Call::ENVELOPE_NAMESPACE
        );

        $fault = $this->optionalChild(
            $body,
            'Fault',
            WsaaLoginCmsSoap11Call::ENVELOPE_NAMESPACE
        );

        if ($fault instanceof DOMElement) {
            throw WsaaLoginCmsFaultException::fromArcaCode(
                $this->faultCode($fault)
            );
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException(
                'WSAA devolvió HTTP no exitoso sin SOAP Fault interpretable.'
            );
        }

        $loginCmsResponse = $this->singleChild(
            $body,
            'loginCmsResponse',
            WsaaLoginCmsSoap11Call::OPERATION_NAMESPACE
        );

        $loginCmsReturn = $this->singleChild(
            $loginCmsResponse,
            'loginCmsReturn',
            WsaaLoginCmsSoap11Call::OPERATION_NAMESPACE
        );

        $ticketXml = trim($loginCmsReturn->textContent);

        if (
            $ticketXml === ''
            || strlen($ticketXml) > self::MAX_LOGIN_TICKET_XML_BYTES
        ) {
            throw new RuntimeException(
                'LoginTicketResponse está fuera de los límites admitidos.'
            );
        }

        $ticketDocument = $this->secureDom($ticketXml);
        $root = $ticketDocument->documentElement;

        if (
            ! $root instanceof DOMElement
            || $root->localName !== 'loginTicketResponse'
            || $root->namespaceURI !== null
        ) {
            throw new RuntimeException(
                'LoginTicketResponse posee raíz inválida.'
            );
        }

        if (
            $root->hasAttribute('version')
            && trim($root->getAttribute('version')) !== '1.0'
        ) {
            throw new RuntimeException(
                'LoginTicketResponse usa versión no soportada.'
            );
        }

        $header = $this->singleChild(
            $root,
            'header',
            null
        );

        $credentials = $this->singleChild(
            $root,
            'credentials',
            null
        );

        $uniqueIdRaw = $this->requiredText(
            $header,
            'uniqueId',
            10
        );

        if (
            preg_match('/^[0-9]{1,10}$/D', $uniqueIdRaw) !== 1
            || (int) $uniqueIdRaw > 4294967295
        ) {
            throw new RuntimeException(
                'LoginTicketResponse uniqueId inválido.'
            );
        }

        $this->requiredText(
            $header,
            'source',
            4096
        );

        $this->requiredText(
            $header,
            'destination',
            4096
        );

        try {
            $generation = CarbonImmutable::parse(
                $this->requiredText(
                    $header,
                    'generationTime',
                    128
                )
            );

            $expiration = CarbonImmutable::parse(
                $this->requiredText(
                    $header,
                    'expirationTime',
                    128
                )
            );
        } catch (Throwable) {
            throw new RuntimeException(
                'LoginTicketResponse posee tiempos inválidos.'
            );
        }

        $token = $this->requiredText(
            $credentials,
            'token',
            16384
        );

        $sign = $this->requiredText(
            $credentials,
            'sign',
            16384
        );

        return new WsaaAccessTicket(
            $request->organizationId,
            $request->environment,
            $request->service,
            $request->issuerCuit,
            $token,
            $sign,
            $generation,
            $expiration
        );
    }

    private function secureDom(string $xml): DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException(
                'La respuesta WSAA no admite DOCTYPE.'
            );
        }

        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->validateOnParse = false;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML(
                $xml,
                LIBXML_NONET
                | LIBXML_NOBLANKS
                | LIBXML_NOCDATA
                | LIBXML_COMPACT
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded !== true) {
            throw new RuntimeException(
                'La respuesta XML de WSAA es inválida.'
            );
        }

        return $document;
    }

    private function singleChild(
        DOMElement $parent,
        string $localName,
        ?string $namespace
    ): DOMElement {
        $matches = [];

        foreach ($parent->childNodes as $node) {
            if (
                $node instanceof DOMElement
                && $node->localName === $localName
                && $node->namespaceURI === $namespace
            ) {
                $matches[] = $node;
            }
        }

        if (count($matches) !== 1) {
            throw new RuntimeException(
                'La estructura XML de WSAA es incompleta o ambigua.'
            );
        }

        return $matches[0];
    }

    private function optionalChild(
        DOMElement $parent,
        string $localName,
        ?string $namespace
    ): ?DOMElement {
        $matches = [];

        foreach ($parent->childNodes as $node) {
            if (
                $node instanceof DOMElement
                && $node->localName === $localName
                && $node->namespaceURI === $namespace
            ) {
                $matches[] = $node;
            }
        }

        if (count($matches) > 1) {
            throw new RuntimeException(
                'La estructura XML de WSAA es ambigua.'
            );
        }

        return $matches[0] ?? null;
    }

    private function requiredText(
        DOMElement $parent,
        string $localName,
        int $maxBytes
    ): string {
        $element = $this->singleChild(
            $parent,
            $localName,
            null
        );

        $value = trim($element->textContent);

        if (
            $value === ''
            || strlen($value) > $maxBytes
        ) {
            throw new RuntimeException(
                'La respuesta WSAA contiene un campo vacío o fuera de límite.'
            );
        }

        return $value;
    }

    private function faultCode(DOMElement $fault): string
    {
        foreach ($fault->childNodes as $node) {
            if (
                ! $node instanceof DOMElement
                || ! in_array(
                    $node->localName,
                    ['faultstring', 'faultcode'],
                    true
                )
            ) {
                continue;
            }

            if (
                preg_match(
                    '/\b((?:coe|cms|xml|wsn|wsaa)\.[a-z0-9_.-]+)\b/i',
                    trim($node->textContent),
                    $matches
                ) === 1
            ) {
                return strtolower($matches[1]);
            }
        }

        return 'unknown';
    }
}
