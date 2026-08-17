<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Enums\PurchasePaymentRequestStatus;
use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentGroupRequestItem;
use App\Models\PurchasePaymentRequest;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SupplierAdvanceApplicationManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly AuditRecorder $audit,
        private readonly PurchaseObligationBalanceReader $balances
    ) {
    }

    public function apply(
        SupplierAdvanceApplicationData $data,
        User $actor
    ): SupplierAdvanceApplication {
        $organizationId = $this->actors
            ->authorize($actor, 'obligate');

        if ($data->amountMinor <= 0) {
            throw new DomainException(
                'El importe de anticipo a aplicar debe ser mayor que cero.'
            );
        }

        $idempotencyKey = PurchasePayload::idempotencyKey(
            $data->idempotencyKey
        );
        $applicationNote = PurchasePayload::optionalText(
            $data->applicationNote,
            'La nota de aplicación del anticipo',
            1000
        );

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $idempotencyKey,
            $applicationNote
        ): SupplierAdvanceApplication {
            $advance = SupplierAdvance::query()
                ->forOrganization($organizationId)
                ->whereKey($data->supplierAdvanceId)
                ->with('supplier.party')
                ->lockForUpdate()
                ->first();

            $obligation = PurchaseObligation::query()
                ->forOrganization($organizationId)
                ->whereKey($data->purchaseObligationId)
                ->lockForUpdate()
                ->first();

            if (
                ! $advance
                || ! $advance->supplier
                || ! $advance->supplier->party
                || ! $obligation
            ) {
                throw new DomainException(
                    'El anticipo o la obligación no pertenecen a la organización activa.'
                );
            }

            if (
                (int) $advance->supplier_id
                    !== (int) $obligation->supplier_id
                || (string) $advance->currency_code
                    !== (string) $obligation->currency_code
                || (int) $obligation->beneficiary_business_party_id
                    !== (int) $advance->supplier->business_party_id
            ) {
                throw new DomainException(
                    'El anticipo sólo puede aplicarse a deuda del mismo proveedor, moneda y beneficiario.'
                );
            }

            $fingerprint = PurchasePayload::fingerprint([
                'supplier_advance_id' =>
                    (int) $advance->id,
                'purchase_obligation_id' =>
                    (int) $obligation->id,
                'supplier_id' =>
                    (int) $advance->supplier_id,
                'beneficiary_business_party_id' =>
                    (int) $obligation
                        ->beneficiary_business_party_id,
                'currency_code' =>
                    (string) $advance->currency_code,
                'amount_minor' => $data->amountMinor,
                'application_note' => $applicationNote,
            ]);

            $existing = SupplierAdvanceApplication::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals(
                    (string) $existing->fingerprint,
                    $fingerprint
                )) {
                    throw new DomainException(
                        'La misma clave de aplicación de anticipo fue usada con otros hechos.'
                    );
                }

                return $existing;
            }

            if (
                PurchasePaymentRequest::query()
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
                || PurchasePaymentGroupRequestItem::query()
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
                    'La obligación posee una autorización de pago activa. Debe resolverse antes de aplicar un anticipo.'
                );
            }

            $alreadyAppliedToSource =
                (int) SupplierAdvanceApplication::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'supplier_advance_id',
                        $advance->id
                    )
                    ->lockForUpdate()
                    ->get(['amount_minor'])
                    ->sum('amount_minor');

            $sourceAvailable = max(
                0,
                (int) $advance->amount_minor
                    - $alreadyAppliedToSource
            );

            $obligationBalance = $this->balances
                ->locked($obligation);

            if (
                $sourceAvailable <= 0
                || $data->amountMinor > $sourceAvailable
            ) {
                throw new DomainException(
                    'El anticipo no posee saldo suficiente para esta aplicación.'
                );
            }

            if (
                $obligationBalance['remaining_minor'] <= 0
                || $data->amountMinor
                    > $obligationBalance['remaining_minor']
            ) {
                throw new DomainException(
                    'La aplicación del anticipo supera el saldo pendiente de la obligación.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            $application = SupplierAdvanceApplication::query()
                ->create([
                    'organization_id' => $organizationId,
                    'supplier_advance_id' => $advance->id,
                    'purchase_obligation_id' => $obligation->id,
                    'supplier_id' => $advance->supplier_id,
                    'beneficiary_business_party_id' =>
                        $obligation->beneficiary_business_party_id,
                    'currency_code' => $advance->currency_code,
                    'amount_minor' => $data->amountMinor,
                    'application_note' => $applicationNote,
                    'idempotency_key' => $idempotencyKey,
                    'fingerprint' => $fingerprint,
                    'applied_by_user_id' => $actor->id,
                    'applied_at' => $now,
                    'created_at' => $now,
                ]);

            $this->audit->record(
                $application,
                'supplier_advance.applied',
                null,
                [
                    'supplier_advance_id' =>
                        (int) $advance->id,
                    'purchase_obligation_id' =>
                        (int) $obligation->id,
                    'supplier_id' =>
                        (int) $advance->supplier_id,
                    'beneficiary_business_party_id' =>
                        (int) $obligation
                            ->beneficiary_business_party_id,
                    'currency_code' =>
                        (string) $advance->currency_code,
                    'amount_minor' => $data->amountMinor,
                    'cash_effect' => 'none',
                    'external_financial_effect' => 'none',
                    'payment_execution_effect' => 'none',
                ]
            );

            return $application
                ->refresh()
                ->load([
                    'advance.request',
                    'obligation.order',
                    'appliedBy',
                ]);
        }, 3);
    }
}
