<?php

namespace App\Domain\Fiscal;

interface WsaaAccessTicketProvider
{
    public function ticketFor(
        WsaaAccessTicketRequest $request
    ): WsaaAccessTicket;
}
