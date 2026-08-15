<?php

namespace App\Domain\Finance;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementStatus;
use App\Models\CommercePayment;
use App\Models\FinancialExternalMovement;
use App\Models\PaymentReconciliationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;

final class FinancialReconciliationDecisionManager
{
    private const WINDOW_DAYS = 7;

    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly PaymentReconciliationManager $reconciliations
    ) {
    }

    public function reconcileCandidate(
        CommercePayment $payment,
        FinancialExternalMovement $movement,
        User $actor,
        ?string $note = null
    ): PaymentReconciliationEvent {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canReviewFinancialReconciliation() ?? false)) {
            throw new DomainException(
                'No posee permiso para conciliar cobros.'
            );
        }

        if (
            (int) $payment->organization_id !== $organizationId
            || (int) $movement->organization_id !== $organizationId
        ) {
            throw new DomainException(
                'El cobro o movimiento no pertenece a la organización activa.'
            );
        }

        $payment->loadMissing('sale');

        if (! $payment->financial_account_id) {
            throw new DomainException(
                'El cobro no posee una cuenta financiera conciliable.'
            );
        }

        if (
            (int) $movement->financial_account_id
                !== (int) $payment->financial_account_id
        ) {
            throw new DomainException(
                'El movimiento externo pertenece a otra cuenta financiera.'
            );
        }

        if (
            $movement->direction
                !== FinancialMovementDirection::Credit
            || $movement->status
                !== FinancialMovementStatus::Posted
        ) {
            throw new DomainException(
                'Sólo un ingreso externo contabilizado puede seleccionarse.'
            );
        }

        if (
            $payment->sale?->currency_code
                !== $movement->currency_code
        ) {
            throw new DomainException(
                'La moneda del cobro y del movimiento externo no coincide.'
            );
        }

        $paidAt = CarbonImmutable::instance(
            $payment->paid_at ?? $payment->created_at
        )->utc();

        $occurredAt = CarbonImmutable::instance(
            $movement->occurred_at
        )->utc();

        if (
            $occurredAt->lt($paidAt->subDays(self::WINDOW_DAYS))
            || $occurredAt->gt($paidAt->addDays(self::WINDOW_DAYS))
        ) {
            throw new DomainException(
                'El movimiento externo quedó fuera de la ventana segura de conciliación.'
            );
        }

        $note = filled($note) ? trim((string) $note) : null;

        $difference =
            (int) $movement->gross_amount_minor
            - (int) $payment->amount_minor;

        if (
            $difference !== 0
            && (
                $note === null
                || mb_strlen($note) < 10
            )
        ) {
            throw new DomainException(
                'Una conciliación con diferencia requiere una nota de al menos 10 caracteres.'
            );
        }

        if ($note !== null && mb_strlen($note) > 2000) {
            throw new DomainException(
                'La nota de conciliación supera la longitud admitida.'
            );
        }

        $idempotencyKey = implode(':', [
            'p6',
            'manual',
            (string) $organizationId,
            (string) $payment->getKey(),
            (string) $movement->getKey(),
        ]);

        return $this->reconciliations->reconcile(
            $payment,
            [[
                'movement' => $movement,
                'gross_amount_minor' =>
                    (int) $movement->gross_amount_minor,
            ]],
            $idempotencyKey,
            $actor,
            $note
        );
    }
}
