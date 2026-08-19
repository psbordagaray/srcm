<?php

namespace App\Domain\Fiscal;

interface WsaaLoginCmsResponseParser
{
    public function parse(
        int $httpStatus,
        string $soapXml,
        WsaaAccessTicketRequest $request
    ): WsaaAccessTicket;
}
