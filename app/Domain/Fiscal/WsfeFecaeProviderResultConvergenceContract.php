<?php

namespace App\Domain\Fiscal;

interface WsfeFecaeProviderResultConvergenceContract
{
    public function converge(
        WsfeFecaeNormalizedResponseData $response
    ): FiscalAuthorizationTransportResult;
}
