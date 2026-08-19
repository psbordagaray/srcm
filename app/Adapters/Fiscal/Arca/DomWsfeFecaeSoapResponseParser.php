<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsfeFecaeSoap11Call;
use App\Domain\Fiscal\WsfeFecaeSoapResponseParser;
use App\Domain\Fiscal\WsfeFecaeSoapResultData;
use DOMDocument;
use DOMElement;
use RuntimeException;

final class DomWsfeFecaeSoapResponseParser implements
    WsfeFecaeSoapResponseParser
{
    public const MAX_SOAP_RESPONSE_BYTES =
        2097152;

    private const REPEATING_ELEMENTS = [
        'FECAEDetResponse',
        'Obs',
        'Evt',
        'Err',
    ];

    private const COMPLEX_CONTAINER_ELEMENTS = [
        'FeCabResp',
        'FeDetResp',
        'Observaciones',
        'Events',
        'Errors',
    ];

    public function parse(
        int $httpStatus,
        string $soapXml
    ): WsfeFecaeSoapResultData {
        if (
            $httpStatus < 100
            || $httpStatus > 599
            || $soapXml === ''
            || strlen($soapXml)
                > self::MAX_SOAP_RESPONSE_BYTES
        ) {
            throw new RuntimeException(
                'La respuesta WSFE está fuera de los límites admitidos.'
            );
        }

        $document = $this->secureDom(
            $soapXml
        );
        $envelope =
            $document->documentElement;

        if (
            ! $envelope instanceof DOMElement
            || $envelope->localName
                !== 'Envelope'
            || $envelope->namespaceURI
                !== WsfeFecaeSoap11Call::
                    ENVELOPE_NAMESPACE
        ) {
            throw new RuntimeException(
                'La respuesta WSFE no es SOAP 1.1 válido.'
            );
        }

        $body = $this->singleChild(
            $envelope,
            'Body',
            WsfeFecaeSoap11Call::
                ENVELOPE_NAMESPACE
        );

        $fault = $this->optionalChild(
            $body,
            'Fault',
            WsfeFecaeSoap11Call::
                ENVELOPE_NAMESPACE
        );

        if ($fault instanceof DOMElement) {
            throw new RuntimeException(
                'WSFE devolvió SOAP Fault.'
            );
        }

        if (
            $httpStatus < 200
            || $httpStatus >= 300
        ) {
            throw new RuntimeException(
                'WSFE devolvió HTTP no exitoso sin SOAP Fault interpretable.'
            );
        }

        $response = $this->singleChild(
            $body,
            'FECAESolicitarResponse',
            WsfeFecaeSoap11Call::
                SERVICE_NAMESPACE
        );

        $result = $this->singleChild(
            $response,
            'FECAESolicitarResult',
            WsfeFecaeSoap11Call::
                SERVICE_NAMESPACE
        );

        return new WsfeFecaeSoapResultData(
            $this->childrenMap(
                $result
            )
        );
    }

    private function secureDom(
        string $xml
    ): DOMDocument {
        if (
            stripos(
                $xml,
                '<!DOCTYPE'
            ) !== false
        ) {
            throw new RuntimeException(
                'La respuesta WSFE no admite DOCTYPE.'
            );
        }

        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->validateOnParse = false;

        $previous =
            libxml_use_internal_errors(
                true
            );

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
            libxml_use_internal_errors(
                $previous
            );
        }

        if ($loaded !== true) {
            throw new RuntimeException(
                'La respuesta XML de WSFE es inválida.'
            );
        }

        return $document;
    }

    /**
     * @return array<string,mixed>
     */
    private function childrenMap(
        DOMElement $parent
    ): array {
        $result = [];

        foreach (
            $parent->childNodes
            as $node
        ) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if (
                $node->namespaceURI
                    !== WsfeFecaeSoap11Call::
                        SERVICE_NAMESPACE
            ) {
                throw new RuntimeException(
                    'La respuesta WSFE contiene un elemento fuera del namespace esperado.'
                );
            }

            $name = $node->localName;
            $value = $this->elementValue(
                $node
            );
            $repeating = in_array(
                $name,
                self::REPEATING_ELEMENTS,
                true
            );

            if (
                ! array_key_exists(
                    $name,
                    $result
                )
            ) {
                $result[$name] =
                    $repeating
                        ? [$value]
                        : $value;

                continue;
            }

            if (
                ! is_array(
                    $result[$name]
                )
                || ! array_is_list(
                    $result[$name]
                )
            ) {
                $result[$name] = [
                    $result[$name],
                ];
            }

            $result[$name][] =
                $value;
        }

        return $result;
    }

    private function elementValue(
        DOMElement $element
    ): mixed {
        foreach (
            $element->childNodes
            as $node
        ) {
            if ($node instanceof DOMElement) {
                return $this->childrenMap(
                    $element
                );
            }
        }

        if (
            in_array(
                $element->localName,
                self::COMPLEX_CONTAINER_ELEMENTS,
                true
            )
        ) {
            return [];
        }

        return trim(
            $element->textContent
        );
    }

    private function singleChild(
        DOMElement $parent,
        string $localName,
        ?string $namespace
    ): DOMElement {
        $matches = [];

        foreach (
            $parent->childNodes
            as $node
        ) {
            if (
                $node instanceof DOMElement
                && $node->localName
                    === $localName
                && $node->namespaceURI
                    === $namespace
            ) {
                $matches[] = $node;
            }
        }

        if (count($matches) !== 1) {
            throw new RuntimeException(
                "La respuesta WSFE requiere exactamente un elemento {$localName}."
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

        foreach (
            $parent->childNodes
            as $node
        ) {
            if (
                $node instanceof DOMElement
                && $node->localName
                    === $localName
                && $node->namespaceURI
                    === $namespace
            ) {
                $matches[] = $node;
            }
        }

        if (count($matches) > 1) {
            throw new RuntimeException(
                "La respuesta WSFE contiene más de un elemento {$localName}."
            );
        }

        return $matches[0] ?? null;
    }
}
