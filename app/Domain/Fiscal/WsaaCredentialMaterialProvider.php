<?php

namespace App\Domain\Fiscal;

interface WsaaCredentialMaterialProvider
{
    public function forRequest(
        WsaaAccessTicketRequest $request
    ): WsaaCredentialMaterial;
}
