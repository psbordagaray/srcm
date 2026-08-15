<?php

namespace App\Domain\Finance;

use App\Enums\FinancialProviderCapability;
use App\Models\FinancialProviderConnection;
use App\Models\FinancialProviderConnectionHealthCheck;

final class FinancialProviderHealthProbeRunner
{
    public function __construct(
        private readonly FinancialProviderHealthProbeRegistry $registry,
        private readonly FinancialProviderConnectionHealthManager $health
    ) {
    }

    public function run(
        FinancialProviderConnection $connection,
        FinancialProviderCapability $capability
    ): FinancialProviderConnectionHealthCheck {
        $probe = $this->registry->resolve(
            $connection,
            $capability
        );

        return $this->health->record(
            $connection,
            $probe->probe($connection)
        );
    }
}
