<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use App\Models\FinancialProviderConnection;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinancialProviderConnectionManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function connect(
        FinancialAccount $account,
        string $providerKey,
        User $actor,
        ?string $externalAccountId = null
    ): FinancialProviderConnection {
        $organizationId = $this->organizationId($actor);
        $providerKey = $this->providerKey($providerKey);
        $externalAccountId = $this->externalAccountId(
            $externalAccountId
        );

        return DB::transaction(function () use (
            $organizationId,
            $account,
            $providerKey,
            $externalAccountId,
            $actor
        ): FinancialProviderConnection {
            $lockedAccount = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey($account->getKey())
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $lockedAccount) {
                throw new DomainException(
                    'La cuenta financiera no está disponible en la organización activa.'
                );
            }

            if (
                in_array(
                    $lockedAccount->type,
                    [
                        FinancialAccountType::CashBox,
                        FinancialAccountType::CashReserve,
                    ],
                    true
                )
            ) {
                throw new DomainException(
                    'Una cuenta de efectivo no admite conexión con un proveedor financiero externo.'
                );
            }

            if (
                blank($lockedAccount->provider)
                || Str::slug((string) $lockedAccount->provider)
                    !== $providerKey
            ) {
                throw new DomainException(
                    'El proveedor configurado en la cuenta no coincide con la conexión solicitada.'
                );
            }

            $existing = FinancialProviderConnection::query()
                ->forOrganization($organizationId)
                ->where(
                    'financial_account_id',
                    $lockedAccount->getKey()
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    $existing->provider_key !== $providerKey
                    || $existing->external_account_id
                        !== $externalAccountId
                ) {
                    throw new DomainException(
                        'La cuenta financiera ya posee otra conexión de proveedor.'
                    );
                }

                return $existing;
            }

            if (
                $externalAccountId !== null
                && FinancialProviderConnection::query()
                    ->forOrganization($organizationId)
                    ->where('provider_key', $providerKey)
                    ->where(
                        'external_account_id',
                        $externalAccountId
                    )
                    ->exists()
            ) {
                throw new DomainException(
                    'La cuenta externa ya está vinculada dentro de la organización.'
                );
            }

            $connection = FinancialProviderConnection::query()
                ->create([
                    'organization_id' => $organizationId,
                    'financial_account_id' =>
                        $lockedAccount->getKey(),
                    'provider_key' => $providerKey,
                    'external_account_id' =>
                        $externalAccountId,
                    'active' => true,
                    'created_by_user_id' =>
                        $actor->getKey(),
                    'updated_by_user_id' =>
                        $actor->getKey(),
                ]);

            $this->audit->record(
                $connection,
                'financial_provider_connection_created',
                null,
                [
                    'financial_account_id' =>
                        $lockedAccount->getKey(),
                    'provider_key' => $providerKey,
                    'external_account_id' =>
                        $externalAccountId,
                    'active' => true,
                ]
            );

            return $connection->refresh()->load('account');
        }, 3);
    }

    public function toggleActive(
        FinancialProviderConnection $connection,
        User $actor
    ): FinancialProviderConnection {
        $organizationId = $this->organizationId($actor);

        return DB::transaction(function () use (
            $organizationId,
            $connection,
            $actor
        ): FinancialProviderConnection {
            $locked = FinancialProviderConnection::query()
                ->forOrganization($organizationId)
                ->whereKey($connection->getKey())
                ->with('account')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La conexión financiera no pertenece a la organización activa.'
                );
            }

            if (
                ! $locked->active
                && ! ($locked->account?->active ?? false)
            ) {
                throw new DomainException(
                    'No puede reactivarse la conexión mientras la cuenta financiera esté inactiva.'
                );
            }

            $old = ['active' => $locked->active];

            $locked->forceFill([
                'active' => ! $locked->active,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->audit->record(
                $locked,
                'financial_provider_connection_toggled',
                $old,
                ['active' => $locked->active]
            );

            return $locked->refresh()->load('account');
        }, 3);
    }

    private function organizationId(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canManageFinancialAccounts() ?? false)) {
            throw new DomainException(
                'No posee permiso para administrar conexiones financieras.'
            );
        }

        return $organizationId;
    }

    private function providerKey(string $value): string
    {
        $value = Str::slug(trim($value));

        if (
            $value === ''
            || mb_strlen($value) > 100
            || preg_match(
                '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                $value
            ) !== 1
        ) {
            throw new DomainException(
                'La clave del proveedor financiero no es válida.'
            );
        }

        return $value;
    }

    private function externalAccountId(?string $value): ?string
    {
        $value = filled($value)
            ? trim((string) $value)
            : null;

        if (
            $value !== null
            && (
                $value === ''
                || mb_strlen($value) > 191
            )
        ) {
            throw new DomainException(
                'El identificador externo de la cuenta no es válido.'
            );
        }

        return $value;
    }
}
