<?php

namespace App\Domain\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoReadOnlyConnectionHealthProbe;
use App\Contracts\Finance\FinancialProviderConnectionHealthProbe;
use App\Enums\FinancialProviderCapability;
use App\Models\FinancialProviderConnection;
use DomainException;

final class FinancialProviderHealthProbeRegistry
{
    /**
     * @var list<FinancialProviderConnectionHealthProbe>
     */
    private array $probes;

    public function __construct(
        MercadoPagoReadOnlyConnectionHealthProbe $mercadoPagoRead
    ) {
        $this->probes = [
            $mercadoPagoRead,
        ];
    }

    public function supports(
        FinancialProviderConnection $connection,
        FinancialProviderCapability $capability
    ): bool {
        return $this->match($connection, $capability) !== null;
    }

    public function resolve(
        FinancialProviderConnection $connection,
        FinancialProviderCapability $capability
    ): FinancialProviderConnectionHealthProbe {
        $probe = $this->match($connection, $capability);

        if (! $probe) {
            throw new DomainException(
                'No existe un health probe seguro para este proveedor y capacidad.'
            );
        }

        return $probe;
    }

    private function match(
        FinancialProviderConnection $connection,
        FinancialProviderCapability $capability
    ): ?FinancialProviderConnectionHealthProbe {
        foreach ($this->probes as $probe) {
            if (
                $probe->providerKey() === $connection->provider_key
                && $probe->capability() === $capability
            ) {
                return $probe;
            }
        }

        return null;
    }
}
