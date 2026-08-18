<?php

namespace App\Domain\Fiscal;

use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;

final readonly class FiscalPointOfSaleData
{
    public function __construct(
        public int $pointNumber,
        public FiscalEnvironment $environment,
        public FiscalIntegrationMode $integrationMode
    ) {
    }
}

