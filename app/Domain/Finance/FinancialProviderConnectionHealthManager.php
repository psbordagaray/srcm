<?php

namespace App\Domain\Finance;

use App\Enums\FinancialProviderCapability;
use App\Models\FinancialProviderConnection;
use App\Models\FinancialProviderConnectionHealthCheck;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FinancialProviderConnectionHealthManager
{
    public function __construct(
        private readonly FinancialProviderConnectionCompatibilityManager $compatibilityBindings
    ) {
    }

    public function record(
        FinancialProviderConnection $connection,
        FinancialProviderHealthObservation $observation
    ): FinancialProviderConnectionHealthCheck {
        return DB::transaction(function () use (
            $connection,
            $observation
        ): FinancialProviderConnectionHealthCheck {
            $locked = FinancialProviderConnection::query()
                ->whereKey($connection->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La conexión financiera no existe.'
                );
            }

            $binding = $this->compatibilityBindings
                ->currentBinding($locked);

            if ($binding) {
                $declaredCapability =
                    $binding->compatibility
                        ?->capabilities()
                        ->where(
                            'capability',
                            $observation->capability->value
                        )
                        ->exists() ?? false;

                if (! $declaredCapability) {
                    throw new DomainException(
                        'La capacidad observada no está declarada en el snapshot de compatibilidad actual.'
                    );
                }
            }

            return FinancialProviderConnectionHealthCheck::query()
                ->create([
                    'organization_id' =>
                        $locked->organization_id,
                    'financial_provider_connection_id' =>
                        $locked->getKey(),
                    'financial_provider_connection_compatibility_binding_id' =>
                        $binding?->getKey(),
                    'capability' =>
                        $observation->capability->value,
                    'health_status' =>
                        $observation->status->value,
                    'source_key' =>
                        $observation->sourceKey,
                    'diagnostic_code' =>
                        $observation->diagnosticCode,
                    'latency_ms' =>
                        $observation->latencyMs,
                    'checked_at' =>
                        $observation->checkedAt,
                    'created_at' => now()->utc(),
                ]);
        }, 3);
    }

    public function latestForBinding(
        FinancialProviderConnection $connection,
        FinancialProviderCapability $capability,
        ?int $bindingId
    ): ?FinancialProviderConnectionHealthCheck {
        $query = FinancialProviderConnectionHealthCheck::query()
            ->where(
                'financial_provider_connection_id',
                $connection->getKey()
            )
            ->where(
                'capability',
                $capability->value
            );

        $bindingId === null
            ? $query->whereNull(
                'financial_provider_connection_compatibility_binding_id'
            )
            : $query->where(
                'financial_provider_connection_compatibility_binding_id',
                $bindingId
            );

        return $query
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();
    }
}
