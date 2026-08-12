<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\FinancialAccountType;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CashRegisterSessionManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function open(
        CashRegister $register,
        int $openingAmountMinor,
        string $idempotencyKey,
        User $actor
    ): CashRegisterSession {
        $organizationId = $this->organizationId($actor);
        $idempotencyKey = $this->idempotencyKey($idempotencyKey);

        if ($openingAmountMinor < 0) {
            throw new DomainException(
                'El fondo inicial de caja no puede ser negativo.'
            );
        }

        return DB::transaction(function () use (
            $organizationId,
            $register,
            $openingAmountMinor,
            $idempotencyKey,
            $actor
        ): CashRegisterSession {
            $this->lockOrganization($organizationId);

            $lockedRegister = CashRegister::query()
                ->forOrganization($organizationId)
                ->whereKey($register->id)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $lockedRegister) {
                throw new DomainException(
                    'La caja operativa no está disponible en la organización activa.'
                );
            }

            $account = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey($lockedRegister->financial_account_id)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (
                ! $account
                || $account->type !== FinancialAccountType::CashBox
            ) {
                throw new DomainException(
                    'La caja operativa no posee una cuenta de efectivo activa válida.'
                );
            }

            $fingerprint = $this->fingerprint(
                $organizationId,
                $lockedRegister->id,
                $account->id,
                $actor->id,
                $account->currency_code,
                $openingAmountMinor
            );

            $existing = CashRegisterSession::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->fingerprint !== $fingerprint) {
                    throw new DomainException(
                        'La misma apertura de caja fue reintentada con datos diferentes.'
                    );
                }

                return $existing;
            }

            if (
                CashRegisterSession::query()
                    ->forOrganization($organizationId)
                    ->where('cash_register_id', $lockedRegister->id)
                    ->where('status', CashRegisterSessionStatus::Open)
                    ->exists()
            ) {
                throw new DomainException(
                    'La caja operativa ya posee un turno abierto.'
                );
            }

            if (
                CashRegisterSession::query()
                    ->forOrganization($organizationId)
                    ->where('opened_by_user_id', $actor->id)
                    ->where('status', CashRegisterSessionStatus::Open)
                    ->exists()
            ) {
                throw new DomainException(
                    'El usuario ya posee otro turno de caja abierto.'
                );
            }

            $now = CarbonImmutable::now();

            $session = CashRegisterSession::query()->create([
                'organization_id' => $organizationId,
                'cash_register_id' => $lockedRegister->id,
                'opened_by_user_id' => $actor->id,
                'status' => CashRegisterSessionStatus::Open,
                'currency_code' => $account->currency_code,
                'opening_amount_minor' => $openingAmountMinor,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'opened_at' => $now,
                'created_at' => $now,
            ]);

            $this->audit->record(
                $session,
                'cash_register_session_opened',
                null,
                [
                    'cash_register_id' => $lockedRegister->id,
                    'financial_account_id' => $account->id,
                    'currency_code' => $account->currency_code,
                    'opening_amount_minor' => $openingAmountMinor,
                    'opened_by_user_id' => $actor->id,
                    'status' => CashRegisterSessionStatus::Open,
                ]
            );

            return $session->refresh();
        }, 3);
    }

    public function currentFor(User $actor): ?CashRegisterSession
    {
        $organizationId = $this->currentOrganization->id($actor);

        return CashRegisterSession::query()
            ->forOrganization($organizationId)
            ->where('opened_by_user_id', $actor->id)
            ->where('status', CashRegisterSessionStatus::Open)
            ->with('register.financialAccount')
            ->first();
    }

    public function lockCurrentFor(User $actor): ?CashRegisterSession
    {
        $organizationId = $this->organizationId($actor);

        $session = CashRegisterSession::query()
            ->forOrganization($organizationId)
            ->where('opened_by_user_id', $actor->id)
            ->where('status', CashRegisterSessionStatus::Open)
            ->lockForUpdate()
            ->first();

        return $session?->load('register.financialAccount');
    }

    private function organizationId(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canOperateCashRegister() ?? false)) {
            throw new DomainException(
                'No posee permiso para operar una caja.'
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

    private function idempotencyKey(string $value): string
    {
        $value = Str::of($value)->squish()->toString();

        if ($value === '' || mb_strlen($value) > 191) {
            throw new DomainException(
                'La clave de idempotencia de apertura no es válida.'
            );
        }

        return $value;
    }

    private function fingerprint(
        int $organizationId,
        int $registerId,
        int $accountId,
        int $actorId,
        string $currencyCode,
        int $openingAmountMinor
    ): string {
        return hash('sha256', json_encode([
            'organization_id' => $organizationId,
            'cash_register_id' => $registerId,
            'financial_account_id' => $accountId,
            'opened_by_user_id' => $actorId,
            'currency_code' => $currencyCode,
            'opening_amount_minor' => $openingAmountMinor,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
