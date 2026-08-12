<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CashSecurityDropReason;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\CommercePaymentMethod;
use App\Enums\FinancialAccountType;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\CommercePayment;
use App\Models\FinancialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CashLedgerRecorder
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    public function recordSalePayment(
        CashRegisterSession $session,
        CommercePayment $payment,
        User $actor
    ): CashMovement {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canOperateCashRegister() ?? false)) {
            throw new DomainException(
                'No posee permiso para registrar movimientos de caja.'
            );
        }

        $session->loadMissing('register.financialAccount');
        $register = $session->register;
        $account = $register?->financialAccount;

        if (
            (int) $session->organization_id !== $organizationId
            || (int) $session->opened_by_user_id !== (int) $actor->id
            || $session->status !== CashRegisterSessionStatus::Open
            || ! $register
            || ! $register->active
            || (int) $register->organization_id !== $organizationId
            || ! $account
            || ! $account->active
            || (int) $account->organization_id !== $organizationId
            || $account->type !== FinancialAccountType::CashBox
            || (int) $account->id !== (int) $payment->financial_account_id
            || $account->currency_code !== $session->currency_code
        ) {
            throw new DomainException(
                'El contexto de caja no es válido para registrar el efectivo.'
            );
        }

        if (
            (int) $payment->organization_id !== $organizationId
            || (int) $payment->received_by_user_id !== (int) $actor->id
            || $payment->method !== CommercePaymentMethod::Cash
            || $payment->amount_minor <= 0
        ) {
            throw new DomainException(
                'El pago no puede incorporarse al libro de efectivo.'
            );
        }

        $idempotencyKey = 'commerce-payment:'.$payment->id;
        $fingerprint = hash('sha256', implode('|', [
            $organizationId,
            $session->id,
            $register->id,
            $account->id,
            $payment->id,
            CashMovementDirection::In->value,
            CashMovementType::SalePayment->value,
            $payment->amount_minor,
            $session->currency_code,
            $actor->id,
        ]));

        $existing = CashMovement::query()
            ->forOrganization($organizationId)
            ->where('commerce_payment_id', $payment->id)
            ->first();

        if ($existing) {
            if (! hash_equals($existing->fingerprint, $fingerprint)) {
                throw new DomainException(
                    'El cobro de efectivo ya fue registrado con otros datos.'
                );
            }

            return $existing;
        }

        $movement = CashMovement::query()->create([
            'organization_id' => $organizationId,
            'cash_register_session_id' => $session->id,
            'cash_register_id' => $register->id,
            'financial_account_id' => $account->id,
            'commerce_payment_id' => $payment->id,
            'direction' => CashMovementDirection::In,
            'type' => CashMovementType::SalePayment,
            'amount_minor' => $payment->amount_minor,
            'currency_code' => $session->currency_code,
            'idempotency_key' => $idempotencyKey,
            'fingerprint' => $fingerprint,
            'recorded_by_user_id' => $actor->id,
            'occurred_at' => $payment->paid_at
                ?? CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
        ]);

        $this->audit->record(
            $movement,
            'cash_movement_recorded',
            null,
            [
                'cash_register_session_id' => $session->id,
                'cash_register_id' => $register->id,
                'financial_account_id' => $account->id,
                'commerce_payment_id' => $payment->id,
                'direction' => CashMovementDirection::In,
                'type' => CashMovementType::SalePayment,
                'amount_minor' => $payment->amount_minor,
                'currency_code' => $session->currency_code,
            ]
        );

        return $movement->refresh();
    }

    public function recordSecurityDrop(
        FinancialAccount $destination,
        int $amountMinor,
        CashSecurityDropReason $reason,
        ?string $note,
        string $idempotencyKey,
        User $actor
    ): CashMovement {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canOperateCashRegister() ?? false)) {
            throw new DomainException(
                'No posee permiso para registrar retiros de seguridad.'
            );
        }

        if ($amountMinor <= 0) {
            throw new DomainException(
                'El retiro de seguridad debe ser mayor que cero.'
            );
        }

        $idempotencyKey = Str::of($idempotencyKey)
            ->squish()
            ->toString();

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 191
        ) {
            throw new DomainException(
                'La clave de idempotencia del retiro no es válida.'
            );
        }

        $note = trim((string) $note);

        if ($note === '') {
            $note = null;
        }

        if ($note !== null && mb_strlen($note) > 1000) {
            throw new DomainException(
                'La nota del retiro es demasiado extensa.'
            );
        }

        return DB::transaction(function () use (
            $organizationId,
            $destination,
            $amountMinor,
            $reason,
            $note,
            $idempotencyKey,
            $actor
        ): CashMovement {
            $session = CashRegisterSession::query()
                ->forOrganization($organizationId)
                ->where('opened_by_user_id', $actor->id)
                ->where('status', CashRegisterSessionStatus::Open)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new DomainException(
                    'Para retirar efectivo necesitás un turno de caja abierto.'
                );
            }

            $session->loadMissing('register.financialAccount');
            $register = $session->register;
            $origin = $register?->financialAccount;

            if (
                ! $register
                || ! $register->active
                || (int) $register->organization_id !== $organizationId
                || ! $origin
                || ! $origin->active
                || (int) $origin->organization_id !== $organizationId
                || $origin->type !== FinancialAccountType::CashBox
                || $origin->currency_code !== $session->currency_code
            ) {
                throw new DomainException(
                    'El contexto de caja no es válido para retirar efectivo.'
                );
            }

            $target = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey($destination->id)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (
                ! $target
                || $target->type !== FinancialAccountType::CashReserve
                || $target->currency_code !== $session->currency_code
                || (int) $target->id === (int) $origin->id
            ) {
                throw new DomainException(
                    'El destino debe ser una reserva de efectivo activa '
                    .'de la misma moneda.'
                );
            }

            $existing = CashMovement::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            $fingerprint = hash(
                'sha256',
                json_encode([
                    'organization_id' => $organizationId,
                    'cash_register_session_id' => $session->id,
                    'cash_register_id' => $register->id,
                    'financial_account_id' => $origin->id,
                    'destination_financial_account_id' => $target->id,
                    'direction' => CashMovementDirection::Out->value,
                    'type' => CashMovementType::SecurityDrop->value,
                    'amount_minor' => $amountMinor,
                    'currency_code' => $session->currency_code,
                    'reason_code' => $reason->value,
                    'note' => $note,
                    'recorded_by_user_id' => $actor->id,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );

            if ($existing) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new DomainException(
                        'La misma clave de retiro fue usada con otros datos.'
                    );
                }

                return $existing;
            }

            $expectedBefore = $this->lockedExpectedAmountMinor(
                $session
            );

            if ($amountMinor > $expectedBefore) {
                throw new DomainException(
                    'El retiro supera el efectivo esperado del turno.'
                );
            }

            $now = CarbonImmutable::now();

            $movement = CashMovement::query()->create([
                'organization_id' => $organizationId,
                'cash_register_session_id' => $session->id,
                'cash_register_id' => $register->id,
                'financial_account_id' => $origin->id,
                'destination_financial_account_id' => $target->id,
                'commerce_payment_id' => null,
                'direction' => CashMovementDirection::Out,
                'type' => CashMovementType::SecurityDrop,
                'reason_code' => $reason,
                'note' => $note,
                'amount_minor' => $amountMinor,
                'currency_code' => $session->currency_code,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'recorded_by_user_id' => $actor->id,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            $this->audit->record(
                $movement,
                'cash_security_drop_recorded',
                null,
                [
                    'cash_register_session_id' => $session->id,
                    'cash_register_id' => $register->id,
                    'financial_account_id' => $origin->id,
                    'destination_financial_account_id' => $target->id,
                    'direction' => CashMovementDirection::Out,
                    'type' => CashMovementType::SecurityDrop,
                    'reason_code' => $reason,
                    'amount_minor' => $amountMinor,
                    'currency_code' => $session->currency_code,
                    'expected_before_minor' => $expectedBefore,
                    'expected_after_minor' =>
                        $expectedBefore - $amountMinor,
                ]
            );

            return $movement->refresh();
        }, 3);
    }

    public function expectedAmountMinor(
        CashRegisterSession $session,
        User $actor
    ): int {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if ((int) $session->organization_id !== $organizationId) {
            throw new DomainException(
                'El turno no pertenece a la organización activa.'
            );
        }

        $isOwn = (int) $session->opened_by_user_id === (int) $actor->id;

        if (
            ! $isOwn
            && ! ($role?->canSuperviseCashRegisters() ?? false)
        ) {
            throw new DomainException(
                'No posee permiso para consultar el efectivo esperado '
                .'de otro turno.'
            );
        }

        if (
            $isOwn
            && ! ($role?->canOperateCashRegister() ?? false)
        ) {
            throw new DomainException(
                'No posee permiso para consultar este turno.'
            );
        }

        return $this->expectedAmountMinorQuery($session);
    }

    private function lockedExpectedAmountMinor(
        CashRegisterSession $session
    ): int {
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

        return $session->opening_amount_minor + $netMinor;
    }

    private function expectedAmountMinorQuery(
        CashRegisterSession $session
    ): int {
        $movements = CashMovement::query()
            ->where(
                'cash_register_session_id',
                $session->id
            )
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

        return $session->opening_amount_minor + $netMinor;
    }
}
