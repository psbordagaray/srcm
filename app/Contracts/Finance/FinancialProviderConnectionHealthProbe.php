<?php

namespace App\Contracts\Finance;

use App\Domain\Finance\FinancialProviderHealthObservation;
use App\Enums\FinancialProviderCapability;
use App\Models\FinancialProviderConnection;

interface FinancialProviderConnectionHealthProbe
{
    public function providerKey(): string;

    public function capability(): FinancialProviderCapability;

    public function probe(
        FinancialProviderConnection $connection
    ): FinancialProviderHealthObservation;
}
