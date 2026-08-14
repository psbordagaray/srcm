<?php

namespace App\Domain\Finance;

use App\Models\FinancialProviderCompatibility;
use App\Models\FinancialProviderCompatibilityRetirement;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FinancialProviderCompatibilityLifecycleManager
{
    public function retire(
        FinancialProviderCompatibility $compatibility,
        string $reason,
        string $srcmVersion,
        DateTimeInterface $retiredAt
    ): FinancialProviderCompatibilityRetirement {
        $reason = $this->text(
            $reason,
            500,
            'El motivo de retiro no es válido.'
        );
        $srcmVersion = $this->text(
            $srcmVersion,
            120,
            'La versión SRCM de retiro no es válida.'
        );
        $retiredAt = CarbonImmutable::instance($retiredAt)
            ->utc();

        return DB::transaction(function () use (
            $compatibility,
            $reason,
            $srcmVersion,
            $retiredAt
        ): FinancialProviderCompatibilityRetirement {
            $locked = FinancialProviderCompatibility::query()
                ->whereKey($compatibility->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La compatibilidad de proveedor no existe.'
                );
            }

            $existing =
                FinancialProviderCompatibilityRetirement::query()
                    ->where(
                        'financial_provider_compatibility_id',
                        $locked->getKey()
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existing) {
                if (
                    $existing->reason !== $reason
                    || $existing->srcm_version !== $srcmVersion
                    || ! $existing->retired_at->equalTo(
                        $retiredAt
                    )
                ) {
                    throw new DomainException(
                        'La compatibilidad ya fue retirada con otra evidencia.'
                    );
                }

                return $existing;
            }

            if ($this->hasActiveDependency($locked)) {
                throw new DomainException(
                    'No puede retirarse una compatibilidad mientras exista una conexión activa que dependa de ella.'
                );
            }

            return FinancialProviderCompatibilityRetirement::query()
                ->create([
                    'financial_provider_compatibility_id' =>
                        $locked->getKey(),
                    'reason' => $reason,
                    'srcm_version' => $srcmVersion,
                    'retired_at' => $retiredAt,
                    'created_at' => now()->utc(),
                ]);
        }, 3);
    }

    public function hasActiveDependency(
        FinancialProviderCompatibility $compatibility
    ): bool {
        return DB::table(
            'financial_provider_connection_compatibility_bindings as binding'
        )
            ->join(
                'financial_provider_connections as connection',
                'connection.id',
                '=',
                'binding.financial_provider_connection_id'
            )
            ->where(
                'binding.financial_provider_compatibility_id',
                $compatibility->getKey()
            )
            ->where('connection.active', true)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from(
                        'financial_provider_connection_compatibility_bindings as successor'
                    )
                    ->whereColumn(
                        'successor.previous_binding_id',
                        'binding.id'
                    );
            })
            ->exists();
    }

    private function text(
        string $value,
        int $max,
        string $message
    ): string {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            throw new DomainException($message);
        }

        return $value;
    }
}
