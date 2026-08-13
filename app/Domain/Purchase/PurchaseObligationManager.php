<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Enums\PurchaseObligationCondition;
use App\Enums\PurchaseObligationKind;
use App\Models\BusinessParty;
use App\Models\PurchaseObligation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PurchaseObligationManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly AuditRecorder $audit
    ) {
    }

    public function recognize(
        PurchaseObligationData $data,
        User $actor
    ): PurchaseObligation {
        return DB::transaction(function () use (
            $data,
            $actor
        ): PurchaseObligation {
            $organizationId = $this->actors->authorize(
                $actor,
                'obligate'
            );

            $receipt = PurchaseReceipt::query()
                ->whereKey($data->purchaseReceiptId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $receipt) {
                throw new DomainException(
                    'La recepción no existe o pertenece a otra organización.'
                );
            }

            $order = PurchaseOrder::query()
                ->whereKey($receipt->purchase_order_id)
                ->where('organization_id', $organizationId)
                ->where('supplier_id', $receipt->supplier_id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new DomainException(
                    'La recepción perdió su orden de compra válida.'
                );
            }

            $supplier = Supplier::query()
                ->whereKey($receipt->supplier_id)
                ->where('organization_id', $organizationId)
                ->with('party')
                ->lockForUpdate()
                ->first();

            if (! $supplier || ! $supplier->party) {
                throw new DomainException(
                    'La obligación requiere proveedor e identidad comercial válidos.'
                );
            }

            $amountMinor = match ($data->kind) {
                PurchaseObligationKind::Merchandise =>
                    (int) $receipt->merchandise_total_minor,
                PurchaseObligationKind::Logistics =>
                    (int) $receipt->logistics_cost_minor,
            };

            if ($amountMinor <= 0) {
                throw new DomainException(
                    'No existe un importe positivo de '
                    .$data->kind->label()
                    .' para reconocer como obligación.'
                );
            }

            $beneficiaryId = $data->beneficiaryBusinessPartyId
                ?? (int) $supplier->business_party_id;

            $beneficiary = BusinessParty::query()
                ->whereKey($beneficiaryId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $beneficiary) {
                throw new DomainException(
                    'El beneficiario no existe o pertenece a otra organización.'
                );
            }

            [$dueOn, $conditionNote] = $this->condition(
                $data->paymentCondition,
                $data->dueOn,
                $data->conditionNote
            );

            $idempotencyKey = 'purchase-obligation:'
                .$receipt->public_id.':'.$data->kind->value;

            $fingerprint = PurchasePayload::fingerprint([
                'purchase_order_id' => (int) $order->id,
                'purchase_receipt_id' => (int) $receipt->id,
                'supplier_id' => (int) $supplier->id,
                'beneficiary_business_party_id' => (int) $beneficiary->id,
                'kind' => $data->kind->value,
                'currency_code' => (string) $order->currency_code,
                'amount_minor' => $amountMinor,
                'payment_condition' => $data->paymentCondition->value,
                'due_on' => $dueOn,
                'condition_note' => $conditionNote,
            ]);

            $existing = PurchaseObligation::query()
                ->where('organization_id', $organizationId)
                ->where('purchase_receipt_id', $receipt->id)
                ->where('kind', $data->kind->value)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals(
                    (string) $existing->fingerprint,
                    $fingerprint
                )) {
                    throw new DomainException(
                        'Esta recepción ya posee una obligación de '
                        .$data->kind->label()
                        .' reconocida con otros datos.'
                    );
                }

                return $existing;
            }

            $now = CarbonImmutable::now('UTC');

            $obligation = PurchaseObligation::query()->create([
                'organization_id' => $organizationId,
                'purchase_order_id' => $order->id,
                'purchase_receipt_id' => $receipt->id,
                'supplier_id' => $supplier->id,
                'beneficiary_business_party_id' => $beneficiary->id,
                'kind' => $data->kind,
                'currency_code' => $order->currency_code,
                'amount_minor' => $amountMinor,
                'payment_condition' => $data->paymentCondition,
                'due_on' => $dueOn,
                'condition_note' => $conditionNote,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'recognized_by_user_id' => $actor->id,
                'recognized_at' => $now,
                'created_at' => $now,
            ]);

            $this->audit->record(
                $obligation,
                'purchase_obligation.recognized',
                null,
                [
                    'public_id' => $obligation->public_id,
                    'purchase_order_id' => $order->id,
                    'purchase_receipt_id' => $receipt->id,
                    'supplier_id' => $supplier->id,
                    'beneficiary_business_party_id' => $beneficiary->id,
                    'kind' => $data->kind->value,
                    'currency_code' => $order->currency_code,
                    'amount_minor' => $amountMinor,
                    'payment_condition' =>
                        $data->paymentCondition->value,
                    'due_on' => $dueOn,
                ]
            );

            return $obligation->refresh()->load([
                'beneficiary',
                'recognizedBy',
                'receipt',
            ]);
        }, 3);
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function condition(
        PurchaseObligationCondition $condition,
        ?string $dueOn,
        ?string $conditionNote
    ): array {
        $dueOn = filled($dueOn) ? trim((string) $dueOn) : null;
        $conditionNote = PurchasePayload::optionalText(
            $conditionNote,
            'El detalle de condición',
            1000
        );

        if ($condition === PurchaseObligationCondition::DueDate) {
            if (
                $dueOn === null
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueOn)
            ) {
                throw new DomainException(
                    'La condición con vencimiento requiere fecha válida.'
                );
            }

            $date = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $dueOn,
                'UTC'
            );

            if (! $date || $date->format('Y-m-d') !== $dueOn) {
                throw new DomainException(
                    'La fecha de vencimiento es inválida.'
                );
            }
        } elseif ($dueOn !== null) {
            throw new DomainException(
                'Sólo la condición con vencimiento admite fecha.'
            );
        }

        if (
            $condition === PurchaseObligationCondition::Other
            && $conditionNote === null
        ) {
            throw new DomainException(
                'Otra condición de pago requiere detalle.'
            );
        }

        return [$dueOn, $conditionNote];
    }
}
