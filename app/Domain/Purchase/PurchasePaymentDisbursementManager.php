<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CashRegisterSessionStatus;
use App\Enums\FinancialAccountType;
use App\Enums\PurchasePaymentDisbursementChannel;
use App\Enums\PurchasePaymentRequestStatus;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\FinancialAccount;
use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentDisbursement;
use App\Models\PurchasePaymentDisbursementAllocation;
use App\Models\PurchasePaymentGroupRequest;
use App\Models\PurchasePaymentRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PurchasePaymentDisbursementManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly AuditRecorder $audit,
        private readonly PurchaseObligationBalanceReader $balances
    ) {
    }

    public function executeIndividual(
        PurchasePaymentRequest $request,
        ?string $executionReference,
        ?string $executionNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentDisbursement {
        $organizationId = $this->actors->authorize(
            $actor,
            'execute-payment'
        );

        $executionReference = PurchasePayload::optionalText(
            $executionReference,
            'La referencia de desembolso',
            180
        );
        $executionNote = PurchasePayload::optionalText(
            $executionNote,
            'La nota de desembolso',
            1000
        );
        $idempotencyKey = PurchasePayload::idempotencyKey(
            $idempotencyKey
        );

        return DB::transaction(function () use (
            $request,
            $executionReference,
            $executionNote,
            $idempotencyKey,
            $actor,
            $organizationId
        ): PurchasePaymentDisbursement {
            $locked = PurchasePaymentRequest::query()
                ->forOrganization($organizationId)
                ->whereKey($request->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La autorización individual no pertenece a la organización activa.'
                );
            }

            $existing = PurchasePaymentDisbursement::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->with([
                    'allocations',
                    'cashMovement',
                ])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->existingIndividual(
                    $existing,
                    $locked,
                    $executionReference,
                    $executionNote,
                    $actor
                );
            }

            $this->assertApprovedAndSegregated(
                $locked->status,
                $locked->approved_by_user_id,
                $actor
            );

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
                    'La autorización individual no conserva una huella válida.'
                );
            }

            $obligation = PurchaseObligation::query()
                ->forOrganization($organizationId)
                ->whereKey(
                    $locked->purchase_obligation_id
                )
                ->where(
                    'beneficiary_business_party_id',
                    $locked->beneficiary_business_party_id
                )
                ->where(
                    'currency_code',
                    $locked->currency_code
                )
                ->lockForUpdate()
                ->first();

            if (! $obligation) {
                throw new DomainException(
                    'La obligación individual ya no coincide con la autorización.'
                );
            }

            $remainingMinor = $this->balances
                ->locked($obligation)
                ['remaining_minor'];

            if (
                $locked->amount_minor <= 0
                || $locked->amount_minor
                    > $remainingMinor
            ) {
                throw new DomainException(
                    'El desembolso individual supera el saldo económico ejecutable.'
                );
            }

            $origin = $this->lockOrigin(
                $organizationId,
                (int) $locked
                    ->origin_financial_account_id,
                (string) $locked->currency_code
            );

            [$channel, $session] =
                $this->resolveChannel(
                    $origin,
                    $organizationId,
                    (string) $locked->currency_code,
                    (int) $locked->amount_minor,
                    $executionReference,
                    $actor
                );

            $now = CarbonImmutable::now('UTC');

            $fingerprint =
                PurchasePayload::fingerprint([
                    'authorization_type' =>
                        'individual',
                    'purchase_payment_request_id' =>
                        (int) $locked->id,
                    'request_fingerprint' =>
                        (string) $locked->fingerprint,
                    'approval_fingerprint' =>
                        (string) $locked
                            ->approval_fingerprint,
                    'origin_financial_account_id' =>
                        (int) $origin->id,
                    'beneficiary_business_party_id' =>
                        (int) $locked
                            ->beneficiary_business_party_id,
                    'channel' => $channel->value,
                    'cash_register_session_id' =>
                        $session?->id,
                    'cash_register_id' =>
                        $session?->cash_register_id,
                    'executed_by_user_id' =>
                        (int) $actor->id,
                    'amount_minor' =>
                        (int) $locked->amount_minor,
                    'currency_code' =>
                        (string) $locked->currency_code,
                    'execution_reference' =>
                        $executionReference,
                    'execution_note' =>
                        $executionNote,
                ]);

            $disbursement =
                PurchasePaymentDisbursement::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'purchase_payment_request_id' =>
                            $locked->id,
                        'purchase_payment_group_request_id' =>
                            null,
                        'origin_financial_account_id' =>
                            $origin->id,
                        'beneficiary_business_party_id' =>
                            $locked
                                ->beneficiary_business_party_id,
                        'channel' =>
                            $channel,
                        'cash_register_session_id' =>
                            $session?->id,
                        'cash_register_id' =>
                            $session?->cash_register_id,
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
                            $fingerprint,
                        'executed_at' => $now,
                        'created_at' => $now,
                    ]);

            $this->createAllocation(
                $disbursement,
                $obligation,
                $locked,
                null,
                (int) $locked->amount_minor,
                $now
            );

            $movement = $this->recordCashIfNeeded(
                $disbursement,
                $origin,
                $session,
                $actor,
                $now
            );

            $locked->status =
                PurchasePaymentRequestStatus::Executed;
            $locked->save();

            $this->recordAudit(
                $disbursement,
                $movement,
                1,
                $actor
            );

            return $disbursement
                ->refresh()
                ->load([
                    'individualRequest',
                    'allocations.obligation',
                    'originFinancialAccount',
                    'executedBy',
                    'cashMovement',
                ]);
        }, 3);
    }

    public function executeGroup(
        PurchasePaymentGroupRequest $request,
        ?string $executionReference,
        ?string $executionNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentDisbursement {
        $organizationId = $this->actors->authorize(
            $actor,
            'execute-payment'
        );

        $executionReference = PurchasePayload::optionalText(
            $executionReference,
            'La referencia de desembolso',
            180
        );
        $executionNote = PurchasePayload::optionalText(
            $executionNote,
            'La nota de desembolso',
            1000
        );
        $idempotencyKey = PurchasePayload::idempotencyKey(
            $idempotencyKey
        );

        return DB::transaction(function () use (
            $request,
            $executionReference,
            $executionNote,
            $idempotencyKey,
            $actor,
            $organizationId
        ): PurchasePaymentDisbursement {
            $locked = PurchasePaymentGroupRequest::query()
                ->forOrganization($organizationId)
                ->whereKey($request->id)
                ->with('items')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La autorización agrupada no pertenece a la organización activa.'
                );
            }

            $existing = PurchasePaymentDisbursement::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->with([
                    'allocations',
                    'cashMovement',
                ])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->existingGroup(
                    $existing,
                    $locked,
                    $executionReference,
                    $executionNote,
                    $actor
                );
            }

            $this->assertApprovedAndSegregated(
                $locked->status,
                $locked->approved_by_user_id,
                $actor
            );

            if ($locked->items->count() < 2) {
                throw new DomainException(
                    'La autorización agrupada perdió sus imputaciones mínimas.'
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
                    'La autorización agrupada no conserva una huella válida.'
                );
            }

            $origin = $this->lockOrigin(
                $organizationId,
                (int) $locked
                    ->origin_financial_account_id,
                (string) $locked->currency_code
            );

            $obligations = $this->lockGroupObligations(
                $locked
            );

            $totalMinor = 0;

            foreach ($locked->items as $item) {
                $obligation = $obligations
                    ->get(
                        (int) $item
                            ->purchase_obligation_id
                    );

                if (! $obligation) {
                    throw new DomainException(
                        'Falta una obligación de la autorización agrupada.'
                    );
                }

                $remainingMinor = $this->balances
                    ->locked($obligation)
                    ['remaining_minor'];

                if (
                    (int) $item->amount_minor <= 0
                    || (int) $item->amount_minor
                        > $remainingMinor
                ) {
                    throw new DomainException(
                        'Una imputación agrupada supera el saldo económico ejecutable.'
                    );
                }

                $totalMinor +=
                    (int) $item->amount_minor;
            }

            [$channel, $session] =
                $this->resolveChannel(
                    $origin,
                    $organizationId,
                    (string) $locked->currency_code,
                    $totalMinor,
                    $executionReference,
                    $actor
                );

            $now = CarbonImmutable::now('UTC');

            $fingerprint =
                PurchasePayload::fingerprint([
                    'authorization_type' =>
                        'group',
                    'purchase_payment_group_request_id' =>
                        (int) $locked->id,
                    'request_fingerprint' =>
                        (string) $locked->fingerprint,
                    'approval_fingerprint' =>
                        (string) $locked
                            ->approval_fingerprint,
                    'origin_financial_account_id' =>
                        (int) $origin->id,
                    'beneficiary_business_party_id' =>
                        (int) $locked
                            ->beneficiary_business_party_id,
                    'channel' => $channel->value,
                    'cash_register_session_id' =>
                        $session?->id,
                    'cash_register_id' =>
                        $session?->cash_register_id,
                    'executed_by_user_id' =>
                        (int) $actor->id,
                    'amount_minor' =>
                        $totalMinor,
                    'currency_code' =>
                        (string) $locked->currency_code,
                    'execution_reference' =>
                        $executionReference,
                    'execution_note' =>
                        $executionNote,
                    'items' =>
                        $locked->items
                            ->map(fn ($item) => [
                                'id' => (int) $item->id,
                                'purchase_obligation_id' =>
                                    (int) $item
                                        ->purchase_obligation_id,
                                'amount_minor' =>
                                    (int) $item->amount_minor,
                                'fingerprint' =>
                                    (string) $item
                                        ->fingerprint,
                            ])
                            ->values()
                            ->all(),
                ]);

            $disbursement =
                PurchasePaymentDisbursement::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'purchase_payment_request_id' =>
                            null,
                        'purchase_payment_group_request_id' =>
                            $locked->id,
                        'origin_financial_account_id' =>
                            $origin->id,
                        'beneficiary_business_party_id' =>
                            $locked
                                ->beneficiary_business_party_id,
                        'channel' =>
                            $channel,
                        'cash_register_session_id' =>
                            $session?->id,
                        'cash_register_id' =>
                            $session?->cash_register_id,
                        'executed_by_user_id' =>
                            $actor->id,
                        'amount_minor' =>
                            $totalMinor,
                        'currency_code' =>
                            $locked->currency_code,
                        'execution_reference' =>
                            $executionReference,
                        'execution_note' =>
                            $executionNote,
                        'idempotency_key' =>
                            $idempotencyKey,
                        'fingerprint' =>
                            $fingerprint,
                        'executed_at' => $now,
                        'created_at' => $now,
                    ]);

            foreach ($locked->items as $item) {
                $obligation = $obligations
                    ->get(
                        (int) $item
                            ->purchase_obligation_id
                    );

                $this->createAllocation(
                    $disbursement,
                    $obligation,
                    null,
                    $item,
                    (int) $item->amount_minor,
                    $now
                );
            }

            $movement = $this->recordCashIfNeeded(
                $disbursement,
                $origin,
                $session,
                $actor,
                $now
            );

            $locked->status =
                PurchasePaymentRequestStatus::Executed;
            $locked->save();

            $this->recordAudit(
                $disbursement,
                $movement,
                $locked->items->count(),
                $actor
            );

            return $disbursement
                ->refresh()
                ->load([
                    'groupRequest.items',
                    'allocations.obligation',
                    'originFinancialAccount',
                    'executedBy',
                    'cashMovement',
                ]);
        }, 3);
    }

    private function assertApprovedAndSegregated(
        PurchasePaymentRequestStatus $status,
        ?int $approvedByUserId,
        User $actor
    ): void {
        if (
            $status !== PurchasePaymentRequestStatus::Approved
            || $approvedByUserId === null
        ) {
            throw new DomainException(
                'El pago debe estar autorizado antes del desembolso.'
            );
        }

        if (
            (int) $approvedByUserId
                === (int) $actor->id
        ) {
            throw new DomainException(
                'Quien autorizó el pago no puede ejecutar el desembolso.'
            );
        }
    }

    private function lockOrigin(
        int $organizationId,
        int $accountId,
        string $currencyCode
    ): FinancialAccount {
        $origin = FinancialAccount::query()
            ->forOrganization($organizationId)
            ->whereKey($accountId)
            ->where('active', true)
            ->where(
                'currency_code',
                $currencyCode
            )
            ->lockForUpdate()
            ->first();

        if (! $origin) {
            throw new DomainException(
                'La cuenta de origen ya no está activa o no coincide en moneda.'
            );
        }

        if (
            $origin->type
                === FinancialAccountType::CashReserve
        ) {
            throw new DomainException(
                'P9.7i no ejecuta pagos directos desde reserva física; requiere flujo de tesorería.'
            );
        }

        return $origin;
    }

    /**
     * @return array{
     *     0:PurchasePaymentDisbursementChannel,
     *     1:?CashRegisterSession
     * }
     */
    private function resolveChannel(
        FinancialAccount $origin,
        int $organizationId,
        string $currencyCode,
        int $amountMinor,
        ?string $executionReference,
        User $actor
    ): array {
        if (
            $origin->type
                !== FinancialAccountType::CashBox
        ) {
            if (blank($executionReference)) {
                throw new DomainException(
                    'Un desembolso non-cash requiere referencia externa o bancaria.'
                );
            }

            return [
                PurchasePaymentDisbursementChannel::NonCash,
                null,
            ];
        }

        $session = CashRegisterSession::query()
            ->forOrganization($organizationId)
            ->where(
                'opened_by_user_id',
                $actor->id
            )
            ->where(
                'status',
                CashRegisterSessionStatus::Open
            )
            ->where(
                'currency_code',
                $currencyCode
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
                'El desembolso cash requiere un turno abierto propio sobre la caja autorizada.'
            );
        }

        $expectedMinor =
            $this->lockedExpectedAmountMinor(
                $session
            );

        if ($amountMinor > $expectedMinor) {
            throw new DomainException(
                'El desembolso cash supera el efectivo esperado del turno.'
            );
        }

        return [
            PurchasePaymentDisbursementChannel::Cash,
            $session,
        ];
    }

    /**
     * @return Collection<int,PurchaseObligation>
     */
    private function lockGroupObligations(
        PurchasePaymentGroupRequest $request
    ): Collection {
        $ids = $request->items
            ->pluck('purchase_obligation_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $obligations = PurchaseObligation::query()
            ->forOrganization(
                (int) $request->organization_id
            )
            ->whereIn('id', $ids)
            ->where(
                'supplier_id',
                $request->supplier_id
            )
            ->where(
                'beneficiary_business_party_id',
                $request
                    ->beneficiary_business_party_id
            )
            ->where(
                'currency_code',
                $request->currency_code
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($obligations->count() !== count($ids)) {
            throw new DomainException(
                'Las obligaciones agrupadas ya no conservan proveedor, beneficiario y moneda.'
            );
        }

        return $obligations;
    }

    private function createAllocation(
        PurchasePaymentDisbursement $disbursement,
        PurchaseObligation $obligation,
        ?PurchasePaymentRequest $individualRequest,
        mixed $groupItem,
        int $amountMinor,
        CarbonImmutable $now
    ): PurchasePaymentDisbursementAllocation {
        return PurchasePaymentDisbursementAllocation::query()
            ->create([
                'organization_id' =>
                    $disbursement->organization_id,
                'purchase_payment_disbursement_id' =>
                    $disbursement->id,
                'purchase_obligation_id' =>
                    $obligation->id,
                'purchase_payment_request_id' =>
                    $individualRequest?->id,
                'purchase_payment_group_request_item_id' =>
                    $groupItem?->id,
                'amount_minor' => $amountMinor,
                'fingerprint' =>
                    PurchasePayload::fingerprint([
                        'disbursement_fingerprint' =>
                            $disbursement->fingerprint,
                        'purchase_obligation_id' =>
                            (int) $obligation->id,
                        'purchase_payment_request_id' =>
                            $individualRequest?->id,
                        'purchase_payment_group_request_item_id' =>
                            $groupItem?->id,
                        'amount_minor' =>
                            $amountMinor,
                    ]),
                'created_at' => $now,
            ]);
    }

    private function recordCashIfNeeded(
        PurchasePaymentDisbursement $disbursement,
        FinancialAccount $origin,
        ?CashRegisterSession $session,
        User $actor,
        CarbonImmutable $now
    ): ?CashMovement {
        if (
            $disbursement->channel
                !== PurchasePaymentDisbursementChannel::Cash
        ) {
            return null;
        }

        if (! $session || ! $session->register) {
            throw new DomainException(
                'Falta el turno requerido para registrar el desembolso cash.'
            );
        }

        return CashMovement::query()->create([
            'organization_id' =>
                $disbursement->organization_id,
            'cash_register_session_id' =>
                $session->id,
            'cash_register_id' =>
                $session->cash_register_id,
            'financial_account_id' =>
                $origin->id,
            'destination_financial_account_id' =>
                null,
            'cash_security_drop_request_id' =>
                null,
            'purchase_payment_execution_id' =>
                null,
            'purchase_payment_disbursement_id' =>
                $disbursement->id,
            'post_sale_cash_refund_execution_id' =>
                null,
            'post_sale_exchange_payment_id' =>
                null,
            'customer_collection_id' =>
                null,
            'customer_advance_id' =>
                null,
            'supplier_advance_id' =>
                null,
            'commerce_payment_id' =>
                null,
            'direction' =>
                CashMovementDirection::Out,
            'type' =>
                CashMovementType::PurchasePaymentDisbursement,
            'reason_code' => null,
            'note' => null,
            'amount_minor' =>
                $disbursement->amount_minor,
            'currency_code' =>
                $disbursement->currency_code,
            'idempotency_key' =>
                'purchase-payment-disbursement:'
                .$disbursement->id,
            'fingerprint' =>
                PurchasePayload::fingerprint([
                    'purchase_payment_disbursement_id' =>
                        (int) $disbursement->id,
                    'cash_register_session_id' =>
                        (int) $session->id,
                    'cash_register_id' =>
                        (int) $session
                            ->cash_register_id,
                    'financial_account_id' =>
                        (int) $origin->id,
                    'direction' =>
                        CashMovementDirection::Out->value,
                    'type' =>
                        CashMovementType::PurchasePaymentDisbursement
                            ->value,
                    'amount_minor' =>
                        (int) $disbursement
                            ->amount_minor,
                    'currency_code' =>
                        (string) $disbursement
                            ->currency_code,
                    'recorded_by_user_id' =>
                        (int) $actor->id,
                ]),
            'recorded_by_user_id' =>
                $actor->id,
            'occurred_at' => $now,
            'created_at' => $now,
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
            ->get([
                'direction',
                'amount_minor',
            ])
            ->sum(
                fn (CashMovement $movement): int =>
                    $movement->direction
                        === CashMovementDirection::In
                        ? $movement->amount_minor
                        : -$movement->amount_minor
            );

        return (int) $session
            ->opening_amount_minor
            + $netMinor;
    }

    private function existingIndividual(
        PurchasePaymentDisbursement $existing,
        PurchasePaymentRequest $request,
        ?string $executionReference,
        ?string $executionNote,
        User $actor
    ): PurchasePaymentDisbursement {
        if (
            (int) $existing
                ->purchase_payment_request_id
                !== (int) $request->id
            || $existing
                ->purchase_payment_group_request_id
                !== null
            || (int) $existing
                ->executed_by_user_id
                !== (int) $actor->id
            || $existing->execution_reference
                !== $executionReference
            || $existing->execution_note
                !== $executionNote
            || $request->status
                !== PurchasePaymentRequestStatus::Executed
            || $existing->allocations->count() !== 1
        ) {
            throw new DomainException(
                'La misma clave de desembolso individual fue usada con otros hechos.'
            );
        }

        return $existing->refresh()->load([
            'individualRequest',
            'allocations.obligation',
            'originFinancialAccount',
            'executedBy',
            'cashMovement',
        ]);
    }

    private function existingGroup(
        PurchasePaymentDisbursement $existing,
        PurchasePaymentGroupRequest $request,
        ?string $executionReference,
        ?string $executionNote,
        User $actor
    ): PurchasePaymentDisbursement {
        if (
            (int) $existing
                ->purchase_payment_group_request_id
                !== (int) $request->id
            || $existing
                ->purchase_payment_request_id
                !== null
            || (int) $existing
                ->executed_by_user_id
                !== (int) $actor->id
            || $existing->execution_reference
                !== $executionReference
            || $existing->execution_note
                !== $executionNote
            || $request->status
                !== PurchasePaymentRequestStatus::Executed
            || $existing->allocations->count()
                !== $request->items->count()
        ) {
            throw new DomainException(
                'La misma clave de desembolso agrupado fue usada con otros hechos.'
            );
        }

        return $existing->refresh()->load([
            'groupRequest.items',
            'allocations.obligation',
            'originFinancialAccount',
            'executedBy',
            'cashMovement',
        ]);
    }

    private function recordAudit(
        PurchasePaymentDisbursement $disbursement,
        ?CashMovement $movement,
        int $allocationCount,
        User $actor
    ): void {
        $this->audit->record(
            $disbursement,
            'purchase_payment_disbursement.executed',
            null,
            [
                'purchase_payment_request_id' =>
                    $disbursement
                        ->purchase_payment_request_id,
                'purchase_payment_group_request_id' =>
                    $disbursement
                        ->purchase_payment_group_request_id,
                'origin_financial_account_id' =>
                    $disbursement
                        ->origin_financial_account_id,
                'beneficiary_business_party_id' =>
                    $disbursement
                        ->beneficiary_business_party_id,
                'channel' =>
                    $disbursement->channel,
                'cash_movement_id' =>
                    $movement?->id,
                'executed_by_user_id' =>
                    (int) $actor->id,
                'allocation_count' =>
                    $allocationCount,
                'amount_minor' =>
                    $disbursement->amount_minor,
                'currency_code' =>
                    $disbursement->currency_code,
                'external_financial_movement_effect' =>
                    'none',
            ]
        );
    }
}
