<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Enums\SupplierAdvanceDecisionType;
use App\Models\FinancialAccount;
use App\Models\Supplier;
use App\Models\SupplierAdvanceDecision;
use App\Models\SupplierAdvanceRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SupplierAdvanceRequestManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly AuditRecorder $audit
    ) {
    }

    public function request(
        SupplierAdvanceRequestData $data,
        User $actor
    ): SupplierAdvanceRequest {
        $organizationId = $this->actors->authorize(
            $actor,
            'request-payment'
        );

        if ($data->amountMinor <= 0) {
            throw new DomainException(
                'El anticipo solicitado debe ser mayor que cero.'
            );
        }

        $idempotencyKey = PurchasePayload::idempotencyKey(
            $data->idempotencyKey
        );
        $requestNote = PurchasePayload::optionalText(
            $data->requestNote,
            'La nota de solicitud de anticipo',
            1000
        );

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $idempotencyKey,
            $requestNote
        ): SupplierAdvanceRequest {
            $supplier = Supplier::query()
                ->forOrganization($organizationId)
                ->whereKey($data->supplierId)
                ->where('active', true)
                ->with('party')
                ->lockForUpdate()
                ->first();

            if (! $supplier || ! $supplier->party) {
                throw new DomainException(
                    'El proveedor no está activo o no pertenece a la organización.'
                );
            }

            $origin = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey($data->originFinancialAccountId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $origin) {
                throw new DomainException(
                    'La cuenta de origen no está activa o no pertenece a la organización.'
                );
            }

            $fingerprint = PurchasePayload::fingerprint([
                'supplier_id' => (int) $supplier->id,
                'origin_financial_account_id' =>
                    (int) $origin->id,
                'amount_minor' => $data->amountMinor,
                'currency_code' =>
                    (string) $origin->currency_code,
                'request_note' => $requestNote,
            ]);

            $existing = SupplierAdvanceRequest::query()
                ->forOrganization($organizationId)
                ->where(
                    'request_idempotency_key',
                    $idempotencyKey
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    (int) $existing->requested_by_user_id
                        !== (int) $actor->id
                    || ! hash_equals(
                        (string) $existing->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La misma clave de solicitud de anticipo fue usada con otros hechos.'
                    );
                }

                return $existing;
            }

            $now = CarbonImmutable::now('UTC');

            $request = SupplierAdvanceRequest::query()
                ->create([
                    'organization_id' =>
                        $organizationId,
                    'supplier_id' => $supplier->id,
                    'origin_financial_account_id' =>
                        $origin->id,
                    'requested_by_user_id' =>
                        $actor->id,
                    'amount_minor' =>
                        $data->amountMinor,
                    'currency_code' =>
                        $origin->currency_code,
                    'request_note' =>
                        $requestNote,
                    'request_idempotency_key' =>
                        $idempotencyKey,
                    'fingerprint' =>
                        $fingerprint,
                    'requested_at' => $now,
                    'created_at' => $now,
                ]);

            $this->audit->record(
                $request,
                'supplier_advance.requested',
                null,
                [
                    'supplier_id' =>
                        (int) $supplier->id,
                    'origin_financial_account_id' =>
                        (int) $origin->id,
                    'requested_by_user_id' =>
                        (int) $actor->id,
                    'amount_minor' =>
                        $data->amountMinor,
                    'currency_code' =>
                        (string) $origin
                            ->currency_code,
                    'money_effect' => 'none',
                ]
            );

            return $request->refresh()->load([
                'supplier.party',
                'originFinancialAccount',
                'requestedBy',
            ]);
        }, 3);
    }

    public function approve(
        SupplierAdvanceRequest $request,
        ?string $approvalNote,
        string $idempotencyKey,
        User $actor
    ): SupplierAdvanceDecision {
        return $this->decide(
            request: $request,
            type: SupplierAdvanceDecisionType::Approved,
            decisionNote: PurchasePayload::optionalText(
                $approvalNote,
                'La nota de aprobación',
                1000
            ),
            idempotencyKey: $idempotencyKey,
            actor: $actor
        );
    }

    public function reject(
        SupplierAdvanceRequest $request,
        string $rejectionNote,
        string $idempotencyKey,
        User $actor
    ): SupplierAdvanceDecision {
        return $this->decide(
            request: $request,
            type: SupplierAdvanceDecisionType::Rejected,
            decisionNote: PurchasePayload::requiredText(
                $rejectionNote,
                'El motivo de rechazo',
                1000
            ),
            idempotencyKey: $idempotencyKey,
            actor: $actor
        );
    }

    private function decide(
        SupplierAdvanceRequest $request,
        SupplierAdvanceDecisionType $type,
        ?string $decisionNote,
        string $idempotencyKey,
        User $actor
    ): SupplierAdvanceDecision {
        $organizationId = $this->actors->authorize(
            $actor,
            'approve-payment'
        );
        $idempotencyKey = PurchasePayload::idempotencyKey(
            $idempotencyKey
        );

        return DB::transaction(function () use (
            $request,
            $type,
            $decisionNote,
            $idempotencyKey,
            $actor,
            $organizationId
        ): SupplierAdvanceDecision {
            $locked = SupplierAdvanceRequest::query()
                ->forOrganization($organizationId)
                ->whereKey($request->id)
                ->with([
                    'supplier.party',
                    'originFinancialAccount',
                ])
                ->lockForUpdate()
                ->first();

            if (
                ! $locked
                || ! $locked->supplier
                || ! $locked->supplier->party
                || ! $locked->originFinancialAccount
            ) {
                throw new DomainException(
                    'La solicitud de anticipo no pertenece a la organización activa.'
                );
            }

            if (
                (int) $locked->requested_by_user_id
                    === (int) $actor->id
            ) {
                throw new DomainException(
                    'Quien solicita un anticipo no puede decidir su propia autorización.'
                );
            }

            if (
                ! $locked->supplier->active
                || ! $locked
                    ->originFinancialAccount
                    ->active
                || (string) $locked
                    ->originFinancialAccount
                    ->currency_code
                    !== (string) $locked
                        ->currency_code
            ) {
                throw new DomainException(
                    'Los hechos autorizables del anticipo ya no están vigentes.'
                );
            }

            $decisionFingerprint =
                PurchasePayload::fingerprint([
                    'request_id' =>
                        (int) $locked->id,
                    'request_fingerprint' =>
                        (string) $locked
                            ->fingerprint,
                    'decision' => $type->value,
                    'decided_by_user_id' =>
                        (int) $actor->id,
                    'decision_note' =>
                        $decisionNote,
                ]);

            $existingByKey =
                SupplierAdvanceDecision::query()
                    ->forOrganization(
                        $organizationId
                    )
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
                    $decisionFingerprint
                )) {
                    throw new DomainException(
                        'La misma clave de decisión de anticipo fue usada con otros hechos.'
                    );
                }

                return $existingByKey;
            }

            $existing =
                SupplierAdvanceDecision::query()
                    ->forOrganization(
                        $organizationId
                    )
                    ->where(
                        'supplier_advance_request_id',
                        $locked->id
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existing) {
                throw new DomainException(
                    'La solicitud de anticipo ya posee una decisión.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            $decision =
                SupplierAdvanceDecision::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'supplier_advance_request_id' =>
                            $locked->id,
                        'decision' => $type,
                        'decision_note' =>
                            $decisionNote,
                        'decided_by_user_id' =>
                            $actor->id,
                        'idempotency_key' =>
                            $idempotencyKey,
                        'fingerprint' =>
                            $decisionFingerprint,
                        'decided_at' => $now,
                        'created_at' => $now,
                    ]);

            $this->audit->record(
                $decision,
                $type
                    === SupplierAdvanceDecisionType::Approved
                        ? 'supplier_advance.approved'
                        : 'supplier_advance.rejected',
                null,
                [
                    'supplier_advance_request_id' =>
                        (int) $locked->id,
                    'supplier_id' =>
                        (int) $locked->supplier_id,
                    'decision' => $type->value,
                    'decided_by_user_id' =>
                        (int) $actor->id,
                    'amount_minor' =>
                        (int) $locked
                            ->amount_minor,
                    'currency_code' =>
                        (string) $locked
                            ->currency_code,
                    'money_effect' => 'none',
                ]
            );

            return $decision->refresh()->load([
                'request.supplier.party',
                'decidedBy',
            ]);
        }, 3);
    }
}
