<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;

interface FiscalAuthorizationRuntimeScopeStore extends
    FiscalAuthorizationCredentialStore
{
    public function accessTicketRequestFor(
        int $organizationId,
        FiscalEnvironment $environment,
    ): WsaaAccessTicketRequest;
}
