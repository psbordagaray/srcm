<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\FiscalRemoteSequenceQuery;
use App\Domain\Fiscal\FiscalRemoteSequenceState;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoap11Call;
use App\Domain\Fiscal\WsfeCompUltimoAutorizadoSoapResponseParser;
use DOMDocument;
use DOMElement;
use RuntimeException;

final class DomWsfeCompUltimoAutorizadoSoapResponseParser implements
    WsfeCompUltimoAutorizadoSoapResponseParser
{
    public const MAX_SOAP_RESPONSE_BYTES = 1048576;

    public function parse(
        int $httpStatus,
        string $soapXml,
        FiscalRemoteSequenceQuery $query,
    ): FiscalRemoteSequenceState {
        if (
            $httpStatus < 100 || $httpStatus > 599
            || $soapXml === ''
            || strlen($soapXml) > self::MAX_SOAP_RESPONSE_BYTES
        ) {
            throw new RuntimeException(
                'La respuesta FECompUltimoAutorizado está fuera de los límites admitidos.'
            );
        }

        $document = $this->secureDom($soapXml);
        $envelope = $document->documentElement;
        if (
            ! $envelope instanceof DOMElement
            || $envelope->localName !== 'Envelope'
            || $envelope->namespaceURI !== WsfeCompUltimoAutorizadoSoap11Call::ENVELOPE_NAMESPACE
        ) {
            throw new RuntimeException('La respuesta FECompUltimoAutorizado no es SOAP 1.1 válido.');
        }

        $body = $this->singleChild(
            $envelope,
            'Body',
            WsfeCompUltimoAutorizadoSoap11Call::ENVELOPE_NAMESPACE
        );
        if ($this->optionalChild(
            $body,
            'Fault',
            WsfeCompUltimoAutorizadoSoap11Call::ENVELOPE_NAMESPACE
        ) instanceof DOMElement) {
            throw new RuntimeException('WSFE FECompUltimoAutorizado devolvió SOAP Fault.');
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException('WSFE FECompUltimoAutorizado devolvió HTTP no exitoso.');
        }

        $response = $this->singleChild(
            $body,
            'FECompUltimoAutorizadoResponse',
            WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE
        );
        $result = $this->singleChild(
            $response,
            'FECompUltimoAutorizadoResult',
            WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE
        );

        $errors = $this->optionalChild(
            $result,
            'Errors',
            WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE
        );
        if ($errors instanceof DOMElement && $this->elementChildren($errors) !== []) {
            $this->validateRepeatingMessages($errors, 'Err');
            throw new RuntimeException(
                'WSFE FECompUltimoAutorizado devolvió errores de proveedor.'
            );
        }

        $events = $this->optionalChild(
            $result,
            'Events',
            WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE
        );
        if ($events instanceof DOMElement) {
            $this->validateRepeatingMessages($events, 'Evt');
        }

        $point = $this->requiredInt($result, 'PtoVta', 1, 99998);
        $voucherType = $this->requiredInt($result, 'CbteTipo', 1, 999);
        $number = $this->optionalInt($result, 'CbteNro', 0, 99999999) ?? 0;

        if ($point !== $query->pointOfSaleNumber || $voucherType !== $query->voucherTypeCode) {
            throw new RuntimeException(
                'WSFE FECompUltimoAutorizado respondió para otra identidad de secuencia.'
            );
        }

        return new FiscalRemoteSequenceState(
            $query->environment,
            $point,
            $voucherType,
            $number,
        );
    }

    private function secureDom(string $xml): DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException('La respuesta FECompUltimoAutorizado no admite DOCTYPE.');
        }
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->validateOnParse = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML(
                $xml,
                LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA | LIBXML_COMPACT
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($loaded !== true) {
            throw new RuntimeException('La respuesta XML de FECompUltimoAutorizado es inválida.');
        }
        return $document;
    }

    private function requiredInt(DOMElement $parent, string $name, int $min, int $max): int
    {
        $element = $this->singleChild(
            $parent,
            $name,
            WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE
        );
        return $this->parseInt($element->textContent, $name, $min, $max);
    }

    private function optionalInt(DOMElement $parent, string $name, int $min, int $max): ?int
    {
        $element = $this->optionalChild(
            $parent,
            $name,
            WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE
        );
        return $element instanceof DOMElement
            ? $this->parseInt($element->textContent, $name, $min, $max)
            : null;
    }

    private function parseInt(string $raw, string $name, int $min, int $max): int
    {
        $raw = trim($raw);
        if (preg_match('/^[0-9]+$/D', $raw) !== 1) {
            throw new RuntimeException("FECompUltimoAutorizado {$name} inválido.");
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new RuntimeException("FECompUltimoAutorizado {$name} fuera de rango.");
        }
        return $value;
    }

    private function validateRepeatingMessages(DOMElement $container, string $itemName): void
    {
        foreach ($this->elementChildren($container) as $item) {
            if (
                $item->localName !== $itemName
                || $item->namespaceURI !== WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE
            ) {
                throw new RuntimeException('FECompUltimoAutorizado contiene evidencia provider malformada.');
            }
            $code = trim($this->singleChild(
                $item,
                'Code',
                WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE
            )->textContent);
            $msg = trim($this->singleChild(
                $item,
                'Msg',
                WsfeCompUltimoAutorizadoSoap11Call::SERVICE_NAMESPACE
            )->textContent);
            if (preg_match('/^[0-9]+$/D', $code) !== 1 || $msg === '' || strlen($msg) > 4096) {
                throw new RuntimeException('FECompUltimoAutorizado contiene evidencia provider inválida.');
            }
        }
    }

    /** @return list<DOMElement> */
    private function elementChildren(DOMElement $parent): array
    {
        $children = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $children[] = $node;
            }
        }
        return $children;
    }

    private function singleChild(DOMElement $parent, string $name, ?string $namespace): DOMElement
    {
        $matches = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name && $node->namespaceURI === $namespace) {
                $matches[] = $node;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException("FECompUltimoAutorizado requiere exactamente un elemento {$name}.");
        }
        return $matches[0];
    }

    private function optionalChild(DOMElement $parent, string $name, ?string $namespace): ?DOMElement
    {
        $matches = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name && $node->namespaceURI === $namespace) {
                $matches[] = $node;
            }
        }
        if (count($matches) > 1) {
            throw new RuntimeException("FECompUltimoAutorizado contiene más de un elemento {$name}.");
        }
        return $matches[0] ?? null;
    }
}
