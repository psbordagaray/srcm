<?php

namespace App\Domain\Fiscal;

interface WsaaLoginCmsTransport
{
    public function exchange(
        WsaaAccessTicketRequest $request,
        WsaaSignedCms $signedCms
    ): WsaaAccessTicket;
}
