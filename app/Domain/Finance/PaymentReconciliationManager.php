<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementStatus;
use App\Enums\PaymentReconciliationStatus;
use App\Models\CommercePayment;
use App\Models\FinancialExternalMovement;
use App\Models\PaymentReconciliation;
use App\Models\PaymentReconciliationAllocation;
use App\Models\PaymentReconciliationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PaymentReconciliationManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit
    ) {
    }

    /**
     * @param list<array{
     *     movement: FinancialExternalMovement,
     *     gross_amount_minor: int
     * }> $allocations
     */
    public function reconcile(
        CommercePayment $payment,
        array $allocations,
        string $idempotencyKey,
        User $actor,
        ?string $note = null
    ): PaymentReconciliationEvent {
        $organizationId = $this->organizationId($actor);
        $idempotencyKey = trim($idempotencyKey);
        $note = filled($note) ? trim((string) $note) : null;

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 191
        ) {
            throw new DomainException(
                'La clave de idempotencia de conciliación no es válida.'
            );
        }

        if ($note !== null && mb_strlen($note) > 2000) {
            throw new DomainException(
                'La nota de conciliación supera la longitud admitida.'
            );
        }

        if ($allocations === []) {
            throw new DomainException(
                'La conciliación requiere al menos un movimiento externo.'
            );
        }

        return DB::transaction(function () use (
            $organizationId,
            $payment,
            $allocations,
            $idempotencyKey,
            $actor,
            $note
        ): PaymentReconciliationEvent {
            $lockedPayment = CommercePayment::query()
                ->forOrganization($organizationId)
                ->whereKey($payment->getKey())
                ->with('sale')
                ->lockForUpdate()
                ->first();

            if (! $lockedPayment) {
                throw new DomainException(
                    'El cobro declarado no pertenece a la organización activa.'
                );
            }

            $existingEvent = PaymentReconciliationEvent::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->with(['reconciliation', 'allocations'])
                ->first();

            if ($existingEvent) {
                if (
                    (int) $existingEvent->reconciliation?->commerce_payment_id
                        !== (int) $lockedPayment->getKey()
                ) {
                    throw new DomainException(
                        'La clave de idempotencia ya pertenece a otro cobro.'
                    );
                }

                return $existingEvent;
            }

            $case = PaymentReconciliation::query()
                ->forOrganization($organizationId)
                ->where('commerce_payment_id', $lockedPayment->getKey())
                ->lockForUpdate()
                ->first();

            $case ??= PaymentReconciliation::query()->create([
                'organization_id' => $organizationId,
                'commerce_payment_id' => $lockedPayment->getKey(),
                'expected_amount_minor' =>
                    (int) $lockedPayment->amount_minor,
                'opened_by_user_id' => $actor->getKey(),
                'opened_at' => CarbonImmutable::now(),
                'created_at' => CarbonImmutable::now(),
            ]);

            $allocatedGross = 0;
            $normalized = [];

            foreach ($allocations as $allocation) {
                $movement = $allocation['movement'] ?? null;
                $amount = $allocation['gross_amount_minor'] ?? null;

                if (
                    ! $movement instanceof FinancialExternalMovement
                    || ! is_int($amount)
                    || $amount <= 0
                ) {
                    throw new DomainException(
                        'La asignación de conciliación no es válida.'
                    );
                }

                $lockedMovement = FinancialExternalMovement::query()
                    ->forOrganization($organizationId)
                    ->whereKey($movement->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedMovement) {
                    throw new DomainException(
                        'Un movimiento externo no pertenece a la organización activa.'
                    );
                }

                if (
                    $lockedMovement->direction
                        !== FinancialMovementDirection::Credit
                    || $lockedMovement->status
                        !== FinancialMovementStatus::Posted
                ) {
                    throw new DomainException(
                        'Sólo un ingreso externo contabilizado puede conciliar un cobro.'
                    );
                }

                if (
                    $lockedPayment->sale?->currency_code
                        !== $lockedMovement->currency_code
                ) {
                    throw new DomainException(
                        'La moneda del cobro y del movimiento externo no coincide.'
                    );
                }

                if ($amount > $lockedMovement->gross_amount_minor) {
                    throw new DomainException(
                        'La asignación supera el importe bruto del movimiento externo.'
                    );
                }

                if (
                    PaymentReconciliationAllocation::query()
                        ->forOrganization($organizationId)
                        ->where(
                            'financial_external_movement_id',
                            $lockedMovement->getKey()
                        )
                        ->whereHas(
                            'event',
                            fn ($query) => $query->where(
                                'payment_reconciliation_id',
                                '<>',
                                $case->getKey()
                            )
                        )
                        ->exists()
                ) {
                    throw new DomainException(
                        'El movimiento externo ya fue utilizado por otro cobro.'
                    );
                }

                if ($allocatedGross > PHP_INT_MAX - $amount) {
                    throw new DomainException(
                        'La conciliación supera el importe admitido.'
                    );
                }

                $allocatedGross += $amount;
                $normalized[] = [
                    'movement' => $lockedMovement,
                    'amount' => $amount,
                ];
            }

            $expected = (int) $lockedPayment->amount_minor;
            $difference = $allocatedGross - $expected;
            $status = $difference === 0
                ? PaymentReconciliationStatus::Matched
                : PaymentReconciliationStatus::Difference;

            $event = PaymentReconciliationEvent::query()->create([
                'organization_id' => $organizationId,
                'payment_reconciliation_id' => $case->getKey(),
                'idempotency_key' => $idempotencyKey,
                'status' => $status,
                'allocated_gross_amount_minor' => $allocatedGross,
                'difference_minor' => $difference,
                'note' => $note,
                'created_by_user_id' => $actor->getKey(),
                'occurred_at' => CarbonImmutable::now(),
                'created_at' => CarbonImmutable::now(),
            ]);

            foreach ($normalized as $allocation) {
                PaymentReconciliationAllocation::query()->create([
                    'organization_id' => $organizationId,
                    'payment_reconciliation_event_id' =>
                        $event->getKey(),
                    'financial_external_movement_id' =>
                        $allocation['movement']->getKey(),
                    'gross_amount_minor' => $allocation['amount'],
                    'created_at' => CarbonImmutable::now(),
                ]);
            }

            $this->audit->record(
                $event,
                'commerce_payment_reconciled',
                null,
                [
                    'commerce_payment_id' => $lockedPayment->getKey(),
                    'expected_amount_minor' => $expected,
                    'allocated_gross_amount_minor' => $allocatedGross,
                    'difference_minor' => $difference,
                    'status' => $status,
                    'allocation_count' => count($normalized),
                ]
            );

            return $event->refresh()->load([
                'reconciliation.payment',
                'allocations.movement.account',
            ]);
        }, 3);
    }

    private function organizationId(User $actor): int
    {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (! ($role?->canReviewFinancialReconciliation() ?? false)) {
            throw new DomainException(
                'No posee permiso para conciliar cobros.'
            );
        }

        return $organizationId;
    }
}
