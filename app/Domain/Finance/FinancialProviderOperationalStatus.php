<?php

namespace App\Domain\Finance;

use Carbon\CarbonImmutable;

final readonly class FinancialProviderOperationalStatus
{
    public function __construct(
        public string $providerKey,
        public ?string $registryKey,
        public ?string $compatibilityStatus,
        public ?string $capabilityStatus,
        public ?string $healthStatus,
        public ?string $diagnosticCode,
        public ?CarbonImmutable $checkedAt,
        public bool $automationAllowed,
        public string $automationReason,
        public bool $probeSupported
    ) {
    }
}
