<?php

namespace App\Domain\Finance;

final readonly class FinancialProviderAutomationDecision
{
    public function __construct(
        public bool $allowed,
        public string $reasonCode
    ) {
    }
}
