<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;
use App\Enums\WsaaCmsDigestAlgorithm;

interface WsaaCmsDigestPolicy
{
    public function forEnvironment(
        FiscalEnvironment $environment
    ): WsaaCmsDigestAlgorithm;
}
