<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Finance\CashLedgerRecorder;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Enums\FinancialAccountType;
use App\Enums\SupplierAdvanceDecisionType;
use App\Models\FinancialAccount;
use App\Models\Supplier;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceDecision;
use App\Models\SupplierAdvanceRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SupplierAdvanceManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly CashRegisterSessionManager $cashSessions,
        private readonly CashLedgerRecorder $cashLedger,
        private readonly AuditRecorder $audit
    ) {
    }

    public function execute(
        SupplierAdvanceRequest $request,
        SupplierAdvanceExecutionData $data,
        User $actor
    ): SupplierAdvance {
        $organizationId = $this->actors->authorize(
            $actor,
            'execute-payment'
        );
        $idempotencyKey = PurchasePayload::idempotencyKey(
            $data->idempotencyKey
        );
        $reference = PurchasePayload::optionalText(
            $data->executionReference,
            'La referencia de ejecución',
            255
        );
        $note = PurchasePayload::optionalText(
            $data->executionNote,
            'La nota de ejecución',
            1000
        );

        return DB::transaction(function () use (
            $request,
            $data,
            $actor,
            $organizationId,
            $idempotencyKey,
            $reference,
            $note
        ): SupplierAdvance {
            $locked = SupplierAdvanceRequest::query()
                ->forOrganization($organizationId)
                ->whereKey($request->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La solicitud de anticipo no pertenece a la organización activa.'
                );
            }

            $decision = SupplierAdvanceDecision::query()
                ->forOrganization($organizationId)
                ->where(
                    'supplier_advance_request_id',
                    $locked->id
                )
                ->lockForUpdate()
                ->first();

            if (
                ! $decision
                || $decision->decision
                    !== SupplierAdvanceDecisionType::Approved
            ) {
                throw new DomainException(
                    'El anticipo requiere una aprobación vigente antes de ejecutarse.'
                );
            }

            if (
                (int) $decision->decided_by_user_id
                    === (int) $actor->id
            ) {
                throw new DomainException(
                    'Quien autoriza un anticipo no puede ejecutarlo.'
                );
            }

            $baseFingerprint = PurchasePayload::fingerprint([
                'supplier_advance_request_id' =>
                    (int) $locked->id,
                'request_fingerprint' =>
                    (string) $locked->fingerprint,
                'executed_by_user_id' =>
                    (int) $actor->id,
                'execution_reference' =>
                    $reference,
                'execution_note' => $note,
            ]);

            $existingByKey = SupplierAdvance::query()
                ->forOrganization($organizationId)
                ->where(
                    'idempotency_key',
                    $idempotencyKey
                )
                ->lockForUpdate()
                ->first();

            if ($existingByKey) {
                if (! hash_equals(
                    (string) $existingByKey
                        ->fingerprint,
                    $baseFingerprint
                )) {
                    throw new DomainException(
                        'La misma clave de ejecución de anticipo fue usada con otros hechos.'
                    );
                }

                return $existingByKey;
            }

            if (
                SupplierAdvance::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->where(
                        'supplier_advance_request_id',
                        $locked->id
                    )
                    ->lockForUpdate()
                    ->exists()
            ) {
                throw new DomainException(
                    'La solicitud de anticipo ya fue ejecutada.'
                );
            }

            $supplier = Supplier::query()
                ->forOrganization($organizationId)
                ->whereKey($locked->supplier_id)
                ->where('active', true)
                ->with('party')
                ->lockForUpdate()
                ->first();

            $origin = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey(
                    $locked
                        ->origin_financial_account_id
                )
                ->where('active', true)
                ->where(
                    'currency_code',
                    $locked->currency_code
                )
                ->lockForUpdate()
                ->first();

            if (
                ! $supplier
                || ! $supplier->party
                || ! $origin
            ) {
                throw new DomainException(
                    'El proveedor o la cuenta autorizada ya no están vigentes.'
                );
            }

            $cashSession = null;
            $channel = 'noncash';

            if (
                $origin->type
                    === FinancialAccountType::CashBox
            ) {
                $channel = 'cash';
                $cashSession = $this->cashSessions
                    ->lockCurrentFor($actor);

                $register =
                    $cashSession?->register;
                $cashAccount =
                    $register?->financialAccount;

                if (
                    ! $cashSession
                    || ! $register
                    || ! $register->active
                    || ! $cashAccount
                    || ! $cashAccount->active
                    || (int) $cashAccount->id
                        !== (int) $origin->id
                    || $cashSession
                        ->currency_code
                        !== $locked
                            ->currency_code
                ) {
                    throw new DomainException(
                        'El anticipo en efectivo debe salir de la caja del turno abierto del ejecutor.'
                    );
                }
            } elseif (
                $origin->type
                    === FinancialAccountType::CashReserve
            ) {
                throw new DomainException(
                    'P9.7f no ejecuta anticipos directamente desde Tesorería de efectivo.'
                );
            } elseif ($reference === null) {
                throw new DomainException(
                    'Un anticipo no efectivo requiere referencia de la operación.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            $advance = SupplierAdvance::query()
                ->create([
                    'organization_id' =>
                        $organizationId,
                    'supplier_advance_request_id' =>
                        $locked->id,
                    'supplier_id' =>
                        $supplier->id,
                    'origin_financial_account_id' =>
                        $origin->id,
                    'cash_register_session_id' =>
                        $cashSession?->id,
                    'cash_register_id' =>
                        $cashSession
                            ?->cash_register_id,
                    'executed_by_user_id' =>
                        $actor->id,
                    'channel' => $channel,
                    'amount_minor' =>
                        $locked->amount_minor,
                    'currency_code' =>
                        $locked->currency_code,
                    'execution_reference' =>
                        $reference,
                    'execution_note' => $note,
                    'idempotency_key' =>
                        $idempotencyKey,
                    'fingerprint' =>
                        $baseFingerprint,
                    'executed_at' => $now,
                    'created_at' => $now,
                ]);

            if ($channel === 'cash') {
                if (! $cashSession) {
                    throw new DomainException(
                        'La ejecución en efectivo perdió el turno de caja.'
                    );
                }

                $this->cashLedger
                    ->recordSupplierAdvance(
                        $cashSession,
                        $advance,
                        $actor
                    );
            }

            $this->audit->record(
                $advance,
                'supplier_advance.executed',
                null,
                [
                    'supplier_advance_request_id' =>
                        (int) $locked->id,
                    'supplier_id' =>
                        (int) $supplier->id,
                    'origin_financial_account_id' =>
                        (int) $origin->id,
                    'executed_by_user_id' =>
                        (int) $actor->id,
                    'channel' => $channel,
                    'amount_minor' =>
                        (int) $locked->amount_minor,
                    'currency_code' =>
                        (string) $locked
                            ->currency_code,
                    'supplier_credit_effect' =>
                        'deferred',
                ]
            );

            return $advance->refresh()->load([
                'request.decision',
                'supplier.party',
                'originFinancialAccount',
                'cashMovement',
                'executedBy',
            ]);
        }, 3);
    }
}
