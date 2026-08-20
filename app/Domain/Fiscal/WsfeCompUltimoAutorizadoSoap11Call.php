<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;
use Carbon\CarbonInterface;
use DomainException;

final class WsfeCompUltimoAutorizadoSoap11Call
{
    public const OPERATION = 'FECompUltimoAutorizado';
    public const SERVICE_NAMESPACE = 'http://ar.gov.afip.dif.FEV1/';
    public const ENVELOPE_NAMESPACE = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const CONTENT_TYPE = 'text/xml; charset=utf-8';
    public const SOAP_ACTION = 'http://ar.gov.afip.dif.FEV1/FECompUltimoAutorizado';

    public function __construct(
        private readonly FiscalRemoteSequenceQuery $query,
        private readonly WsaaAccessTicketRequest $accessTicketRequest,
        private readonly WsaaAccessTicket $accessTicket,
        CarbonInterface $at,
    ) {
        if (
            $accessTicketRequest->organizationId !== $query->organizationId
            || $accessTicketRequest->environment !== $query->environment
            || $accessTicketRequest->service !== 'wsfe'
        ) {
            throw new DomainException(
                'El scope WSAA no coincide con la consulta remota WSFE.'
            );
        }

        if ($query->pointOfSaleNumber < 1 || $query->pointOfSaleNumber > 99998) {
            throw new DomainException('PtoVta WSFE debe estar entre 1 y 99998.');
        }

        if ($query->voucherTypeCode < 1 || $query->voucherTypeCode > 999) {
            throw new DomainException('CbteTipo WSFE debe estar entre 1 y 999.');
        }

        $accessTicket->assertUsableFor($accessTicketRequest, $at);
    }

    public function environment(): FiscalEnvironment
    {
        return $this->query->environment;
    }

    public function query(): FiscalRemoteSequenceQuery
    {
        return $this->query;
    }

    /** @return array{Auth:array{Token:string,Sign:string,Cuit:string},PtoVta:int,CbteTipo:int} */
    public function operationParameters(): array
    {
        return [
            'Auth' => [
                'Token' => $this->accessTicket->token(),
                'Sign' => $this->accessTicket->sign(),
                'Cuit' => $this->accessTicketRequest->issuerCuit,
            ],
            'PtoVta' => $this->query->pointOfSaleNumber,
            'CbteTipo' => $this->query->voucherTypeCode,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new DomainException(
            'La llamada SOAP FECompUltimoAutorizado con secretos no puede serializarse.'
        );
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [
            'operation' => self::OPERATION,
            'soapAction' => self::SOAP_ACTION,
            'organizationId' => $this->query->organizationId,
            'environment' => $this->query->environment->value,
            'service' => $this->accessTicketRequest->service,
            'issuerCuit' => $this->accessTicketRequest->issuerCuit,
            'pointOfSaleNumber' => $this->query->pointOfSaleNumber,
            'voucherTypeCode' => $this->query->voucherTypeCode,
            'token' => '[REDACTED]',
            'sign' => '[REDACTED]',
        ];
    }
}
