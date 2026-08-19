<?php

namespace App\Adapters\Fiscal\Arca;

use App\Domain\Fiscal\WsfeFecaeSoap11Call;
use App\Domain\Fiscal\WsfeFecaeSoapSerializer;
use DOMDocument;
use DOMElement;
use DomainException;

final class DomWsfeFecaeSoapSerializer implements
    WsfeFecaeSoapSerializer
{
    /**
     * @return array<string,string>
     */
    public function headers(
        WsfeFecaeSoap11Call $call
    ): array {
        return [
            'Content-Type' =>
                WsfeFecaeSoap11Call::CONTENT_TYPE,
            'SOAPAction' =>
                '"'
                . WsfeFecaeSoap11Call::SOAP_ACTION
                . '"',
            'Accept' =>
                'text/xml',
        ];
    }

    public function body(
        WsfeFecaeSoap11Call $call
    ): string {
        $document = new DOMDocument(
            '1.0',
            'UTF-8'
        );
        $document->formatOutput = false;

        $envelope =
            $document->createElementNS(
                WsfeFecaeSoap11Call::ENVELOPE_NAMESPACE,
                'soapenv:Envelope'
            );

        $document->appendChild(
            $envelope
        );

        $body =
            $document->createElementNS(
                WsfeFecaeSoap11Call::ENVELOPE_NAMESPACE,
                'soapenv:Body'
            );

        $envelope->appendChild(
            $body
        );

        $operation =
            $document->createElementNS(
                WsfeFecaeSoap11Call::SERVICE_NAMESPACE,
                WsfeFecaeSoap11Call::OPERATION
            );

        $body->appendChild(
            $operation
        );

        $this->appendMap(
            $document,
            $operation,
            $call->operationParameters()
        );

        $xml = $document->saveXML();

        if (
            ! is_string($xml)
            || $xml === ''
        ) {
            throw new DomainException(
                'No se pudo serializar la llamada SOAP FECAESolicitar.'
            );
        }

        return $xml;
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function appendMap(
        DOMDocument $document,
        DOMElement $parent,
        array $fields
    ): void {
        foreach ($fields as $name => $value) {
            if (
                ! is_string($name)
                || preg_match(
                    '/^[A-Za-z_][A-Za-z0-9_.-]*$/D',
                    $name
                ) !== 1
            ) {
                throw new DomainException(
                    'La estructura WSFE contiene un nombre XML inválido.'
                );
            }

            if (
                is_array($value)
                && array_is_list($value)
            ) {
                foreach ($value as $item) {
                    $element = $this->element(
                        $document,
                        $name
                    );

                    $parent->appendChild(
                        $element
                    );

                    if (is_array($item)) {
                        $this->appendMap(
                            $document,
                            $element,
                            $item
                        );

                        continue;
                    }

                    $element->appendChild(
                        $document->createTextNode(
                            $this->scalarText(
                                $item
                            )
                        )
                    );
                }

                continue;
            }

            $element = $this->element(
                $document,
                $name
            );

            $parent->appendChild(
                $element
            );

            if (is_array($value)) {
                $this->appendMap(
                    $document,
                    $element,
                    $value
                );

                continue;
            }

            if ($value === null) {
                continue;
            }

            $element->appendChild(
                $document->createTextNode(
                    $this->scalarText(
                        $value
                    )
                )
            );
        }
    }

    private function element(
        DOMDocument $document,
        string $name
    ): DOMElement {
        return $document->createElementNS(
            WsfeFecaeSoap11Call::SERVICE_NAMESPACE,
            $name
        );
    }

    private function scalarText(
        mixed $value
    ): string {
        if (
            ! is_string($value)
            && ! is_int($value)
            && ! is_float($value)
            && ! is_bool($value)
        ) {
            throw new DomainException(
                'La estructura WSFE contiene un valor no serializable.'
            );
        }

        if (is_bool($value)) {
            return $value
                ? 'true'
                : 'false';
        }

        return (string) $value;
    }
}
