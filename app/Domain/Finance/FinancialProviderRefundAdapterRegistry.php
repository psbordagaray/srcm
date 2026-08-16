<?php

namespace App\Domain\Finance;

use App\Contracts\Finance\FinancialProviderRefundAdapter;
use DomainException;
use Illuminate\Support\Str;

final class FinancialProviderRefundAdapterRegistry
{
    /**
     * @var array<string, FinancialProviderRefundAdapter>
     */
    private array $adapters = [];

    /**
     * @param iterable<FinancialProviderRefundAdapter> $adapters
     */
    public function __construct(
        iterable $adapters = []
    ) {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(
        FinancialProviderRefundAdapter $adapter
    ): void {
        $providerKey =
            $this->normalizeProviderKey(
                $adapter->providerKey()
            );

        if (isset($this->adapters[$providerKey])) {
            throw new DomainException(
                'Ya existe un adapter de reembolso para ese proveedor.'
            );
        }

        $this->adapters[$providerKey] =
            $adapter;
    }

    public function adapterFor(
        string $providerKey
    ): FinancialProviderRefundAdapter {
        $providerKey =
            $this->normalizeProviderKey(
                $providerKey
            );

        $adapter =
            $this->adapters[$providerKey]
            ?? null;

        if (! $adapter) {
            throw new DomainException(
                'No existe un adapter de reembolso validado para ese proveedor.'
            );
        }

        return $adapter;
    }

    private function normalizeProviderKey(
        string $value
    ): string {
        $value =
            Str::slug(
                trim($value)
            );

        if (
            $value === ''
            || mb_strlen($value) > 100
        ) {
            throw new DomainException(
                'La clave del proveedor de reembolso no es válida.'
            );
        }

        return $value;
    }
}
