<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinancialAccountManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function create(
        string $name,
        FinancialAccountType $type,
        string $currencyCode,
        User $actor,
        ?string $provider = null,
        ?string $externalLabel = null
    ): FinancialAccount {
        $organizationId = $this->organizationId($actor);
        $name = $this->name($name);
        $normalizedName = $this->normalizeName($name);
        $currency = $this->currency($currencyCode);
        $provider = $this->optional($provider, 100, 'El proveedor');
        $externalLabel = $this->optional(
            $externalLabel,
            191,
            'La referencia externa'
        );

        return DB::transaction(function () use (
            $organizationId,
            $name,
            $normalizedName,
            $type,
            $currency,
            $provider,
            $externalLabel,
            $actor
        ): FinancialAccount {
            Organization::query()
                ->whereKey($organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                FinancialAccount::query()
                    ->forOrganization($organizationId)
                    ->where('normalized_name', $normalizedName)
                    ->exists()
            ) {
                throw new DomainException(
                    'Ya existe una cuenta financiera con ese nombre.'
                );
            }

            $account = FinancialAccount::query()->create([
                'organization_id' => $organizationId,
                'name' => $name,
                'normalized_name' => $normalizedName,
                'type' => $type,
                'provider' => $provider,
                'currency_code' => $currency,
                'external_label' => $externalLabel,
                'active' => true,
                'created_by_user_id' => $actor->getKey(),
                'updated_by_user_id' => $actor->getKey(),
            ]);

            $this->audit->record(
                $account,
                'financial_account_created',
                null,
                [
                    'name' => $account->name,
                    'type' => $account->type,
                    'provider' => $account->provider,
                    'currency_code' => $account->currency_code,
                    'external_label' => $account->external_label,
                    'active' => true,
                ]
            );

            return $account->refresh();
        }, 3);
    }

    public function update(
        FinancialAccount $account,
        string $name,
        FinancialAccountType $type,
        string $currencyCode,
        User $actor,
        ?string $provider = null,
        ?string $externalLabel = null
    ): FinancialAccount {
        $organizationId = $this->organizationId($actor);
        $name = $this->name($name);
        $normalizedName = $this->normalizeName($name);
        $currency = $this->currency($currencyCode);
        $provider = $this->optional($provider, 100, 'El proveedor');
        $externalLabel = $this->optional(
            $externalLabel,
            191,
            'La referencia externa'
        );

        return DB::transaction(function () use (
            $organizationId,
            $account,
            $name,
            $normalizedName,
            $type,
            $currency,
            $provider,
            $externalLabel,
            $actor
        ): FinancialAccount {
            $locked = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La cuenta financiera no pertenece a la organización activa.'
                );
            }

            if (
                FinancialAccount::query()
                    ->forOrganization($organizationId)
                    ->where('normalized_name', $normalizedName)
                    ->where('id', '<>', $locked->getKey())
                    ->exists()
            ) {
                throw new DomainException(
                    'Ya existe una cuenta financiera con ese nombre.'
                );
            }

            if (
                $locked->externalMovements()->exists()
                && $locked->currency_code !== $currency
            ) {
                throw new DomainException(
                    'La moneda no puede cambiar después del primer movimiento externo.'
                );
            }

            $old = [
                'name' => $locked->name,
                'type' => $locked->type,
                'provider' => $locked->provider,
                'currency_code' => $locked->currency_code,
                'external_label' => $locked->external_label,
                'active' => $locked->active,
            ];

            $locked->forceFill([
                'name' => $name,
                'normalized_name' => $normalizedName,
                'type' => $type,
                'provider' => $provider,
                'currency_code' => $currency,
                'external_label' => $externalLabel,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->audit->record(
                $locked,
                'financial_account_updated',
                $old,
                [
                    'name' => $locked->name,
                    'type' => $locked->type,
                    'provider' => $locked->provider,
                    'currency_code' => $locked->currency_code,
                    'external_label' => $locked->external_label,
                    'active' => $locked->active,
                ]
            );

            return $locked->refresh();
        }, 3);
    }

    public function toggleActive(
        FinancialAccount $account,
        User $actor
    ): FinancialAccount {
        $organizationId = $this->organizationId($actor);

        return DB::transaction(function () use (
            $organizationId,
            $account,
            $actor
        ): FinancialAccount {
            $locked = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La cuenta financiera no pertenece a la organización activa.'
                );
            }

            $old = ['active' => $locked->active];

            $locked->forceFill([
                'active' => ! $locked->active,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->audit->record(
                $locked,
                'financial_account_toggled',
                $old,
                ['active' => $locked->active]
            );

            return $locked->refresh();
        }, 3);
    }

    private function organizationId(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canManageFinancialAccounts() ?? false)) {
            throw new DomainException(
                'No posee permiso para administrar cuentas financieras.'
            );
        }

        return $organizationId;
    }

    private function name(string $value): string
    {
        $value = Str::of($value)->squish()->toString();

        if ($value === '' || mb_strlen($value) > 120) {
            throw new DomainException(
                'El nombre de la cuenta financiera no es válido.'
            );
        }

        return $value;
    }

    private function normalizeName(string $value): string
    {
        $ascii = Str::lower(Str::ascii($value));

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }

    private function currency(string $value): string
    {
        $value = strtoupper(trim($value));

        if (preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new DomainException(
                'La moneda debe expresarse con un código ISO de tres letras.'
            );
        }

        return $value;
    }

    private function optional(
        ?string $value,
        int $maxLength,
        string $label
    ): ?string {
        $value = filled($value)
            ? Str::of((string) $value)->squish()->toString()
            : null;

        if ($value !== null && mb_strlen($value) > $maxLength) {
            throw new DomainException(
                "{$label} supera la longitud admitida."
            );
        }

        return $value;
    }
}
