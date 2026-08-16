<?php

namespace App\Domain\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoRefundReadinessHealthProbe;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderConnectionHealthStatus;
use App\Models\FinancialProviderConnection;
use App\Models\FinancialProviderConnectionCompatibilityBinding;
use App\Models\FinancialProviderConnectionHealthCheck;
use App\Models\User;
use DomainException;

final class MercadoPagoPointRefundActivationManager
{
    private const REFUND_REGISTRY_KEY =
        'mercado-pago:orders-v1:point-refund-v1:p8.4.3.3';

    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly FinancialProviderCompatibilityRegistry $compatibilityRegistry,
        private readonly FinancialProviderConnectionCompatibilityManager $bindings,
        private readonly FinancialProviderConnectionHealthManager $health,
        private readonly FinancialProviderAutomationGate $automationGate,
        private readonly MercadoPagoRefundReadinessHealthProbe $readinessProbe
    ) {
    }

    public function prepare(
        FinancialProviderConnection $connection,
        User $actor
    ): MercadoPagoPointRefundActivationResult {
        $organizationId =
            $this->organizationId(
                $actor
            );

        $connection =
            FinancialProviderConnection::query()
                ->forOrganization(
                    $organizationId
                )
                ->whereKey(
                    $connection->getKey()
                )
                ->first();

        if (! $connection) {
            throw new DomainException(
                'La conexión financiera no pertenece a la organización activa.'
            );
        }

        if (
            $connection->provider_key
                !== 'mercado-pago'
        ) {
            throw new DomainException(
                'La activación P8.4.3.4 sólo admite conexiones Mercado Pago.'
            );
        }

        if (! $connection->active) {
            throw new DomainException(
                'La conexión Mercado Pago está inactiva.'
            );
        }

        $current =
            $this->bindings
                ->currentBinding(
                    $connection
                );

        if (
            $current
                ?->compatibility
                ?->registry_key
                === self::REFUND_REGISTRY_KEY
        ) {
            $latest =
                $this->health
                    ->latestForBinding(
                        $connection,
                        FinancialProviderCapability::Refund,
                        $current->getKey()
                    );

            if ($latest) {
                return $this->result(
                    $connection,
                    $current,
                    $latest
                );
            }
        }

        $preflight =
            $this->readinessProbe
                ->probe(
                    $connection
                );

        if (
            $preflight->status
                !== FinancialProviderConnectionHealthStatus::Degraded
            || $preflight->diagnosticCode
                !== 'refund_smoke_required'
        ) {
            throw new DomainException(
                'La preparación de Refund Mercado Pago no superó el preflight seguro: '
                .($preflight->diagnosticCode
                    ?? 'refund_readiness_unknown')
                .'.'
            );
        }

        $compatibility =
            $this->compatibilityRegistry
                ->registerMercadoPagoPointRefundContractV1();

        $binding =
            $this->bindings->bind(
                $connection,
                $compatibility,
                $actor
            );

        $health =
            $this->health->record(
                $connection,
                $preflight
            );

        return $this->result(
            $connection,
            $binding,
            $health
        );
    }

    private function result(
        FinancialProviderConnection $connection,
        FinancialProviderConnectionCompatibilityBinding $binding,
        FinancialProviderConnectionHealthCheck $health
    ): MercadoPagoPointRefundActivationResult {
        $decision =
            $this->automationGate
                ->evaluate(
                    $connection,
                    FinancialProviderCapability::Refund
                );

        return new MercadoPagoPointRefundActivationResult(
            binding:
                $binding->load([
                    'compatibility.capabilities',
                    'previousBinding.compatibility',
                ]),
            health:
                $health,
            decision:
                $decision
        );
    }

    private function organizationId(
        User $actor
    ): int {
        $organizationId =
            $this->currentOrganization->id(
                $actor
            );

        $role =
            $this->currentOrganization
                ->roleFor(
                    $actor
                );

        if (
            ! (
                $role
                    ?->canManageFinancialAccounts()
                ?? false
            )
        ) {
            throw new DomainException(
                'Sólo un administrador puede preparar la activación de refund de Mercado Pago.'
            );
        }

        return $organizationId;
    }
}
