<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;

interface WsaaCredentialMaterialReferenceStore
{
    public function hasAny(
        FiscalEnvironment $environment
    ): bool;

    public function forRequest(
        WsaaAccessTicketRequest $request
    ): WsaaCredentialMaterialReference;
}
