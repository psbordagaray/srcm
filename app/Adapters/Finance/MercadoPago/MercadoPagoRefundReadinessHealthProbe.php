<?php

namespace App\Adapters\Finance\MercadoPago;

use App\Contracts\Finance\FinancialProviderConnectionHealthProbe;
use App\Domain\Finance\FinancialProviderHealthObservation;
use App\Enums\FinancialProviderCapability;
use App\Enums\FinancialProviderConnectionHealthStatus;
use App\Models\FinancialProviderConnection;
use DomainException;

final class MercadoPagoRefundReadinessHealthProbe
    implements FinancialProviderConnectionHealthProbe
{
    public function __construct(
        private readonly MercadoPagoReadOnlyConnectionHealthProbe $readProbe
    ) {
    }

    public function providerKey(): string
    {
        return 'mercado-pago';
    }

    public function capability(): FinancialProviderCapability
    {
        return FinancialProviderCapability::Refund;
    }

    public function probe(
        FinancialProviderConnection $connection
    ): FinancialProviderHealthObservation {
        if (
            $connection->provider_key
                !== $this->providerKey()
        ) {
            throw new DomainException(
                'El readiness probe de refund Mercado Pago no corresponde a esta conexión.'
            );
        }

        $read =
            $this->readProbe->probe(
                $connection
            );

        [$status, $diagnostic] =
            match ($read->status) {
                FinancialProviderConnectionHealthStatus::Healthy =>
                    [
                        FinancialProviderConnectionHealthStatus::Degraded,
                        'refund_smoke_required',
                    ],
                FinancialProviderConnectionHealthStatus::Degraded =>
                    [
                        FinancialProviderConnectionHealthStatus::Degraded,
                        'refund_readiness_degraded',
                    ],
                FinancialProviderConnectionHealthStatus::Unavailable =>
                    [
                        FinancialProviderConnectionHealthStatus::Unavailable,
                        'refund_readiness_unavailable',
                    ],
                FinancialProviderConnectionHealthStatus::Unknown =>
                    [
                        FinancialProviderConnectionHealthStatus::Unknown,
                        'refund_readiness_unknown',
                    ],
            };

        return new FinancialProviderHealthObservation(
            capability:
                FinancialProviderCapability::Refund,
            status:
                $status,
            checkedAt:
                $read->checkedAt,
            sourceKey:
                'mercado-pago:refund-readiness',
            diagnosticCode:
                $diagnostic,
            latencyMs:
                $read->latencyMs
        );
    }
}
