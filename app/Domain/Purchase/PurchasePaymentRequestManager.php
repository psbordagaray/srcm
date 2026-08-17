<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Enums\PurchasePaymentRequestStatus;
use App\Models\FinancialAccount;
use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentExecution;
use App\Models\PurchasePaymentGroupRequestItem;
use App\Models\PurchasePaymentRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PurchasePaymentRequestManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly AuditRecorder $audit,
        private readonly PurchaseObligationBalanceReader $balances
    ) {
    }

    public function request(
        PurchasePaymentRequestData $data,
        User $actor
    ): PurchasePaymentRequest {
        $organizationId = $this->actors->authorize(
            $actor,
            'request-payment'
        );
        $idempotencyKey = $this->idempotencyKey(
            $data->idempotencyKey,
            'solicitud'
        );
        $requestNote = PurchasePayload::optionalText(
            $data->requestNote,
            'La nota de solicitud',
            1000
        );

        if ($data->amountMinor <= 0) {
            throw new DomainException(
                'El importe solicitado debe ser mayor que cero.'
            );
        }

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $idempotencyKey,
            $requestNote
        ): PurchasePaymentRequest {
            $obligation = PurchaseObligation::query()
                ->forOrganization($organizationId)
                ->whereKey($data->purchaseObligationId)
                ->lockForUpdate()
                ->first();

            if (! $obligation) {
                throw new DomainException(
                    'La obligación no existe o pertenece a otra organización.'
                );
            }

            $origin = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey($data->originFinancialAccountId)
                ->where('active', true)
                ->where('currency_code', $obligation->currency_code)
                ->lockForUpdate()
                ->first();

            if (! $origin) {
                throw new DomainException(
                    'La cuenta de origen propuesta no está activa o no coincide en moneda.'
                );
            }

            $fingerprint = $this->requestFingerprint(
                $obligation,
                $origin,
                $data->amountMinor,
                $requestNote
            );

            $existing = PurchasePaymentRequest::query()
                ->forOrganization($organizationId)
                ->where('request_idempotency_key', $idempotencyKey)
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
                        'La misma clave de solicitud fue usada con otros hechos.'
                    );
                }

                return $existing;
            }

            $remainingMinor = $this->balances
                ->locked($obligation)
                ['remaining_minor'];

            if (
                $remainingMinor <= 0
                || $data->amountMinor > $remainingMinor
            ) {
                throw new DomainException(
                    'El importe solicitado supera el saldo económico pendiente de ejecución.'
                );
            }

            if (PurchasePaymentRequest::query()
                ->forOrganization($organizationId)
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                )
                ->whereIn('status', [
                    PurchasePaymentRequestStatus::Pending->value,
                    PurchasePaymentRequestStatus::Approved->value,
                ])
                ->lockForUpdate()
                ->exists()
            ) {
                throw new DomainException(
                    'La obligación ya posee una solicitud de pago pendiente o autorizada.'
                );
            }

            if (
                PurchasePaymentGroupRequestItem::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'purchase_obligation_id',
                        $obligation->id
                    )
                    ->whereHas(
                        'request',
                        fn ($query) => $query
                            ->whereIn('status', [
                                PurchasePaymentRequestStatus::Pending->value,
                                PurchasePaymentRequestStatus::Approved->value,
                            ])
                    )
                    ->lockForUpdate()
                    ->exists()
            ) {
                throw new DomainException(
                    'La obligación participa en una solicitud de pago agrupada activa.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            $request = PurchasePaymentRequest::query()->create([
                'organization_id' => $organizationId,
                'purchase_obligation_id' => $obligation->id,
                'origin_financial_account_id' => $origin->id,
                'beneficiary_business_party_id' =>
                    $obligation->beneficiary_business_party_id,
                'requested_by_user_id' => $actor->id,
                'amount_minor' => $data->amountMinor,
                'currency_code' => $obligation->currency_code,
                'status' => PurchasePaymentRequestStatus::Pending,
                'request_note' => $requestNote,
                'request_idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'requested_at' => $now,
                'created_at' => $now,
            ]);

            $this->audit->record(
                $request,
                'purchase_payment_request.requested',
                null,
                [
                    'purchase_obligation_id' => $obligation->id,
                    'beneficiary_business_party_id' =>
                        $obligation->beneficiary_business_party_id,
                    'origin_financial_account_id' => $origin->id,
                    'requested_by_user_id' => $actor->id,
                    'amount_minor' => $data->amountMinor,
                    'currency_code' => $obligation->currency_code,
                ]
            );

            return $request->refresh()->load([
                'obligation.beneficiary',
                'originFinancialAccount',
                'requestedBy',
            ]);
        }, 3);
    }

    public function approve(
        PurchasePaymentRequest $request,
        ?string $approvalNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentRequest {
        $organizationId = $this->actors->authorize(
            $actor,
            'approve-payment'
        );
        $approvalNote = PurchasePayload::optionalText(
            $approvalNote,
            'La nota de aprobación',
            1000
        );
        $idempotencyKey = $this->idempotencyKey(
            $idempotencyKey,
            'aprobación'
        );

        return DB::transaction(function () use (
            $request,
            $actor,
            $organizationId,
            $approvalNote,
            $idempotencyKey
        ): PurchasePaymentRequest {
            $locked = $this->lockRequest(
                $request,
                $organizationId
            );

            if (
                $locked->status
                    === PurchasePaymentRequestStatus::Approved
                && $locked->approval_idempotency_key
                    === $idempotencyKey
                && (int) $locked->approved_by_user_id
                    === (int) $actor->id
            ) {
                return $locked;
            }

            if (
                $locked->status
                    !== PurchasePaymentRequestStatus::Pending
            ) {
                throw new DomainException(
                    'Sólo una solicitud pendiente puede autorizarse.'
                );
            }

            if (
                (int) $locked->requested_by_user_id
                    === (int) $actor->id
            ) {
                throw new DomainException(
                    'Quien solicita un pago no puede autorizarlo.'
                );
            }

            [$obligation, $origin] = $this->lockCurrentFacts(
                $locked
            );

            $expectedFingerprint = $this->requestFingerprint(
                $obligation,
                $origin,
                $locked->amount_minor,
                $locked->request_note
            );

            if (! hash_equals(
                (string) $locked->fingerprint,
                $expectedFingerprint
            )) {
                throw new DomainException(
                    'Los hechos autorizables ya no coinciden con la solicitud original.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            $locked->status =
                PurchasePaymentRequestStatus::Approved;
            $locked->approved_by_user_id = $actor->id;
            $locked->approval_idempotency_key =
                $idempotencyKey;
            $locked->approval_fingerprint =
                PurchasePayload::fingerprint([
                    'request_fingerprint' =>
                        $locked->fingerprint,
                    'approved_by_user_id' => $actor->id,
                    'approval_note' => $approvalNote,
                ]);
            $locked->approval_note = $approvalNote;
            $locked->approved_at = $now;
            $locked->save();

            $this->audit->record(
                $locked,
                'purchase_payment_request.approved',
                null,
                [
                    'purchase_obligation_id' =>
                        $locked->purchase_obligation_id,
                    'beneficiary_business_party_id' =>
                        $locked->beneficiary_business_party_id,
                    'origin_financial_account_id' =>
                        $locked->origin_financial_account_id,
                    'requested_by_user_id' =>
                        $locked->requested_by_user_id,
                    'approved_by_user_id' => $actor->id,
                    'amount_minor' => $locked->amount_minor,
                    'currency_code' => $locked->currency_code,
                ]
            );

            return $locked->refresh();
        }, 3);
    }

    public function reject(
        PurchasePaymentRequest $request,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentRequest {
        $organizationId = $this->actors->authorize(
            $actor,
            'approve-payment'
        );

        return $this->resolve(
            request: $request,
            target:
                PurchasePaymentRequestStatus::Rejected,
            resolutionNote: $resolutionNote,
            idempotencyKey: $idempotencyKey,
            actor: $actor,
            organizationId: $organizationId,
            mustBeDifferentFromRequester: true
        );
    }

    public function expire(
        PurchasePaymentRequest $request,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentRequest {
        $organizationId = $this->actors->authorize(
            $actor,
            'approve-payment'
        );

        return $this->resolve(
            request: $request,
            target:
                PurchasePaymentRequestStatus::Expired,
            resolutionNote: $resolutionNote,
            idempotencyKey: $idempotencyKey,
            actor: $actor,
            organizationId: $organizationId,
            mustBeDifferentFromRequester: true
        );
    }

    public function cancel(
        PurchasePaymentRequest $request,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentRequest {
        $organizationId = $this->actors->authorize(
            $actor,
            'request-payment'
        );

        return DB::transaction(function () use (
            $request,
            $resolutionNote,
            $idempotencyKey,
            $actor,
            $organizationId
        ): PurchasePaymentRequest {
            $locked = $this->lockRequest(
                $request,
                $organizationId
            );

            if (
                (int) $locked->requested_by_user_id
                    !== (int) $actor->id
            ) {
                $this->actors->authorize(
                    $actor,
                    'approve-payment'
                );
            }

            return $this->resolveLocked(
                $locked,
                PurchasePaymentRequestStatus::Cancelled,
                $resolutionNote,
                $idempotencyKey,
                $actor
            );
        }, 3);
    }

    private function resolve(
        PurchasePaymentRequest $request,
        PurchasePaymentRequestStatus $target,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor,
        int $organizationId,
        bool $mustBeDifferentFromRequester
    ): PurchasePaymentRequest {
        return DB::transaction(function () use (
            $request,
            $target,
            $resolutionNote,
            $idempotencyKey,
            $actor,
            $organizationId,
            $mustBeDifferentFromRequester
        ): PurchasePaymentRequest {
            $locked = $this->lockRequest(
                $request,
                $organizationId
            );

            if (
                $mustBeDifferentFromRequester
                && (int) $locked->requested_by_user_id
                    === (int) $actor->id
            ) {
                throw new DomainException(
                    'El solicitante no puede resolver su propia solicitud como aprobador.'
                );
            }

            return $this->resolveLocked(
                $locked,
                $target,
                $resolutionNote,
                $idempotencyKey,
                $actor
            );
        }, 3);
    }

    private function resolveLocked(
        PurchasePaymentRequest $locked,
        PurchasePaymentRequestStatus $target,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentRequest {
        $resolutionNote = trim($resolutionNote);
        $idempotencyKey = $this->idempotencyKey(
            $idempotencyKey,
            'resolución'
        );

        if (
            $resolutionNote === ''
            || mb_strlen($resolutionNote) > 1000
        ) {
            throw new DomainException(
                'La resolución requiere una nota válida.'
            );
        }

        if (
            $locked->status === $target
            && $locked->resolution_idempotency_key
                === $idempotencyKey
            && (int) $locked->resolved_by_user_id
                === (int) $actor->id
        ) {
            return $locked;
        }

        if (! $locked->status->isActive()) {
            throw new DomainException(
                'La solicitud ya no admite esa resolución.'
            );
        }

        if (
            $target === PurchasePaymentRequestStatus::Rejected
            && $locked->status
                !== PurchasePaymentRequestStatus::Pending
        ) {
            throw new DomainException(
                'Una autorización ya otorgada debe cancelarse o expirar, no rechazarse.'
            );
        }

        $locked->status = $target;
        $locked->resolved_by_user_id = $actor->id;
        $locked->resolution_idempotency_key =
            $idempotencyKey;
        $locked->resolution_note = $resolutionNote;
        $locked->resolved_at = CarbonImmutable::now('UTC');
        $locked->save();

        $event = match ($target) {
            PurchasePaymentRequestStatus::Rejected =>
                'purchase_payment_request.rejected',
            PurchasePaymentRequestStatus::Cancelled =>
                'purchase_payment_request.cancelled',
            PurchasePaymentRequestStatus::Expired =>
                'purchase_payment_request.expired',
            default => throw new DomainException(
                'Estado de resolución inválido.'
            ),
        };

        $this->audit->record(
            $locked,
            $event,
            null,
            [
                'status' => $target,
                'resolved_by_user_id' => $actor->id,
                'resolution_note' => $resolutionNote,
            ]
        );

        return $locked->refresh();
    }

    private function lockRequest(
        PurchasePaymentRequest $request,
        int $organizationId
    ): PurchasePaymentRequest {
        $locked = PurchasePaymentRequest::query()
            ->forOrganization($organizationId)
            ->whereKey($request->id)
            ->lockForUpdate()
            ->first();

        if (! $locked) {
            throw new DomainException(
                'La solicitud de pago no pertenece a la organización activa.'
            );
        }

        return $locked;
    }

    /**
     * @return array{0:PurchaseObligation,1:FinancialAccount}
     */
    private function lockCurrentFacts(
        PurchasePaymentRequest $request
    ): array {
        $obligation = PurchaseObligation::query()
            ->forOrganization($request->organization_id)
            ->whereKey($request->purchase_obligation_id)
            ->where(
                'beneficiary_business_party_id',
                $request->beneficiary_business_party_id
            )
            ->where('currency_code', $request->currency_code)
            ->lockForUpdate()
            ->first();

        if (
            ! $obligation
            || $request->amount_minor <= 0
            || $request->amount_minor > $obligation->amount_minor
        ) {
            throw new DomainException(
                'La obligación ya no coincide con la solicitud autorizable.'
            );
        }

        $origin = FinancialAccount::query()
            ->forOrganization($request->organization_id)
            ->whereKey($request->origin_financial_account_id)
            ->where('active', true)
            ->where('currency_code', $request->currency_code)
            ->lockForUpdate()
            ->first();

        if (! $origin) {
            throw new DomainException(
                'La cuenta de origen propuesta ya no está disponible.'
            );
        }

        return [$obligation, $origin];
    }

    private function requestFingerprint(
        PurchaseObligation $obligation,
        FinancialAccount $origin,
        int $amountMinor,
        ?string $requestNote
    ): string {
        return PurchasePayload::fingerprint([
            'organization_id' => (int) $obligation->organization_id,
            'purchase_obligation_id' => (int) $obligation->id,
            'obligation_fingerprint' => $obligation->fingerprint,
            'beneficiary_business_party_id' =>
                (int) $obligation->beneficiary_business_party_id,
            'amount_minor' => $amountMinor,
            'currency_code' => $obligation->currency_code,
            'origin_financial_account_id' => (int) $origin->id,
            'request_note' => $requestNote,
        ]);
    }

    private function idempotencyKey(
        string $value,
        string $label
    ): string {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 180) {
            throw new DomainException(
                'La clave de idempotencia de '.$label.' es inválida.'
            );
        }

        return $value;
    }
}
