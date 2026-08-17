<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Enums\PurchasePaymentRequestStatus;
use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentRequest;
use App\Models\SupplierCreditApplication;
use App\Models\SupplierCreditNote;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SupplierCreditApplicationManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly AuditRecorder $audit,
        private readonly PurchaseObligationBalanceReader $balances
    ) {
    }

    public function apply(
        SupplierCreditApplicationData $data,
        User $actor
    ): SupplierCreditApplication {
        $organizationId = $this->actors
            ->authorize($actor, 'obligate');

        if ($data->amountMinor <= 0) {
            throw new DomainException(
                'El importe a aplicar debe ser mayor que cero.'
            );
        }

        $idempotencyKey = PurchasePayload::idempotencyKey(
            $data->idempotencyKey
        );
        $applicationNote = PurchasePayload::optionalText(
            $data->applicationNote,
            'La nota de aplicación',
            1000
        );

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId,
            $idempotencyKey,
            $applicationNote
        ): SupplierCreditApplication {
            $creditNote = SupplierCreditNote::query()
                ->forOrganization($organizationId)
                ->whereKey($data->supplierCreditNoteId)
                ->with('supplier.party')
                ->lockForUpdate()
                ->first();

            $obligation = PurchaseObligation::query()
                ->forOrganization($organizationId)
                ->whereKey($data->purchaseObligationId)
                ->lockForUpdate()
                ->first();

            if (
                ! $creditNote
                || ! $creditNote->supplier
                || ! $creditNote->supplier->party
                || ! $obligation
            ) {
                throw new DomainException(
                    'El crédito o la obligación no pertenecen a la organización activa.'
                );
            }

            if (
                (int) $creditNote->supplier_id
                    !== (int) $obligation->supplier_id
                || (string) $creditNote->currency_code
                    !== (string) $obligation->currency_code
                || (int) $obligation->beneficiary_business_party_id
                    !== (int) $creditNote->supplier->business_party_id
            ) {
                throw new DomainException(
                    'El crédito sólo puede aplicarse a deuda del mismo proveedor, moneda y beneficiario.'
                );
            }

            $fingerprint = PurchasePayload::fingerprint([
                'supplier_credit_note_id' =>
                    (int) $creditNote->id,
                'purchase_obligation_id' =>
                    (int) $obligation->id,
                'supplier_id' =>
                    (int) $creditNote->supplier_id,
                'beneficiary_business_party_id' =>
                    (int) $obligation
                        ->beneficiary_business_party_id,
                'currency_code' =>
                    (string) $creditNote->currency_code,
                'amount_minor' => $data->amountMinor,
                'application_note' => $applicationNote,
            ]);

            $existing = SupplierCreditApplication::query()
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
                        'La misma clave de aplicación fue usada con otros hechos.'
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
            ) {
                throw new DomainException(
                    'La obligación posee una solicitud de pago activa. Debe resolverse antes de aplicar crédito del proveedor.'
                );
            }

            $alreadyAppliedToSource =
                (int) SupplierCreditApplication::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'supplier_credit_note_id',
                        $creditNote->id
                    )
                    ->lockForUpdate()
                    ->get(['amount_minor'])
                    ->sum('amount_minor');

            $sourceAvailable = max(
                0,
                (int) $creditNote->amount_minor
                    - $alreadyAppliedToSource
            );

            $obligationBalance = $this->balances
                ->locked($obligation);

            if (
                $sourceAvailable <= 0
                || $data->amountMinor > $sourceAvailable
            ) {
                throw new DomainException(
                    'La nota de crédito no posee saldo suficiente para esta aplicación.'
                );
            }

            if (
                $obligationBalance['remaining_minor'] <= 0
                || $data->amountMinor
                    > $obligationBalance['remaining_minor']
            ) {
                throw new DomainException(
                    'La aplicación supera el saldo pendiente de la obligación.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            $application = SupplierCreditApplication::query()
                ->create([
                    'organization_id' => $organizationId,
                    'supplier_credit_note_id' => $creditNote->id,
                    'purchase_obligation_id' => $obligation->id,
                    'supplier_id' => $creditNote->supplier_id,
                    'beneficiary_business_party_id' =>
                        $obligation->beneficiary_business_party_id,
                    'currency_code' => $creditNote->currency_code,
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
                'supplier_credit.applied',
                null,
                [
                    'supplier_credit_note_id' =>
                        (int) $creditNote->id,
                    'purchase_obligation_id' =>
                        (int) $obligation->id,
                    'supplier_id' =>
                        (int) $creditNote->supplier_id,
                    'beneficiary_business_party_id' =>
                        (int) $obligation
                            ->beneficiary_business_party_id,
                    'currency_code' =>
                        (string) $creditNote->currency_code,
                    'amount_minor' => $data->amountMinor,
                    'cash_effect' => 'none',
                    'payment_execution_effect' => 'none',
                ]
            );

            return $application
                ->refresh()
                ->load([
                    'creditNote.invoice',
                    'obligation.order',
                    'appliedBy',
                ]);
        }, 3);
    }
}
