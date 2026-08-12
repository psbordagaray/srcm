<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\FinancialAccountType;
use App\Models\CashRegister;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CashRegisterManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function create(
        string $name,
        FinancialAccount $financialAccount,
        User $actor
    ): CashRegister {
        $organizationId = $this->organizationId($actor);
        $name = $this->name($name);
        $normalizedName = $this->normalizeName($name);

        return DB::transaction(function () use (
            $organizationId,
            $name,
            $normalizedName,
            $financialAccount,
            $actor
        ): CashRegister {
            $this->lockOrganization($organizationId);

            $account = $this->lockCashAccount(
                $financialAccount,
                $organizationId
            );

            if (
                CashRegister::query()
                    ->forOrganization($organizationId)
                    ->where('normalized_name', $normalizedName)
                    ->exists()
            ) {
                throw new DomainException(
                    'Ya existe una caja operativa con ese nombre.'
                );
            }

            if (
                CashRegister::query()
                    ->where('financial_account_id', $account->id)
                    ->exists()
            ) {
                throw new DomainException(
                    'La cuenta financiera ya está vinculada a otra caja operativa.'
                );
            }

            $register = CashRegister::query()->create([
                'organization_id' => $organizationId,
                'financial_account_id' => $account->id,
                'name' => $name,
                'normalized_name' => $normalizedName,
                'active' => true,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                $register,
                'cash_register_created',
                null,
                [
                    'name' => $register->name,
                    'financial_account_id' => $account->id,
                    'currency_code' => $account->currency_code,
                    'active' => true,
                ]
            );

            return $register->refresh();
        }, 3);
    }

    public function update(
        CashRegister $register,
        string $name,
        FinancialAccount $financialAccount,
        User $actor
    ): CashRegister {
        $organizationId = $this->organizationId($actor);
        $name = $this->name($name);
        $normalizedName = $this->normalizeName($name);

        return DB::transaction(function () use (
            $organizationId,
            $register,
            $name,
            $normalizedName,
            $financialAccount,
            $actor
        ): CashRegister {
            $this->lockOrganization($organizationId);

            $locked = CashRegister::query()
                ->forOrganization($organizationId)
                ->whereKey($register->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La caja operativa no pertenece a la organización activa.'
                );
            }

            $account = $this->lockCashAccount(
                $financialAccount,
                $organizationId
            );

            if (
                CashRegister::query()
                    ->forOrganization($organizationId)
                    ->where('normalized_name', $normalizedName)
                    ->where('id', '<>', $locked->id)
                    ->exists()
            ) {
                throw new DomainException(
                    'Ya existe una caja operativa con ese nombre.'
                );
            }

            if (
                CashRegister::query()
                    ->where('financial_account_id', $account->id)
                    ->where('id', '<>', $locked->id)
                    ->exists()
            ) {
                throw new DomainException(
                    'La cuenta financiera ya está vinculada a otra caja operativa.'
                );
            }

            if (
                (int) $locked->financial_account_id !== (int) $account->id
                && $locked->sessions()->exists()
            ) {
                throw new DomainException(
                    'La cuenta financiera no puede cambiar después del primer turno de caja.'
                );
            }

            $old = [
                'name' => $locked->name,
                'financial_account_id' => $locked->financial_account_id,
                'active' => $locked->active,
            ];

            $locked->forceFill([
                'name' => $name,
                'normalized_name' => $normalizedName,
                'financial_account_id' => $account->id,
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(
                $locked,
                'cash_register_updated',
                $old,
                [
                    'name' => $locked->name,
                    'financial_account_id' => $locked->financial_account_id,
                    'active' => $locked->active,
                ]
            );

            return $locked->refresh();
        }, 3);
    }

    public function toggleActive(
        CashRegister $register,
        User $actor
    ): CashRegister {
        $organizationId = $this->organizationId($actor);

        return DB::transaction(function () use (
            $organizationId,
            $register,
            $actor
        ): CashRegister {
            $this->lockOrganization($organizationId);

            $locked = CashRegister::query()
                ->forOrganization($organizationId)
                ->whereKey($register->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La caja operativa no pertenece a la organización activa.'
                );
            }

            if (
                $locked->active
                && $locked->sessions()
                    ->where('status', CashRegisterSessionStatus::Open)
                    ->exists()
            ) {
                throw new DomainException(
                    'No puede inactivarse una caja con un turno abierto.'
                );
            }

            $old = ['active' => $locked->active];

            $locked->forceFill([
                'active' => ! $locked->active,
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(
                $locked,
                'cash_register_toggled',
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

        if (! ($role?->canManageCashRegisters() ?? false)) {
            throw new DomainException(
                'No posee permiso para administrar cajas operativas.'
            );
        }

        return $organizationId;
    }

    private function lockOrganization(int $organizationId): void
    {
        $exists = Organization::query()
            ->whereKey($organizationId)
            ->where('active', true)
            ->lockForUpdate()
            ->exists();

        if (! $exists) {
            throw new DomainException('La organización no está activa.');
        }
    }

    private function lockCashAccount(
        FinancialAccount $account,
        int $organizationId
    ): FinancialAccount {
        $locked = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->whereKey($account->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $locked) {
            throw new DomainException(
                'La cuenta financiera no está disponible en la organización activa.'
            );
        }

        if ($locked->type !== FinancialAccountType::CashBox) {
            throw new DomainException(
                'Una caja operativa requiere una cuenta financiera de tipo Caja de efectivo.'
            );
        }

        return $locked;
    }

    private function name(string $value): string
    {
        $value = Str::of($value)->squish()->toString();

        if ($value === '' || mb_strlen($value) > 120) {
            throw new DomainException(
                'El nombre de la caja operativa no es válido.'
            );
        }

        return $value;
    }

    private function normalizeName(string $value): string
    {
        $ascii = Str::lower(Str::ascii($value));

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }
}
