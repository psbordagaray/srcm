<?php

namespace App\Domain\Finance;

use App\Enums\FinancialProviderCapability;
use App\Models\FinancialProviderConnection;

final class FinancialProviderOperationalStatusReader
{
    public function __construct(
        private readonly FinancialProviderConnectionCompatibilityManager $compatibilityBindings,
        private readonly FinancialProviderConnectionHealthManager $health,
        private readonly FinancialProviderAutomationGate $gate,
        private readonly FinancialProviderHealthProbeRegistry $probes
    ) {
    }

    public function read(
        FinancialProviderConnection $connection,
        FinancialProviderCapability $capability
    ): FinancialProviderOperationalStatus {
        $binding = $this->compatibilityBindings
            ->currentBinding($connection);

        $compatibility = $binding?->compatibility;

        $capabilityContract = $compatibility
            ?->capabilities()
            ->where(
                'capability',
                $capability->value
            )
            ->first();

        $latest = $this->health->latestForBinding(
            $connection,
            $capability,
            $binding?->getKey()
        );

        $decision = $this->gate->evaluate(
            $connection,
            $capability
        );

        return new FinancialProviderOperationalStatus(
            providerKey: $connection->provider_key,
            registryKey: $compatibility?->registry_key,
            compatibilityStatus:
                $compatibility?->compatibility_status->value,
            capabilityStatus:
                $capabilityContract?->compatibility_status->value,
            healthStatus: $latest?->health_status->value,
            diagnosticCode: $latest?->diagnostic_code,
            checkedAt: $latest?->checked_at,
            automationAllowed: $decision->allowed,
            automationReason: $decision->reasonCode,
            probeSupported: $this->probes->supports(
                $connection,
                $capability
            )
        );
    }
}
