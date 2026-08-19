<?php

namespace App\Domain\Fiscal;

use Carbon\CarbonInterface;
use DomainException;

final class WsfeFecaeSoap11Call
{
    public const OPERATION = 'FECAESolicitar';

    public const SERVICE_NAMESPACE =
        'http://ar.gov.afip.dif.FEV1/';

    public const ENVELOPE_NAMESPACE =
        'http://schemas.xmlsoap.org/soap/envelope/';

    public const CONTENT_TYPE =
        'text/xml; charset=utf-8';

    public const SOAP_ACTION =
        'http://ar.gov.afip.dif.FEV1/FECAESolicitar';

    private readonly FiscalAuthorizationTransportRequest $transportRequest;

    private readonly WsaaAccessTicketRequest $accessTicketRequest;

    private readonly WsaaAccessTicket $accessTicket;

    public function __construct(
        FiscalAuthorizationTransportRequest $transportRequest,
        WsaaAccessTicketRequest $accessTicketRequest,
        WsaaAccessTicket $accessTicket,
        CarbonInterface $at,
    ) {
        if (
            $accessTicketRequest->organizationId
                !== $transportRequest->organizationId
            || $accessTicketRequest->environment
                !== $transportRequest->environment
        ) {
            throw new DomainException(
                'El scope WSAA no coincide con la organización y ambiente del request fiscal.'
            );
        }

        $accessTicket->assertUsableFor(
            $accessTicketRequest,
            $at
        );

        $header = $transportRequest
            ->fecaeRequest
            ->header;

        $detail = $transportRequest
            ->fecaeRequest
            ->detail;

        if (
            $header->recordCount !== 1
            || $header->pointOfSaleNumber
                !== $transportRequest->pointOfSaleNumber
            || $header->voucherTypeCode
                !== $transportRequest->voucherTypeCode
            || $detail->voucherFrom
                !== $transportRequest->voucherNumber
            || $detail->voucherTo
                !== $transportRequest->voucherNumber
        ) {
            throw new DomainException(
                'El FeCAEReq no coincide con la identidad y secuencia del transport request.'
            );
        }

        $this->transportRequest =
            $transportRequest;
        $this->accessTicketRequest =
            $accessTicketRequest;
        $this->accessTicket =
            $accessTicket;
    }

    public function environment(): \App\Enums\FiscalEnvironment
    {
        return $this->transportRequest
            ->environment;
    }

    /**
     * Canonical SOAP 1.1 operation parameter object.
     *
     * Token and Sign are exposed only at this explicit provider-edge call.
     *
     * @return array{
     *   Auth:array{
     *     Token:string,
     *     Sign:string,
     *     Cuit:string
     *   },
     *   FeCAEReq:array<string,mixed>
     * }
     */
    public function operationParameters(): array
    {
        return [
            'Auth' => [
                'Token' =>
                    $this->accessTicket->token(),
                'Sign' =>
                    $this->accessTicket->sign(),
                'Cuit' =>
                    $this->accessTicketRequest
                        ->issuerCuit,
            ],
            'FeCAEReq' =>
                $this->transportRequest
                    ->fecaeRequest
                    ->toWsfeArray(),
        ];
    }

    /**
     * Secret-containing provider-edge call objects are never durable.
     *
     * @return never
     */
    public function __serialize(): array
    {
        throw new DomainException(
            'La llamada SOAP FECAE con secretos no puede serializarse.'
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'operation' => self::OPERATION,
            'soapAction' => self::SOAP_ACTION,
            'organizationId' =>
                $this->transportRequest
                    ->organizationId,
            'environment' =>
                $this->transportRequest
                    ->environment
                    ->value,
            'service' =>
                $this->accessTicketRequest
                    ->service,
            'issuerCuit' =>
                $this->accessTicketRequest
                    ->issuerCuit,
            'pointOfSaleNumber' =>
                $this->transportRequest
                    ->pointOfSaleNumber,
            'voucherTypeCode' =>
                $this->transportRequest
                    ->voucherTypeCode,
            'voucherNumber' =>
                $this->transportRequest
                    ->voucherNumber,
            'token' => '[REDACTED]',
            'sign' => '[REDACTED]',
        ];
    }
}
