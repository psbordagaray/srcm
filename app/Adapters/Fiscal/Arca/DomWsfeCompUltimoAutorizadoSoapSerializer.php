<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoap11Call;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapSerializer;
use DOMDocument;
use DOMElement;
use DomainException;

final class DomWsfeCompUltimoAutorizadoSoapSerializer implements
    WsfeCompUltimoAutorizadoSoapSerializer
{
    public function headers(WsfeCompUltimoAutorizadoSoap11Call $call): array
    {
        return [
            'Content-Type' => WsfeCompUltimoAutorizadoSoap11Call::CONTENT_TYPE,
            'SOAPAction' => '"' . WsfeCompUltimoAutorizadoSoap11Call::SOAP_ACTION . '"',
            'Accept' => 'text/xml',
        ];
    }

    public function body(WsfeCompUltimoAutorizadoSoap11Call $call): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $envelope = $document->createElementNS(
            WsfeCompUltimoAutorizadoSoap11Call::ENVELOPE_NAMESPACE,
            'soapenv:Envelope'
        );
        $document->appendChild($envelope);
        $body = $document->createElementNS(
            WsfeCompUltimoAutorizadoSoap11Call::ENVELOPE_NAMESPACE,
            'soapenv:Body'
        );
        $envelope->appendChild($body);
        $operation = $document->createElementNS(
            WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE,
            'ar:' . WsfeCompUltimoAutorizadoSoap11Call::OPERATION
        );
        $body->appendChild($operation);
        $this->appendMap($document, $operation, $call->operationParameters());

        $xml = $document->saveXML();
        if (! is_string($xml) || $xml === '') {
            throw new DomainException(
                'No se pudo serializar FECompUltimoAutorizado SOAP 1.1.'
            );
        }

        return $xml;
    }

    /** @param array<string,mixed> $fields */
    private function appendMap(
        DOMDocument $document,
        DOMElement $parent,
        array $fields
    ): void {
        foreach ($fields as $name => $value) {
            if (! is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name) !== 1) {
                throw new DomainException('La estructura WSFE contiene un nombre XML inválido.');
            }
            $element = $document->createElementNS(
                WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE,
                'ar:' . $name
            );
            $parent->appendChild($element);
            if (is_array($value)) {
                $this->appendMap($document, $element, $value);
                continue;
            }
            if (! is_string($value) && ! is_int($value)) {
                throw new DomainException('La estructura WSFE contiene un valor no serializable.');
            }
            $element->appendChild($document->createTextNode((string) $value));
        }
    }
}
