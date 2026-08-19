<?php

namespace App\Domain\Fiscal;

interface WsaaTraBuilder
{
    public function build(
        WsaaAccessTicketRequest $request
    ): WsaaTra;
}
