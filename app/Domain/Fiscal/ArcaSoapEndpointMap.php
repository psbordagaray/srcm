<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;

interface ArcaSoapEndpointMap
{
    public function for(
        FiscalEnvironment $environment
    ): ArcaSoapEndpointSet;
}
