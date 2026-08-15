<?php

namespace App\Domain\Finance;

use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderCompatibilityStatus;
use App\Enums\FinancialProviderConnectionHealthStatus;
use App\Models\FinancialProviderConnection;
use DomainException;

final class FinancialProviderAutomationGate
{
    public function __construct(
        private readonly FinancialProviderConnectionCompatibilityManager $compatibilityBindings,
        private readonly FinancialProviderConnectionHealthManager $health
    ) {
    }

    public function evaluate(
        FinancialProviderConnection $connection,
        FinancialProviderCapability $capability
    ): FinancialProviderAutomationDecision {
        if (! $connection->active) {
            return new FinancialProviderAutomationDecision(
                false,
                'connection_inactive'
            );
        }

        $binding = $this->compatibilityBindings
            ->currentBinding($connection);

        if (! $binding) {
            return new FinancialProviderAutomationDecision(
                false,
                'compatibility_unbound'
            );
        }

        $compatibility = $binding->compatibility;

        if (! $compatibility) {
            return new FinancialProviderAutomationDecision(
                false,
                'compatibility_missing'
            );
        }

        if ($compatibility->retirement) {
            return new FinancialProviderAutomationDecision(
                false,
                'compatibility_retired'
            );
        }

        if ($compatibility->migration_required) {
            return new FinancialProviderAutomationDecision(
                false,
                'compatibility_migration_required'
            );
        }

        if (
            ! in_array(
                $compatibility->compatibility_status,
                [
                    FinancialProviderCompatibilityStatus::Compatible,
                    FinancialProviderCompatibilityStatus::Degraded,
                ],
                true
            )
        ) {
            return new FinancialProviderAutomationDecision(
                false,
                'compatibility_'.$compatibility
                    ->compatibility_status->value
            );
        }

        $capabilityContract = $compatibility
            ->capabilities()
            ->where(
                'capability',
                $capability->value
            )
            ->first();

        if (! $capabilityContract) {
            return new FinancialProviderAutomationDecision(
                false,
                'capability_not_registered'
            );
        }

        if (
            $capabilityContract->compatibility_status
                !== FinancialProviderCompatibilityStatus::Compatible
        ) {
            return new FinancialProviderAutomationDecision(
                false,
                'capability_'.$capabilityContract
                    ->compatibility_status->value
            );
        }

        $latest = $this->health->latestForBinding(
            $connection,
            $capability,
            $binding->getKey()
        );

        if (! $latest) {
            return new FinancialProviderAutomationDecision(
                false,
                'health_unknown'
            );
        }

        if (
            $latest->health_status
                !== FinancialProviderConnectionHealthStatus::Healthy
        ) {
            return new FinancialProviderAutomationDecision(
                false,
                'health_'.$latest->health_status->value
            );
        }

        return new FinancialProviderAutomationDecision(
            true,
            'allowed'
        );
    }

    public function assertCanAutomate(
        FinancialProviderConnection $connection,
        FinancialProviderCapability $capability
    ): void {
        $decision = $this->evaluate(
            $connection,
            $capability
        );

        if (! $decision->allowed) {
            throw new DomainException(
                'Automatización financiera bloqueada: '
                    .$decision->reasonCode.'.'
            );
        }
    }
}
