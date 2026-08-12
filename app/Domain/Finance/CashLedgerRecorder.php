<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\CommercePaymentMethod;
use App\Enums\FinancialAccountType;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\CommercePayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;

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
}
