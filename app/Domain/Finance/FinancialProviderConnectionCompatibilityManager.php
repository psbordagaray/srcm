<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialProviderCompatibilityStatus;
use App\Models\FinancialProviderCompatibility;
use App\Models\FinancialProviderConnection;
use App\Models\FinancialProviderConnectionCompatibilityBinding;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class FinancialProviderConnectionCompatibilityManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function bind(
        FinancialProviderConnection $connection,
        FinancialProviderCompatibility $compatibility,
        User $actor
    ): FinancialProviderConnectionCompatibilityBinding {
        $organizationId = $this->organizationId($actor);

        return DB::transaction(function () use (
            $organizationId,
            $connection,
            $compatibility,
            $actor
        ): FinancialProviderConnectionCompatibilityBinding {
            $lockedConnection = FinancialProviderConnection::query()
                ->forOrganization($organizationId)
                ->whereKey($connection->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedConnection) {
                throw new DomainException(
                    'La conexión financiera no pertenece a la organización activa.'
                );
            }

            $lockedCompatibility = FinancialProviderCompatibility::query()
                ->whereKey($compatibility->getKey())
                ->with('retirement')
                ->lockForUpdate()
                ->first();

            if (! $lockedCompatibility) {
                throw new DomainException(
                    'La compatibilidad financiera solicitada no existe.'
                );
            }

            if (
                $lockedConnection->provider_key
                    !== $lockedCompatibility->provider_key
            ) {
                throw new DomainException(
                    'La compatibilidad seleccionada pertenece a otro proveedor.'
                );
            }

            $this->assertCompatibilityCanBind(
                $lockedCompatibility
            );

            $current = $this->currentBindingQuery(
                $lockedConnection
            )
                ->lockForUpdate()
                ->first();

            if (
                $current
                && $current->financial_provider_compatibility_id
                    === $lockedCompatibility->getKey()
            ) {
                return $current->load('compatibility');
            }

            $binding =
                FinancialProviderConnectionCompatibilityBinding::query()
                    ->create([
                        'financial_provider_connection_id' =>
                            $lockedConnection->getKey(),
                        'financial_provider_compatibility_id' =>
                            $lockedCompatibility->getKey(),
                        'previous_binding_id' =>
                            $current?->getKey(),
                        'bound_by_user_id' =>
                            $actor->getKey(),
                        'bound_at' => now()->utc(),
                        'created_at' => now()->utc(),
                    ]);

            $this->audit->record(
                $lockedConnection,
                'financial_provider_compatibility_bound',
                $current
                    ? [
                        'binding_id' => $current->getKey(),
                        'compatibility_id' =>
                            $current
                                ->financial_provider_compatibility_id,
                    ]
                    : null,
                [
                    'binding_id' => $binding->getKey(),
                    'compatibility_id' =>
                        $lockedCompatibility->getKey(),
                    'registry_key' =>
                        $lockedCompatibility->registry_key,
                ]
            );

            return $binding->load([
                'compatibility',
                'previousBinding',
            ]);
        }, 3);
    }

    public function currentBinding(
        FinancialProviderConnection $connection
    ): ?FinancialProviderConnectionCompatibilityBinding {
        return $this->currentBindingQuery($connection)
            ->with('compatibility.retirement')
            ->first();
    }

    public function assertCanActivate(
        FinancialProviderConnection $connection
    ): void {
        $binding = $this->currentBinding($connection);

        if (! $binding) {
            return;
        }

        $compatibility = $binding->compatibility;

        if (! $compatibility) {
            throw new DomainException(
                'La conexión posee una vinculación de compatibilidad inválida.'
            );
        }

        $this->assertCompatibilityCanBind(
            $compatibility
        );
    }

    private function assertCompatibilityCanBind(
        FinancialProviderCompatibility $compatibility
    ): void {
        if ($compatibility->retirement) {
            throw new DomainException(
                'La compatibilidad de proveedor fue retirada y no puede vincularse ni reactivarse.'
            );
        }

        if ($compatibility->migration_required) {
            throw new DomainException(
                'La compatibilidad requiere migración antes de poder utilizarse.'
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
            throw new DomainException(
                'La compatibilidad de proveedor no está habilitada para conexiones operativas.'
            );
        }
    }

    private function currentBindingQuery(
        FinancialProviderConnection $connection
    ) {
        return FinancialProviderConnectionCompatibilityBinding::query()
            ->where(
                'financial_provider_connection_id',
                $connection->getKey()
            )
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from(
                        'financial_provider_connection_compatibility_bindings as successor'
                    )
                    ->whereColumn(
                        'successor.previous_binding_id',
                        'financial_provider_connection_compatibility_bindings.id'
                    );
            })
            ->latest('id');
    }

    private function organizationId(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canManageFinancialAccounts() ?? false)) {
            throw new DomainException(
                'No posee permiso para administrar compatibilidad de conexiones financieras.'
            );
        }

        return $organizationId;
    }
}
