<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Enums\PurchasePaymentRequestStatus;
use App\Models\FinancialAccount;
use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentGroupRequest;
use App\Models\PurchasePaymentGroupRequestItem;
use App\Models\PurchasePaymentRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PurchasePaymentGroupRequestManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly AuditRecorder $audit,
        private readonly PurchaseObligationBalanceReader $balances
    ) {
    }

    public function request(
        PurchasePaymentGroupRequestData $data,
        User $actor
    ): PurchasePaymentGroupRequest {
        $organizationId = $this->actors->authorize(
            $actor,
            'request-payment'
        );

        $idempotencyKey = PurchasePayload::idempotencyKey(
            $data->idempotencyKey
        );
        $requestNote = PurchasePayload::optionalText(
            $data->requestNote,
            'La nota de solicitud agrupada',
            1000
        );
        $items = $this->normalizeItems(
            $data->items
        );

        if (count($items) < 2) {
            throw new DomainException(
                'Un pago agrupado requiere al menos dos obligaciones distintas.'
            );
        }

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $idempotencyKey,
            $requestNote,
            $items
        ): PurchasePaymentGroupRequest {
            $obligationIds = array_column(
                $items,
                'purchase_obligation_id'
            );

            $obligations = PurchaseObligation::query()
                ->forOrganization($organizationId)
                ->whereIn('id', $obligationIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if (
                $obligations->count()
                    !== count($obligationIds)
            ) {
                throw new DomainException(
                    'Una o más obligaciones no pertenecen a la organización activa.'
                );
            }

            $first = $obligations
                ->get($obligationIds[0]);

            if (! $first) {
                throw new DomainException(
                    'No se pudo resolver la primera obligación agrupada.'
                );
            }

            $origin = FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereKey(
                    $data->originFinancialAccountId
                )
                ->where('active', true)
                ->where(
                    'currency_code',
                    $first->currency_code
                )
                ->lockForUpdate()
                ->first();

            if (! $origin) {
                throw new DomainException(
                    'La cuenta de origen no está activa o no coincide en moneda.'
                );
            }

            foreach ($items as $item) {
                $obligation = $obligations
                    ->get(
                        $item[
                            'purchase_obligation_id'
                        ]
                    );

                if (! $obligation) {
                    throw new DomainException(
                        'Falta una obligación agrupada.'
                    );
                }

                if (
                    (int) $obligation->supplier_id
                        !== (int) $first->supplier_id
                    || (int) $obligation
                        ->beneficiary_business_party_id
                        !== (int) $first
                            ->beneficiary_business_party_id
                    || (string) $obligation
                        ->currency_code
                        !== (string) $first
                            ->currency_code
                ) {
                    throw new DomainException(
                        'Todas las obligaciones agrupadas deben compartir proveedor, beneficiario y moneda.'
                    );
                }

                $remaining = $this->balances
                    ->locked($obligation)
                    ['remaining_minor'];

                if (
                    $remaining <= 0
                    || $item['amount_minor']
                        > $remaining
                ) {
                    throw new DomainException(
                        'Una imputación agrupada supera el saldo económico pendiente de su obligación.'
                    );
                }

            }

            $fingerprint = PurchasePayload::fingerprint([
                'supplier_id' =>
                    (int) $first->supplier_id,
                'beneficiary_business_party_id' =>
                    (int) $first
                        ->beneficiary_business_party_id,
                'origin_financial_account_id' =>
                    (int) $origin->id,
                'currency_code' =>
                    (string) $first->currency_code,
                'request_note' => $requestNote,
                'items' => $items,
            ]);

            $existing = PurchasePaymentGroupRequest::query()
                ->forOrganization($organizationId)
                ->where(
                    'request_idempotency_key',
                    $idempotencyKey
                )
                ->with('items')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    (int) $existing
                        ->requested_by_user_id
                        !== (int) $actor->id
                    || ! hash_equals(
                        (string) $existing
                            ->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La misma clave de solicitud agrupada fue usada con otros hechos.'
                    );
                }

                return $existing;
            }

            foreach ($items as $item) {
                $this->assertNoActivePaymentIntent(
                    $organizationId,
                    (int) $item[
                        'purchase_obligation_id'
                    ]
                );
            }

            $now = CarbonImmutable::now('UTC');

            $request = PurchasePaymentGroupRequest::query()
                ->create([
                    'organization_id' =>
                        $organizationId,
                    'supplier_id' =>
                        $first->supplier_id,
                    'beneficiary_business_party_id' =>
                        $first
                            ->beneficiary_business_party_id,
                    'origin_financial_account_id' =>
                        $origin->id,
                    'requested_by_user_id' =>
                        $actor->id,
                    'currency_code' =>
                        $first->currency_code,
                    'status' =>
                        PurchasePaymentRequestStatus::Pending,
                    'request_note' =>
                        $requestNote,
                    'request_idempotency_key' =>
                        $idempotencyKey,
                    'fingerprint' =>
                        $fingerprint,
                    'requested_at' => $now,
                    'created_at' => $now,
                ]);

            foreach ($items as $item) {
                PurchasePaymentGroupRequestItem::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'purchase_payment_group_request_id' =>
                            $request->id,
                        'purchase_obligation_id' =>
                            $item[
                                'purchase_obligation_id'
                            ],
                        'amount_minor' =>
                            $item['amount_minor'],
                        'fingerprint' =>
                            PurchasePayload::fingerprint([
                                'group_fingerprint' =>
                                    $fingerprint,
                                'purchase_obligation_id' =>
                                    $item[
                                        'purchase_obligation_id'
                                    ],
                                'amount_minor' =>
                                    $item['amount_minor'],
                            ]),
                        'created_at' => $now,
                    ]);
            }

            $this->audit->record(
                $request,
                'purchase_payment_group.requested',
                null,
                [
                    'supplier_id' =>
                        (int) $first->supplier_id,
                    'beneficiary_business_party_id' =>
                        (int) $first
                            ->beneficiary_business_party_id,
                    'origin_financial_account_id' =>
                        (int) $origin->id,
                    'requested_by_user_id' =>
                        (int) $actor->id,
                    'currency_code' =>
                        (string) $first->currency_code,
                    'item_count' => count($items),
                    'amount_minor' =>
                        array_sum(
                            array_column(
                                $items,
                                'amount_minor'
                            )
                        ),
                    'money_effect' => 'none',
                ]
            );

            return $request
                ->refresh()
                ->load([
                    'items.obligation',
                    'supplier.party',
                    'beneficiary',
                    'originFinancialAccount',
                    'requestedBy',
                ]);
        }, 3);
    }

    public function approve(
        PurchasePaymentGroupRequest $request,
        ?string $approvalNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentGroupRequest {
        $organizationId = $this->actors->authorize(
            $actor,
            'approve-payment'
        );

        $approvalNote = PurchasePayload::optionalText(
            $approvalNote,
            'La nota de aprobación agrupada',
            1000
        );
        $idempotencyKey = PurchasePayload::idempotencyKey(
            $idempotencyKey
        );

        return DB::transaction(function () use (
            $request,
            $approvalNote,
            $idempotencyKey,
            $actor,
            $organizationId
        ): PurchasePaymentGroupRequest {
            $locked = $this->lockRequest(
                $request,
                $organizationId
            );

            if (
                $locked->status
                    === PurchasePaymentRequestStatus::Approved
                && $locked
                    ->approval_idempotency_key
                    === $idempotencyKey
                && (int) $locked
                    ->approved_by_user_id
                    === (int) $actor->id
            ) {
                return $locked;
            }

            if (
                $locked->status
                    !== PurchasePaymentRequestStatus::Pending
            ) {
                throw new DomainException(
                    'Sólo una solicitud agrupada pendiente puede autorizarse.'
                );
            }

            if (
                (int) $locked
                    ->requested_by_user_id
                    === (int) $actor->id
            ) {
                throw new DomainException(
                    'Quien solicita un pago agrupado no puede autorizarlo.'
                );
            }

            $this->revalidateCurrentFacts(
                $locked
            );

            $now = CarbonImmutable::now('UTC');

            $locked->status =
                PurchasePaymentRequestStatus::Approved;
            $locked->approved_by_user_id =
                $actor->id;
            $locked->approval_note =
                $approvalNote;
            $locked->approval_idempotency_key =
                $idempotencyKey;
            $locked->approval_fingerprint =
                PurchasePayload::fingerprint([
                    'request_fingerprint' =>
                        $locked->fingerprint,
                    'approved_by_user_id' =>
                        (int) $actor->id,
                    'approval_note' =>
                        $approvalNote,
                ]);
            $locked->approved_at = $now;
            $locked->save();

            $this->audit->record(
                $locked,
                'purchase_payment_group.approved',
                null,
                [
                    'requested_by_user_id' =>
                        (int) $locked
                            ->requested_by_user_id,
                    'approved_by_user_id' =>
                        (int) $actor->id,
                    'item_count' =>
                        $locked->items->count(),
                    'amount_minor' =>
                        (int) $locked->items
                            ->sum('amount_minor'),
                    'currency_code' =>
                        (string) $locked
                            ->currency_code,
                    'money_effect' => 'none',
                ]
            );

            return $locked->refresh()
                ->load('items.obligation');
        }, 3);
    }

    public function reject(
        PurchasePaymentGroupRequest $request,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentGroupRequest {
        $organizationId = $this->actors->authorize(
            $actor,
            'approve-payment'
        );

        return $this->resolve(
            $request,
            PurchasePaymentRequestStatus::Rejected,
            $resolutionNote,
            $idempotencyKey,
            $actor,
            $organizationId,
            true
        );
    }

    public function cancel(
        PurchasePaymentGroupRequest $request,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentGroupRequest {
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
        ): PurchasePaymentGroupRequest {
            $locked = $this->lockRequest(
                $request,
                $organizationId
            );

            if (
                (int) $locked
                    ->requested_by_user_id
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
        PurchasePaymentGroupRequest $request,
        PurchasePaymentRequestStatus $target,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor,
        int $organizationId,
        bool $mustDifferFromRequester
    ): PurchasePaymentGroupRequest {
        return DB::transaction(function () use (
            $request,
            $target,
            $resolutionNote,
            $idempotencyKey,
            $actor,
            $organizationId,
            $mustDifferFromRequester
        ): PurchasePaymentGroupRequest {
            $locked = $this->lockRequest(
                $request,
                $organizationId
            );

            if (
                $mustDifferFromRequester
                && (int) $locked
                    ->requested_by_user_id
                    === (int) $actor->id
            ) {
                throw new DomainException(
                    'El solicitante no puede resolver su propia solicitud agrupada como aprobador.'
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
        PurchasePaymentGroupRequest $locked,
        PurchasePaymentRequestStatus $target,
        string $resolutionNote,
        string $idempotencyKey,
        User $actor
    ): PurchasePaymentGroupRequest {
        $resolutionNote = PurchasePayload::requiredText(
            $resolutionNote,
            'La nota de resolución agrupada',
            1000
        );
        $idempotencyKey = PurchasePayload::idempotencyKey(
            $idempotencyKey
        );

        if (
            $locked->status === $target
            && $locked
                ->resolution_idempotency_key
                === $idempotencyKey
            && (int) $locked
                ->resolved_by_user_id
                === (int) $actor->id
        ) {
            return $locked;
        }

        if (! $locked->status->isActive()) {
            throw new DomainException(
                'La solicitud agrupada ya no admite esa resolución.'
            );
        }

        if (
            $target
                === PurchasePaymentRequestStatus::Rejected
            && $locked->status
                !== PurchasePaymentRequestStatus::Pending
        ) {
            throw new DomainException(
                'Una solicitud agrupada aprobada debe cancelarse, no rechazarse.'
            );
        }

        $locked->status = $target;
        $locked->resolved_by_user_id =
            $actor->id;
        $locked->resolution_note =
            $resolutionNote;
        $locked->resolution_idempotency_key =
            $idempotencyKey;
        $locked->resolved_at =
            CarbonImmutable::now('UTC');
        $locked->save();

        $event = match ($target) {
            PurchasePaymentRequestStatus::Rejected =>
                'purchase_payment_group.rejected',
            PurchasePaymentRequestStatus::Cancelled =>
                'purchase_payment_group.cancelled',
            default => throw new DomainException(
                'Estado de resolución agrupada inválido.'
            ),
        };

        $this->audit->record(
            $locked,
            $event,
            null,
            [
                'status' => $target,
                'resolved_by_user_id' =>
                    (int) $actor->id,
                'resolution_note' =>
                    $resolutionNote,
                'money_effect' => 'none',
            ]
        );

        return $locked->refresh()
            ->load('items.obligation');
    }

    private function lockRequest(
        PurchasePaymentGroupRequest $request,
        int $organizationId
    ): PurchasePaymentGroupRequest {
        $locked = PurchasePaymentGroupRequest::query()
            ->forOrganization($organizationId)
            ->whereKey($request->id)
            ->with('items')
            ->lockForUpdate()
            ->first();

        if (! $locked) {
            throw new DomainException(
                'La solicitud agrupada no pertenece a la organización activa.'
            );
        }

        return $locked;
    }

    private function revalidateCurrentFacts(
        PurchasePaymentGroupRequest $request
    ): void {
        $origin = FinancialAccount::query()
            ->forOrganization(
                $request->organization_id
            )
            ->whereKey(
                $request
                    ->origin_financial_account_id
            )
            ->where('active', true)
            ->where(
                'currency_code',
                $request->currency_code
            )
            ->lockForUpdate()
            ->first();

        if (! $origin) {
            throw new DomainException(
                'La cuenta de origen de la solicitud agrupada ya no está vigente.'
            );
        }

        if ($request->items->count() < 2) {
            throw new DomainException(
                'La solicitud agrupada perdió sus imputaciones mínimas.'
            );
        }

        foreach ($request->items as $item) {
            $obligation = PurchaseObligation::query()
                ->forOrganization(
                    $request->organization_id
                )
                ->whereKey(
                    $item->purchase_obligation_id
                )
                ->lockForUpdate()
                ->first();

            if (
                ! $obligation
                || (int) $obligation->supplier_id
                    !== (int) $request->supplier_id
                || (int) $obligation
                    ->beneficiary_business_party_id
                    !== (int) $request
                        ->beneficiary_business_party_id
                || (string) $obligation
                    ->currency_code
                    !== (string) $request
                        ->currency_code
            ) {
                throw new DomainException(
                    'Una obligación ya no coincide con los hechos agrupados autorizables.'
                );
            }

            $remaining = $this->balances
                ->locked($obligation)
                ['remaining_minor'];

            if (
                $remaining <= 0
                || (int) $item->amount_minor
                    > $remaining
            ) {
                throw new DomainException(
                    'Una imputación agrupada ya supera el saldo económico pendiente.'
                );
            }

            $this->assertNoActiveIndividualRequest(
                (int) $request->organization_id,
                (int) $obligation->id
            );
        }
    }

    private function assertNoActivePaymentIntent(
        int $organizationId,
        int $obligationId
    ): void {
        $this->assertNoActiveIndividualRequest(
            $organizationId,
            $obligationId
        );

        if (
            PurchasePaymentGroupRequestItem::query()
                ->forOrganization($organizationId)
                ->where(
                    'purchase_obligation_id',
                    $obligationId
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
                'La obligación ya participa en otra solicitud de pago agrupada activa.'
            );
        }
    }

    private function assertNoActiveIndividualRequest(
        int $organizationId,
        int $obligationId
    ): void {
        if (
            PurchasePaymentRequest::query()
                ->forOrganization($organizationId)
                ->where(
                    'purchase_obligation_id',
                    $obligationId
                )
                ->whereIn('status', [
                    PurchasePaymentRequestStatus::Pending->value,
                    PurchasePaymentRequestStatus::Approved->value,
                ])
                ->lockForUpdate()
                ->exists()
        ) {
            throw new DomainException(
                'La obligación ya posee una solicitud de pago individual activa.'
            );
        }
    }

    /**
     * @param array<int,PurchasePaymentGroupItemData> $items
     * @return array<int,array{
     *     purchase_obligation_id:int,
     *     amount_minor:int
     * }>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! $item instanceof PurchasePaymentGroupItemData) {
                throw new DomainException(
                    'Las imputaciones agrupadas poseen un formato inválido.'
                );
            }

            if (
                $item->purchaseObligationId <= 0
                || $item->amountMinor <= 0
            ) {
                throw new DomainException(
                    'Cada imputación agrupada requiere obligación e importe válidos.'
                );
            }

            if (
                isset(
                    $normalized[
                        $item->purchaseObligationId
                    ]
                )
            ) {
                throw new DomainException(
                    'Una obligación no puede repetirse dentro del mismo pago agrupado.'
                );
            }

            $normalized[
                $item->purchaseObligationId
            ] = [
                'purchase_obligation_id' =>
                    $item->purchaseObligationId,
                'amount_minor' =>
                    $item->amountMinor,
            ];
        }

        ksort($normalized, SORT_NUMERIC);

        return array_values($normalized);
    }
}
