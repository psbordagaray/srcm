<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashCountDifferenceReason;
use App\Enums\CashMovementDirection;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\CashSecurityDropRequestStatus;
use App\Enums\FinancialAccountType;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashRegisterClosure;
use App\Models\CashRegisterSession;
use App\Models\CashSecurityDropRequest;
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

    public function closeCurrent(
        int $countedAmountMinor,
        ?CashCountDifferenceReason $differenceReason,
        ?string $note,
        string $idempotencyKey,
        User $actor
    ): CashRegisterClosure {
        $organizationId = $this->organizationId($actor);
        $idempotencyKey = $this->closeIdempotencyKey(
            $idempotencyKey
        );

        if ($countedAmountMinor < 0) {
            throw new DomainException(
                'El efectivo contado no puede ser negativo.'
            );
        }

        $note = trim((string) $note);

        if ($note === '') {
            $note = null;
        }

        if ($note !== null && mb_strlen($note) > 1000) {
            throw new DomainException(
                'La nota del arqueo es demasiado extensa.'
            );
        }

        return DB::transaction(function () use (
            $organizationId,
            $countedAmountMinor,
            $differenceReason,
            $note,
            $idempotencyKey,
            $actor
        ): CashRegisterClosure {
            $this->lockOrganization($organizationId);

            $existing = CashRegisterClosure::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->with('session.register.financialAccount')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $fingerprint = $this->closeFingerprint(
                    $organizationId,
                    $existing->cash_register_session_id,
                    $existing->cash_register_id,
                    $existing->financial_account_id,
                    $existing->opened_by_user_id,
                    $actor->id,
                    $existing->expected_amount_minor,
                    $countedAmountMinor,
                    $countedAmountMinor -
                        $existing->expected_amount_minor,
                    $existing->currency_code,
                    $differenceReason,
                    $note
                );

                if (
                    (int) $existing->closed_by_user_id !== (int) $actor->id
                    || ! hash_equals(
                        $existing->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La misma clave de cierre fue usada con otros datos.'
                    );
                }

                return $existing;
            }

            $session = CashRegisterSession::query()
                ->forOrganization($organizationId)
                ->where('opened_by_user_id', $actor->id)
                ->where('status', CashRegisterSessionStatus::Open)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new DomainException(
                    'No existe un turno abierto propio para cerrar.'
                );
            }

            $session->loadMissing('register.financialAccount');
            $register = $session->register;
            $account = $register?->financialAccount;

            if (
                ! $register
                || ! $register->active
                || (int) $register->organization_id !== $organizationId
                || ! $account
                || ! $account->active
                || (int) $account->organization_id !== $organizationId
                || $account->type !== FinancialAccountType::CashBox
                || $account->currency_code !== $session->currency_code
            ) {
                throw new DomainException(
                    'El contexto de caja no es válido para el cierre.'
                );
            }

            if (
                CashSecurityDropRequest::query()
                    ->forOrganization($organizationId)
                    ->where('cash_register_session_id', $session->id)
                    ->whereIn('status', [
                        CashSecurityDropRequestStatus::Pending->value,
                        CashSecurityDropRequestStatus::Approved->value,
                    ])
                    ->lockForUpdate()
                    ->exists()
            ) {
                throw new DomainException(
                    'Resolvé o cancelá el retiro de seguridad pendiente '.
                    'antes de cerrar el turno.'
                );
            }

            $session->status =
                CashRegisterSessionStatus::ClosingRequested;
            $session->save();

            $movements = CashMovement::query()
                ->where(
                    'cash_register_session_id',
                    $session->id
                )
                ->lockForUpdate()
                ->get([
                    'direction',
                    'amount_minor',
                ]);

            $netMinor = $movements->sum(
                fn (CashMovement $movement): int =>
                    $movement->direction === CashMovementDirection::In
                        ? $movement->amount_minor
                        : -$movement->amount_minor
            );

            $expectedAmountMinor =
                $session->opening_amount_minor + $netMinor;

            if ($expectedAmountMinor < 0) {
                throw new DomainException(
                    'El efectivo esperado del turno no puede ser negativo.'
                );
            }

            $differenceMinor =
                $countedAmountMinor - $expectedAmountMinor;

            if (
                $differenceMinor !== 0
                && ($differenceReason === null || $note === null)
            ) {
                throw new DomainException(
                    'Una diferencia de arqueo requiere motivo y nota.'
                );
            }

            if ($differenceMinor === 0) {
                $differenceReason = null;
            }

            $fingerprint = $this->closeFingerprint(
                $organizationId,
                $session->id,
                $register->id,
                $account->id,
                $session->opened_by_user_id,
                $actor->id,
                $expectedAmountMinor,
                $countedAmountMinor,
                $differenceMinor,
                $session->currency_code,
                $differenceReason,
                $note
            );

            $now = CarbonImmutable::now();

            $closure = CashRegisterClosure::query()->create([
                'organization_id' => $organizationId,
                'cash_register_session_id' => $session->id,
                'cash_register_id' => $register->id,
                'financial_account_id' => $account->id,
                'opened_by_user_id' => $session->opened_by_user_id,
                'closed_by_user_id' => $actor->id,
                'expected_amount_minor' => $expectedAmountMinor,
                'counted_amount_minor' => $countedAmountMinor,
                'difference_minor' => $differenceMinor,
                'currency_code' => $session->currency_code,
                'difference_reason' => $differenceReason,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'closed_at' => $now,
                'created_at' => $now,
            ]);

            $session->status = CashRegisterSessionStatus::Closed;
            $session->save();

            $this->audit->record(
                $closure,
                'cash_register_session_closed',
                null,
                [
                    'cash_register_session_id' => $session->id,
                    'cash_register_id' => $register->id,
                    'financial_account_id' => $account->id,
                    'opened_by_user_id' => $session->opened_by_user_id,
                    'closed_by_user_id' => $actor->id,
                    'expected_amount_minor' => $expectedAmountMinor,
                    'counted_amount_minor' => $countedAmountMinor,
                    'difference_minor' => $differenceMinor,
                    'currency_code' => $session->currency_code,
                    'difference_reason' => $differenceReason,
                ]
            );

            return $closure->refresh();
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

    private function closeIdempotencyKey(string $value): string
    {
        $value = Str::of($value)->squish()->toString();

        if ($value === '' || mb_strlen($value) > 191) {
            throw new DomainException(
                'La clave de idempotencia de cierre no es válida.'
            );
        }

        return $value;
    }

    private function closeFingerprint(
        int $organizationId,
        int $sessionId,
        int $registerId,
        int $accountId,
        int $openedByUserId,
        int $closedByUserId,
        int $expectedAmountMinor,
        int $countedAmountMinor,
        int $differenceMinor,
        string $currencyCode,
        ?CashCountDifferenceReason $differenceReason,
        ?string $note
    ): string {
        return hash('sha256', json_encode([
            'organization_id' => $organizationId,
            'cash_register_session_id' => $sessionId,
            'cash_register_id' => $registerId,
            'financial_account_id' => $accountId,
            'opened_by_user_id' => $openedByUserId,
            'closed_by_user_id' => $closedByUserId,
            'expected_amount_minor' => $expectedAmountMinor,
            'counted_amount_minor' => $countedAmountMinor,
            'difference_minor' => $differenceMinor,
            'currency_code' => $currencyCode,
            'difference_reason' => $differenceReason?->value,
            'note' => $note,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
