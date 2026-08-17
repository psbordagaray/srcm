<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\FinancialAccountType;
use App\Enums\PurchasePaymentRequestStatus;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\FinancialAccount;
use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentExecution;
use App\Models\PurchasePaymentRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PurchasePaymentExecutionManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly AuditRecorder $audit,
        private readonly PurchaseObligationBalanceReader $balances
    ) {
    }

    public function executeCash(
        PurchasePaymentRequest $request,
        ?string $executionReference,
        ?string $executionNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentExecution {
        $organizationId = $this->actors->authorize(
            $actor,
            'execute-payment'
        );
        $executionReference = PurchasePayload::optionalText(
            $executionReference,
            'La referencia de ejecución',
            180
        );
        $executionNote = PurchasePayload::optionalText(
            $executionNote,
            'La nota de ejecución',
            1000
        );
        $idempotencyKey = $this->idempotencyKey(
            $idempotencyKey
        );

        return DB::transaction(function () use (
            $request,
            $executionReference,
            $executionNote,
            $idempotencyKey,
            $actor,
            $organizationId
        ): PurchasePaymentExecution {
            $obligation = PurchaseObligation::query()
                ->forOrganization($organizationId)
                ->whereKey($request->purchase_obligation_id)
                ->lockForUpdate()
                ->first();

            if (! $obligation) {
                throw new DomainException(
                    'La obligación autorizada no pertenece a la organización activa.'
                );
            }

            $locked = PurchasePaymentRequest::query()
                ->forOrganization($organizationId)
                ->whereKey($request->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La solicitud autorizada no pertenece a la organización activa.'
                );
            }

            $existingByKey = PurchasePaymentExecution::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingByKey) {
                return $this->existingExecution(
                    $existingByKey,
                    $locked,
                    $executionReference,
                    $executionNote,
                    $actor
                );
            }

            if (
                $locked->status
                    === PurchasePaymentRequestStatus::Executed
            ) {
                $existing = PurchasePaymentExecution::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'purchase_payment_request_id',
                        $locked->id
                    )
                    ->lockForUpdate()
                    ->first();

                if (! $existing) {
                    throw new DomainException(
                        'La solicitud figura ejecutada sin su hecho de desembolso.'
                    );
                }

                throw new DomainException(
                    'La autorización ya fue consumida por otra ejecución.'
                );
            }

            if (
                $locked->status
                    !== PurchasePaymentRequestStatus::Approved
            ) {
                throw new DomainException(
                    'El pago debe estar autorizado antes de ejecutarse.'
                );
            }

            if (
                $locked->approved_by_user_id === null
                || (int) $locked->approved_by_user_id
                    === (int) $actor->id
            ) {
                throw new DomainException(
                    'Quien autorizó el pago no puede ejecutar este desembolso en efectivo.'
                );
            }

            $expectedApprovalFingerprint =
                PurchasePayload::fingerprint([
                    'request_fingerprint' =>
                        $locked->fingerprint,
                    'approved_by_user_id' =>
                        (int) $locked->approved_by_user_id,
                    'approval_note' =>
                        $locked->approval_note,
                ]);

            if (
                blank($locked->approval_fingerprint)
                || ! hash_equals(
                    (string) $locked->approval_fingerprint,
                    $expectedApprovalFingerprint
                )
            ) {
                throw new DomainException(
                    'La autorización de pago ya no posee una huella válida.'
                );
            }

            if (
                (int) $locked->purchase_obligation_id
                    !== (int) $obligation->id
                || (int) $locked->beneficiary_business_party_id
                    !== (int) $obligation->beneficiary_business_party_id
                || $locked->currency_code
                    !== $obligation->currency_code
            ) {
                throw new DomainException(
                    'La autorización ya no coincide con la obligación económica.'
                );
            }

            $remainingMinor = $this->balances
                ->locked($obligation)
                ['remaining_minor'];

            if (
                $locked->amount_minor <= 0
                || $locked->amount_minor > $remainingMinor
            ) {
                throw new DomainException(
                    'El importe autorizado supera el saldo económico todavía ejecutable.'
                );
            }

            $origin = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey($locked->origin_financial_account_id)
                ->where('active', true)
                ->where(
                    'currency_code',
                    $locked->currency_code
                )
                ->lockForUpdate()
                ->first();

            if (
                ! $origin
                || $origin->type
                    !== FinancialAccountType::CashBox
            ) {
                throw new DomainException(
                    'P4F.3 Foundation sólo ejecuta efectivo desde una caja activa.'
                );
            }

            $session = CashRegisterSession::query()
                ->forOrganization($organizationId)
                ->where('opened_by_user_id', $actor->id)
                ->where(
                    'status',
                    CashRegisterSessionStatus::Open
                )
                ->where(
                    'currency_code',
                    $locked->currency_code
                )
                ->whereHas(
                    'register',
                    fn ($query) => $query
                        ->where('active', true)
                        ->where(
                            'financial_account_id',
                            $origin->id
                        )
                )
                ->with('register')
                ->lockForUpdate()
                ->first();

            if (! $session || ! $session->register) {
                throw new DomainException(
                    'Para ejecutar este pago necesitás un turno abierto propio sobre la caja autorizada.'
                );
            }

            $expectedBefore = $this->lockedExpectedAmountMinor(
                $session
            );

            if ($locked->amount_minor > $expectedBefore) {
                throw new DomainException(
                    'El pago autorizado supera el efectivo esperado actual del turno.'
                );
            }

            $now = CarbonImmutable::now('UTC');
            $executionFingerprint =
                PurchasePayload::fingerprint([
                    'organization_id' => $organizationId,
                    'purchase_payment_request_id' =>
                        (int) $locked->id,
                    'request_fingerprint' =>
                        $locked->fingerprint,
                    'approval_fingerprint' =>
                        $locked->approval_fingerprint,
                    'purchase_obligation_id' =>
                        (int) $obligation->id,
                    'beneficiary_business_party_id' =>
                        (int) $locked
                            ->beneficiary_business_party_id,
                    'origin_financial_account_id' =>
                        (int) $origin->id,
                    'cash_register_session_id' =>
                        (int) $session->id,
                    'cash_register_id' =>
                        (int) $session->cash_register_id,
                    'executed_by_user_id' =>
                        (int) $actor->id,
                    'amount_minor' =>
                        (int) $locked->amount_minor,
                    'currency_code' =>
                        $locked->currency_code,
                    'execution_reference' =>
                        $executionReference,
                    'execution_note' =>
                        $executionNote,
                ]);

            $execution = PurchasePaymentExecution::query()
                ->create([
                    'organization_id' => $organizationId,
                    'purchase_payment_request_id' =>
                        $locked->id,
                    'purchase_obligation_id' =>
                        $obligation->id,
                    'origin_financial_account_id' =>
                        $origin->id,
                    'beneficiary_business_party_id' =>
                        $locked->beneficiary_business_party_id,
                    'cash_register_session_id' =>
                        $session->id,
                    'cash_register_id' =>
                        $session->cash_register_id,
                    'executed_by_user_id' =>
                        $actor->id,
                    'amount_minor' =>
                        $locked->amount_minor,
                    'currency_code' =>
                        $locked->currency_code,
                    'execution_reference' =>
                        $executionReference,
                    'execution_note' =>
                        $executionNote,
                    'idempotency_key' =>
                        $idempotencyKey,
                    'fingerprint' =>
                        $executionFingerprint,
                    'executed_at' => $now,
                    'created_at' => $now,
                ]);

            $movementFingerprint =
                PurchasePayload::fingerprint([
                    'organization_id' => $organizationId,
                    'purchase_payment_execution_id' =>
                        (int) $execution->id,
                    'cash_register_session_id' =>
                        (int) $session->id,
                    'cash_register_id' =>
                        (int) $session->cash_register_id,
                    'financial_account_id' =>
                        (int) $origin->id,
                    'direction' =>
                        CashMovementDirection::Out->value,
                    'type' =>
                        CashMovementType::PurchasePayment
                            ->value,
                    'amount_minor' =>
                        (int) $locked->amount_minor,
                    'currency_code' =>
                        $locked->currency_code,
                    'recorded_by_user_id' =>
                        (int) $actor->id,
                ]);

            $movement = CashMovement::query()->create([
                'organization_id' => $organizationId,
                'cash_register_session_id' =>
                    $session->id,
                'cash_register_id' =>
                    $session->cash_register_id,
                'financial_account_id' =>
                    $origin->id,
                'destination_financial_account_id' => null,
                'cash_security_drop_request_id' => null,
                'purchase_payment_execution_id' =>
                    $execution->id,
                'commerce_payment_id' => null,
                'direction' =>
                    CashMovementDirection::Out,
                'type' =>
                    CashMovementType::PurchasePayment,
                'reason_code' => null,
                'note' => null,
                'amount_minor' =>
                    $locked->amount_minor,
                'currency_code' =>
                    $locked->currency_code,
                'idempotency_key' =>
                    'purchase-payment-execution:'
                    .$execution->id,
                'fingerprint' =>
                    $movementFingerprint,
                'recorded_by_user_id' =>
                    $actor->id,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            $locked->status =
                PurchasePaymentRequestStatus::Executed;
            $locked->save();

            $this->audit->record(
                $movement,
                'purchase_payment_cash_recorded',
                null,
                [
                    'purchase_payment_execution_id' =>
                        $execution->id,
                    'purchase_payment_request_id' =>
                        $locked->id,
                    'purchase_obligation_id' =>
                        $obligation->id,
                    'beneficiary_business_party_id' =>
                        $locked
                            ->beneficiary_business_party_id,
                    'cash_register_session_id' =>
                        $session->id,
                    'cash_register_id' =>
                        $session->cash_register_id,
                    'financial_account_id' =>
                        $origin->id,
                    'approved_by_user_id' =>
                        $locked->approved_by_user_id,
                    'executed_by_user_id' =>
                        $actor->id,
                    'amount_minor' =>
                        $locked->amount_minor,
                    'currency_code' =>
                        $locked->currency_code,
                    'expected_before_minor' =>
                        $expectedBefore,
                    'expected_after_minor' =>
                        $expectedBefore
                        - $locked->amount_minor,
                ]
            );

            $this->audit->record(
                $execution,
                'purchase_payment_executed',
                null,
                [
                    'purchase_payment_request_id' =>
                        $locked->id,
                    'cash_movement_id' =>
                        $movement->id,
                    'requested_by_user_id' =>
                        $locked->requested_by_user_id,
                    'approved_by_user_id' =>
                        $locked->approved_by_user_id,
                    'executed_by_user_id' =>
                        $actor->id,
                    'amount_minor' =>
                        $locked->amount_minor,
                    'currency_code' =>
                        $locked->currency_code,
                ]
            );

            return $execution->refresh()->load([
                'request',
                'executedBy',
                'cashMovement',
            ]);
        }, 3);
    }

    private function existingExecution(
        PurchasePaymentExecution $existing,
        PurchasePaymentRequest $request,
        ?string $executionReference,
        ?string $executionNote,
        User $actor
    ): PurchasePaymentExecution {
        if (
            (int) $existing->purchase_payment_request_id
                !== (int) $request->id
            || (int) $existing->executed_by_user_id
                !== (int) $actor->id
            || $existing->execution_reference
                !== $executionReference
            || $existing->execution_note
                !== $executionNote
        ) {
            throw new DomainException(
                'La misma clave de ejecución fue usada con otros hechos.'
            );
        }

        if (
            $request->status
                !== PurchasePaymentRequestStatus::Executed
        ) {
            throw new DomainException(
                'Existe una ejecución incompleta que requiere revisión; no se duplicará.'
            );
        }

        $movement = CashMovement::query()
            ->forOrganization($request->organization_id)
            ->where(
                'purchase_payment_execution_id',
                $existing->id
            )
            ->first();

        if (! $movement) {
            throw new DomainException(
                'La ejecución registrada no posee su movimiento de caja.'
            );
        }

        return $existing->refresh()->load([
            'request',
            'executedBy',
            'cashMovement',
        ]);
    }

    private function lockedExpectedAmountMinor(
        CashRegisterSession $session
    ): int {
        $netMinor = (int) CashMovement::query()
            ->where(
                'cash_register_session_id',
                $session->id
            )
            ->lockForUpdate()
            ->get(['direction', 'amount_minor'])
            ->sum(
                fn (CashMovement $movement): int =>
                    $movement->direction
                        === CashMovementDirection::In
                        ? $movement->amount_minor
                        : -$movement->amount_minor
            );

        return (int) $session->opening_amount_minor
            + $netMinor;
    }

    private function idempotencyKey(
        string $value
    ): string {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 180) {
            throw new DomainException(
                'La clave de idempotencia de ejecución es inválida.'
            );
        }

        return $value;
    }
}
