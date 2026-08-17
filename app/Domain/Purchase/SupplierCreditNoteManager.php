<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SupplierCreditNoteManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly AuditRecorder $audit
    ) {
    }

    public function record(
        SupplierCreditNoteData $data,
        User $actor
    ): SupplierCreditNote {
        $organizationId = $this->actors->authorize(
            $actor,
            'document'
        );

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId
        ): SupplierCreditNote {
            $invoice = SupplierInvoice::query()
                ->forOrganization($organizationId)
                ->whereKey($data->supplierInvoiceId)
                ->with('supplier')
                ->lockForUpdate()
                ->first();

            if (! $invoice || ! $invoice->supplier) {
                throw new DomainException(
                    'La factura del proveedor no existe en la organización activa.'
                );
            }

            $document = PurchasePayload::documentReference(
                $data->documentNumber
            );

            if (
                $document['reference'] === null
                || $document['normalized'] === null
            ) {
                throw new DomainException(
                    'La nota de crédito requiere número o referencia.'
                );
            }

            $issuedOn = $this->date(
                $data->issuedOn,
                'La fecha de la nota de crédito'
            );

            if (
                $issuedOn->lessThan(
                    $invoice->issued_on->startOfDay()
                )
            ) {
                throw new DomainException(
                    'La nota de crédito no puede ser anterior a la factura vinculada.'
                );
            }

            if ($data->amountMinor <= 0) {
                throw new DomainException(
                    'El importe de la nota de crédito debe ser mayor que cero.'
                );
            }

            $reason = PurchasePayload::requiredText(
                $data->reason,
                'El motivo de la nota de crédito',
                1000
            );
            $notes = PurchasePayload::optionalText(
                $data->notes,
                'Las notas de la nota de crédito',
                4000
            );
            $idempotencyKey = PurchasePayload::idempotencyKey(
                $data->idempotencyKey
            );

            $fingerprint = PurchasePayload::fingerprint([
                'supplier_invoice_id' => (int) $invoice->id,
                'purchase_order_id' =>
                    (int) $invoice->purchase_order_id,
                'supplier_id' =>
                    (int) $invoice->supplier_id,
                'document_number' =>
                    $document['normalized'],
                'issued_on' =>
                    $issuedOn->format('Y-m-d'),
                'currency_code' =>
                    (string) $invoice->currency_code,
                'amount_minor' => $data->amountMinor,
                'reason' => $reason,
                'notes' => $notes,
            ]);

            $existing = SupplierCreditNote::query()
                ->forOrganization($organizationId)
                ->where(
                    'idempotency_key',
                    $idempotencyKey
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals(
                    (string) $existing->fingerprint,
                    $fingerprint
                )) {
                    throw new DomainException(
                        'La misma clave de nota de crédito fue usada con otros datos.'
                    );
                }

                return $existing;
            }

            $sameDocument = SupplierCreditNote::query()
                ->forOrganization($organizationId)
                ->where(
                    'supplier_id',
                    $invoice->supplier_id
                )
                ->where(
                    'normalized_document_number',
                    $document['normalized']
                )
                ->lockForUpdate()
                ->first();

            if ($sameDocument) {
                throw new DomainException(
                    'El proveedor ya posee una nota de crédito con esa referencia.'
                );
            }

            $creditedMinor = (int) SupplierCreditNote::query()
                ->forOrganization($organizationId)
                ->where(
                    'supplier_invoice_id',
                    $invoice->id
                )
                ->lockForUpdate()
                ->get(['amount_minor'])
                ->sum('amount_minor');

            $remainingDocumentMinor = max(
                0,
                (int) $invoice->total_minor
                    - $creditedMinor
            );

            if (
                $remainingDocumentMinor <= 0
                || $data->amountMinor
                    > $remainingDocumentMinor
            ) {
                throw new DomainException(
                    'La nota de crédito supera el importe documental todavía acreditable.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            $creditNote = SupplierCreditNote::query()
                ->create([
                    'organization_id' =>
                        $organizationId,
                    'supplier_invoice_id' =>
                        $invoice->id,
                    'purchase_order_id' =>
                        $invoice->purchase_order_id,
                    'supplier_id' =>
                        $invoice->supplier_id,
                    'document_number' =>
                        $document['reference'],
                    'normalized_document_number' =>
                        $document['normalized'],
                    'issued_on' =>
                        $issuedOn->format('Y-m-d'),
                    'currency_code' =>
                        $invoice->currency_code,
                    'amount_minor' =>
                        $data->amountMinor,
                    'reason' => $reason,
                    'notes' => $notes,
                    'idempotency_key' =>
                        $idempotencyKey,
                    'fingerprint' =>
                        $fingerprint,
                    'recorded_by_user_id' =>
                        $actor->id,
                    'recorded_at' => $now,
                    'created_at' => $now,
                ]);

            $this->audit->record(
                $creditNote,
                'supplier_credit_note.recorded',
                null,
                [
                    'supplier_invoice_id' =>
                        (int) $invoice->id,
                    'purchase_order_id' =>
                        (int) $invoice->purchase_order_id,
                    'supplier_id' =>
                        (int) $invoice->supplier_id,
                    'document_number' =>
                        $document['reference'],
                    'currency_code' =>
                        (string) $invoice->currency_code,
                    'amount_minor' =>
                        $data->amountMinor,
                    'reason' => $reason,
                    'payable_effect' => 'none',
                ]
            );

            return $creditNote->refresh()->load([
                'invoice',
                'supplier.party',
                'recordedBy',
            ]);
        }, 3);
    }

    private function date(
        string $value,
        string $label
    ): CarbonImmutable {
        $value = trim($value);

        if (
            ! preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $value
            )
        ) {
            throw new DomainException(
                $label.' es inválida.'
            );
        }

        $date = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            'UTC'
        );

        if (
            ! $date
            || $date->format('Y-m-d') !== $value
        ) {
            throw new DomainException(
                $label.' es inválida.'
            );
        }

        return $date;
    }
}
