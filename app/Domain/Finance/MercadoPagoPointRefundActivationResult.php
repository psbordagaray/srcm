<?php

namespace App\Domain\Finance;

use App\Models\FinancialProviderConnectionCompatibilityBinding;
use App\Models\FinancialProviderConnectionHealthCheck;

final readonly class MercadoPagoPointRefundActivationResult
{
    public function __construct(
        public FinancialProviderConnectionCompatibilityBinding $binding,
        public FinancialProviderConnectionHealthCheck $health,
        public FinancialProviderAutomationDecision $decision
    ) {
    }
}
