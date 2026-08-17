<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\InventoryQuantity;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SupplierInvoiceManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly AuditRecorder $audit
    ) {
    }

    public function record(
        SupplierInvoiceData $data,
        User $actor
    ): SupplierInvoice {
        $organizationId = $this->actors->authorize(
            $actor,
            'document'
        );

        return DB::transaction(function () use (
            $data,
            $actor,
            $organizationId
        ): SupplierInvoice {
            $order = PurchaseOrder::query()
                ->forOrganization($organizationId)
                ->whereKey($data->purchaseOrderId)
                ->with('lines')
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new DomainException(
                    'La orden de compra no existe en la organización activa.'
                );
            }

            if (in_array(
                $order->status,
                [
                    PurchaseOrderStatus::Draft,
                    PurchaseOrderStatus::Cancelled,
                ],
                true
            )) {
                throw new DomainException(
                    'El documento del proveedor requiere una orden emitida y no cancelada.'
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
                    'El documento del proveedor requiere número o referencia.'
                );
            }

            $issuedOn = CarbonImmutable::parse(
                $data->issuedOn,
                'UTC'
            )->startOfDay();

            $dueOn = $data->dueOn === null
                ? null
                : CarbonImmutable::parse(
                    $data->dueOn,
                    'UTC'
                )->startOfDay();

            if (
                $dueOn !== null
                && $dueOn->lessThan($issuedOn)
            ) {
                throw new DomainException(
                    'El vencimiento no puede ser anterior a la fecha del documento.'
                );
            }

            $idempotencyKey = PurchasePayload::idempotencyKey(
                $data->idempotencyKey
            );
            $notes = PurchasePayload::optionalText(
                $data->notes,
                'Las notas del documento',
                4000
            );
            $logisticsAmountMinor = PurchaseMoney::nonNegative(
                $data->logisticsAmountMinor,
                'El importe logístico documentado'
            );

            if ($data->lines === []) {
                throw new DomainException(
                    'El documento del proveedor requiere al menos una línea.'
                );
            }

            $orderLines = $order->lines->keyBy('id');
            $normalizedLines = [];
            $merchandiseTotalMinor = 0;

            foreach (
                array_values($data->lines)
                as $index => $line
            ) {
                if (! $line instanceof SupplierInvoiceLineData) {
                    throw new DomainException(
                        'La línea del documento del proveedor es inválida.'
                    );
                }

                $orderLine = null;

                if ($line->purchaseOrderLineId !== null) {
                    $orderLine = $orderLines->get(
                        $line->purchaseOrderLineId
                    );

                    if (! $orderLine) {
                        throw new DomainException(
                            'Una línea documentada no pertenece a la orden seleccionada.'
                        );
                    }
                }

                $quantity = InventoryQuantity::positive(
                    $line->quantity
                );

                InventoryQuantity::assertFitsScale(
                    $quantity,
                    $orderLine
                        ? (int) $orderLine->quantity_scale
                        : 6,
                    'La cantidad documentada'
                );

                $unitCostMinor = PurchaseMoney::nonNegative(
                    $line->unitCostMinor,
                    'El costo unitario documentado'
                );
                $subtotalMinor = PurchaseMoney::subtotal(
                    $quantity,
                    $unitCostMinor
                );
                $description = PurchasePayload::requiredText(
                    $line->description,
                    'La descripción de la línea documentada',
                    255
                );
                $supplierCode = PurchasePayload::optionalText(
                    $line->supplierCode,
                    'El código del proveedor',
                    100
                );

                $merchandiseTotalMinor = PurchaseMoney::add(
                    $merchandiseTotalMinor,
                    $subtotalMinor,
                    'El total de mercadería documentado'
                );

                $normalizedLines[] = [
                    'sequence' => $index + 1,
                    'purchase_order_line_id' =>
                        $orderLine?->id,
                    'catalog_product_id' =>
                        $orderLine?->catalog_product_id,
                    'supplier_code' => $supplierCode,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_cost_minor' => $unitCostMinor,
                    'subtotal_minor' => $subtotalMinor,
                ];
            }

            $totalMinor = PurchaseMoney::add(
                $merchandiseTotalMinor,
                $logisticsAmountMinor,
                'El total documentado'
            );

            if ($totalMinor <= 0) {
                throw new DomainException(
                    'El documento del proveedor debe conservar un importe total positivo.'
                );
            }

            $fingerprint = PurchasePayload::fingerprint([
                'purchase_order_id' => (int) $order->id,
                'supplier_id' => (int) $order->supplier_id,
                'document_number' =>
                    $document['normalized'],
                'issued_on' => $issuedOn->format('Y-m-d'),
                'due_on' => $dueOn?->format('Y-m-d'),
                'currency_code' =>
                    (string) $order->currency_code,
                'merchandise_total_minor' =>
                    $merchandiseTotalMinor,
                'logistics_amount_minor' =>
                    $logisticsAmountMinor,
                'total_minor' => $totalMinor,
                'lines' => $normalizedLines,
                'notes' => $notes,
            ]);

            $existing = SupplierInvoice::query()
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
                        'La misma clave de documento fue usada con otros datos.'
                    );
                }

                return $existing->load('lines');
            }

            $sameDocument = SupplierInvoice::query()
                ->forOrganization($organizationId)
                ->where('supplier_id', $order->supplier_id)
                ->where(
                    'normalized_document_number',
                    $document['normalized']
                )
                ->lockForUpdate()
                ->first();

            if ($sameDocument) {
                throw new DomainException(
                    'El proveedor ya posee un documento registrado con esa referencia.'
                );
            }

            $now = CarbonImmutable::now('UTC');

            $invoice = SupplierInvoice::query()->create([
                'organization_id' => $organizationId,
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'document_number' =>
                    $document['reference'],
                'normalized_document_number' =>
                    $document['normalized'],
                'issued_on' => $issuedOn->format('Y-m-d'),
                'due_on' => $dueOn?->format('Y-m-d'),
                'currency_code' => $order->currency_code,
                'merchandise_total_minor' =>
                    $merchandiseTotalMinor,
                'logistics_amount_minor' =>
                    $logisticsAmountMinor,
                'total_minor' => $totalMinor,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'recorded_by_user_id' => $actor->id,
                'recorded_at' => $now,
                'notes' => $notes,
                'created_at' => $now,
            ]);

            foreach ($normalizedLines as $line) {
                SupplierInvoiceLine::query()->create([
                    'organization_id' => $organizationId,
                    'supplier_invoice_id' => $invoice->id,
                    'purchase_order_id' => $order->id,
                    ...$line,
                ]);
            }

            $this->audit->record(
                $invoice,
                'supplier_invoice.recorded',
                null,
                [
                    'purchase_order_id' =>
                        (int) $order->id,
                    'supplier_id' =>
                        (int) $order->supplier_id,
                    'document_number' =>
                        $document['reference'],
                    'currency_code' =>
                        (string) $order->currency_code,
                    'total_minor' => $totalMinor,
                    'line_count' =>
                        count($normalizedLines),
                    'has_unmatched_lines' =>
                        collect($normalizedLines)
                            ->contains(
                                fn (array $line): bool =>
                                    $line[
                                        'purchase_order_line_id'
                                    ] === null
                            ),
                ]
            );

            return $invoice->load('lines');
        });
    }
}
