<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Inventory\InventoryQuantity;
use App\Enums\InventoryMovementType;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryLocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PurchaseReceiptManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly InventoryMovementCreator $movements,
        private readonly InventoryMovementConfirmer $confirmer,
        private readonly AuditRecorder $audit
    ) {
    }

    public function receive(
        PurchaseReceiptData $data,
        User $actor
    ): PurchaseReceipt {
        return DB::transaction(function () use (
            $data,
            $actor
        ): PurchaseReceipt {
            $organizationId = $this->actors->authorize(
                $actor,
                'receive'
            );
            $idempotencyKey = PurchasePayload::idempotencyKey(
                $data->idempotencyKey
            );
            $document = PurchasePayload::documentReference(
                $data->documentReference
            );
            $logisticsCost = PurchaseMoney::nonNegative(
                $data->logisticsCostMinor,
                'El costo logístico real'
            );
            $notes = PurchasePayload::optionalText(
                $data->notes,
                'Las notas de recepción',
                4000
            );
            $receivedAt = CarbonImmutable::instance(
                $data->receivedAt
            )->utc();

            $order = PurchaseOrder::query()
                ->whereKey($data->purchaseOrderId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new DomainException(
                    'La orden no existe en la organización activa.'
                );
            }

            $orderLines = PurchaseOrderLine::query()
                ->where('organization_id', $organizationId)
                ->where('purchase_order_id', $order->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($orderLines->isEmpty()) {
                throw new DomainException(
                    'La orden no posee líneas recepcionables.'
                );
            }

            $existing = PurchaseReceipt::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            $existingReceiptLinesQuery =
                PurchaseReceiptLine::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'purchase_order_id',
                        $order->id
                    );

            if ($existing) {
                $existingReceiptLinesQuery->where(
                    'purchase_receipt_id',
                    '<>',
                    $existing->id
                );
            }

            $existingReceiptLines =
                $existingReceiptLinesQuery
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            $preparedLines = $this->prepareLines(
                $data->lines,
                $organizationId,
                $orderLines,
                $existingReceiptLines
            );

            $fingerprint = PurchasePayload::fingerprint([
                'purchase_order_id' => (int) $order->id,
                'received_at' => $receivedAt->format(
                    'Y-m-d\TH:i:s.u\Z'
                ),
                'document_reference' =>
                    $document['reference'],
                'normalized_document_reference' =>
                    $document['normalized'],
                'logistics_cost_minor' => $logisticsCost,
                'notes' => $notes,
                'lines' => array_map(
                    fn (array $line): array => [
                        'purchase_order_line_id' =>
                            $line['purchase_order_line_id'],
                        'catalog_product_id' =>
                            $line['catalog_product_id'],
                        'base_unit_code' =>
                            $line['base_unit_code'],
                        'quantity_scale' =>
                            $line['quantity_scale'],
                        'inventory_location_id' =>
                            $line['inventory_location_id'],
                        'condition' =>
                            $line['condition_value'],
                        'received_quantity' =>
                            $line['received_quantity'],
                        'actual_unit_cost_minor' =>
                            $line['actual_unit_cost_minor'],
                        'subtotal_minor' =>
                            $line['subtotal_minor'],
                    ],
                    $preparedLines
                ),
            ]);

            if ($existing) {
                if (! hash_equals(
                    (string) $existing->fingerprint,
                    $fingerprint
                )) {
                    throw new DomainException(
                        'La clave de idempotencia de recepción ya fue utilizada con otro contenido.'
                    );
                }

                return $existing->load([
                    'lines.inventoryMovementLine',
                    'inventoryMovement.lines',
                    'order.lines',
                ]);
            }

            if (! $order->status->acceptsReceipts()) {
                throw new DomainException(
                    'La orden no admite nuevas recepciones.'
                );
            }

            if ($document['normalized'] !== null) {
                $duplicateDocument = PurchaseReceipt::query()
                    ->where('organization_id', $organizationId)
                    ->where('supplier_id', $order->supplier_id)
                    ->where(
                        'normalized_document_reference',
                        $document['normalized']
                    )
                    ->lockForUpdate()
                    ->exists();

                if ($duplicateDocument) {
                    throw new DomainException(
                        'La referencia documental ya fue utilizada para este proveedor.'
                    );
                }
            }

            $publicId = (string) Str::uuid();
            $movement = $this->movements->create(
                new InventoryMovementDraftData(
                    type: InventoryMovementType::Receipt,
                    effectiveAt: $receivedAt,
                    reason: 'Recepción de compra '
                        .$order->public_id,
                    idempotencyKey: 'purchase-receipt:'
                        .substr(
                            hash(
                                'sha256',
                                $organizationId.':'.$idempotencyKey
                            ),
                            0,
                            64
                        ),
                    lines: array_map(
                        fn (array $line) =>
                            new InventoryMovementLineData(
                                catalogProductId:
                                    $line['catalog_product_id'],
                                condition: $line['condition'],
                                enteredQuantity:
                                    $line['received_quantity'],
                                enteredUnitCode:
                                    $line['base_unit_code'],
                                conversionFactor: '1',
                                destinationLocationId:
                                    $line['inventory_location_id'],
                                notes: 'Recepción de orden '
                                    .$order->public_id
                            ),
                        $preparedLines
                    ),
                    sourceType: 'purchase_receipt',
                    sourceId: $publicId,
                    sourceReference:
                        $document['reference'],
                    metadata: [
                        'purchase_order_public_id' =>
                            $order->public_id,
                        'supplier_id' =>
                            (int) $order->supplier_id,
                    ]
                ),
                $actor
            );
            $movement = $this->confirmer->confirm(
                $movement,
                $actor
            );
            $movementLines = $movement->lines
                ->sortBy('sequence')
                ->values();

            if ($movementLines->count() !== count($preparedLines)) {
                throw new DomainException(
                    'El movimiento confirmado no conserva todas las líneas de recepción.'
                );
            }

            $merchandiseTotal = 0;

            foreach ($preparedLines as $line) {
                $merchandiseTotal = PurchaseMoney::add(
                    $merchandiseTotal,
                    $line['subtotal_minor'],
                    'El total real de mercadería'
                );
            }

            $receipt = PurchaseReceipt::query()->create([
                'organization_id' => $organizationId,
                'public_id' => $publicId,
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'inventory_movement_id' => $movement->id,
                'document_reference' =>
                    $document['reference'],
                'normalized_document_reference' =>
                    $document['normalized'],
                'received_at' => $receivedAt,
                'confirmed_at' => now(),
                'received_by_user_id' => $actor->id,
                'logistics_cost_minor' => $logisticsCost,
                'merchandise_total_minor' =>
                    $merchandiseTotal,
                'actual_total_minor' => PurchaseMoney::add(
                    $merchandiseTotal,
                    $logisticsCost,
                    'El total real'
                ),
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'notes' => $notes,
            ]);

            $newReceiptLines = collect();

            foreach ($preparedLines as $index => $line) {
                $movementLine = $movementLines->get($index);

                $newReceiptLines->push(
                    PurchaseReceiptLine::query()->create([
                        'organization_id' => $organizationId,
                        'purchase_receipt_id' => $receipt->id,
                        'purchase_order_id' => $order->id,
                        'purchase_order_line_id' =>
                            $line['purchase_order_line_id'],
                        'inventory_movement_id' =>
                            $movement->id,
                        'inventory_movement_line_id' =>
                            $movementLine->id,
                        'sequence' => $index + 1,
                        'catalog_product_id' =>
                            $line['catalog_product_id'],
                        'inventory_location_id' =>
                            $line['inventory_location_id'],
                        'condition' => $line['condition'],
                        'received_quantity' =>
                            $line['received_quantity'],
                        'actual_unit_cost_minor' =>
                            $line['actual_unit_cost_minor'],
                        'subtotal_minor' =>
                            $line['subtotal_minor'],
                    ])
                );
            }

            $oldStatus = $order->status;
            $newStatus = $this->statusAfterReceipt(
                $orderLines,
                $existingReceiptLines->concat(
                    $newReceiptLines
                )
            );
            $order->forceFill([
                'status' => $newStatus,
            ])->save();

            $this->audit->record(
                $receipt,
                'purchase_receipt.confirmed',
                null,
                [
                    'public_id' => $receipt->public_id,
                    'purchase_order_id' =>
                        $receipt->purchase_order_id,
                    'inventory_movement_id' =>
                        $receipt->inventory_movement_id,
                    'actual_total_minor' =>
                        $receipt->actual_total_minor,
                    'line_count' => count($preparedLines),
                ]
            );

            if ($oldStatus !== $newStatus) {
                $this->audit->record(
                    $order,
                    'purchase_order.status_changed',
                    ['status' => $oldStatus],
                    ['status' => $newStatus]
                );
            }

            return $receipt->refresh()->load([
                'lines.inventoryMovementLine',
                'inventoryMovement.lines',
                'order.lines',
            ]);
        }, 3);
    }

    /**
     * @param list<PurchaseReceiptLineData> $lines
     * @param Collection<int, PurchaseOrderLine> $orderLines
     * @param Collection<int, PurchaseReceiptLine> $existing
     * @return list<array<string, mixed>>
     */
    private function prepareLines(
        array $lines,
        int $organizationId,
        Collection $orderLines,
        Collection $existing
    ): array {
        if ($lines === []) {
            throw new DomainException(
                'La recepción requiere al menos una línea.'
            );
        }

        $receivedByOrderLine = [];

        foreach ($existing as $receiptLine) {
            $lineId = (int) $receiptLine->purchase_order_line_id;
            $receivedByOrderLine[$lineId] =
                InventoryQuantity::add(
                    $receivedByOrderLine[$lineId] ?? '0',
                    $receiptLine->received_quantity
                );
        }

        $seen = [];
        $prepared = [];

        foreach ($lines as $line) {
            if (! $line instanceof PurchaseReceiptLineData) {
                throw new DomainException(
                    'Las líneas de recepción son inválidas.'
                );
            }

            if (isset($seen[$line->purchaseOrderLineId])) {
                throw new DomainException(
                    'Una línea de orden no puede repetirse dentro de la misma recepción.'
                );
            }

            $seen[$line->purchaseOrderLineId] = true;
            $orderLine = $orderLines->get(
                $line->purchaseOrderLineId
            );

            if (! $orderLine) {
                throw new DomainException(
                    'La recepción contiene una línea ajena a la orden.'
                );
            }

            $quantity = InventoryQuantity::positive(
                $line->quantity
            );
            InventoryQuantity::assertFitsScale(
                $quantity,
                (int) $orderLine->quantity_scale,
                'La cantidad recibida'
            );

            $alreadyReceived = $receivedByOrderLine[
                $line->purchaseOrderLineId
            ] ?? InventoryQuantity::signed('0');
            $remaining = InventoryQuantity::subtract(
                $orderLine->ordered_quantity,
                $alreadyReceived
            );

            if (
                ! InventoryQuantity::lessThanOrEqual(
                    $quantity,
                    $remaining
                )
            ) {
                throw new DomainException(
                    'La recepción supera la cantidad pendiente de la línea.'
                );
            }

            $location = InventoryLocation::query()
                ->whereKey($line->inventoryLocationId)
                ->where('organization_id', $organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $location) {
                throw new DomainException(
                    'La ubicación de destino no existe, está inactiva o pertenece a otra organización.'
                );
            }

            $unitCost = PurchaseMoney::nonNegative(
                $line->actualUnitCostMinor,
                'El costo unitario real'
            );

            $prepared[] = [
                'purchase_order_line_id' =>
                    (int) $orderLine->id,
                'catalog_product_id' =>
                    (int) $orderLine->catalog_product_id,
                'base_unit_code' =>
                    $orderLine->base_unit_code,
                'quantity_scale' =>
                    (int) $orderLine->quantity_scale,
                'inventory_location_id' =>
                    (int) $location->id,
                'condition' => $line->condition,
                'condition_value' =>
                    $line->condition->value,
                'received_quantity' => $quantity,
                'actual_unit_cost_minor' => $unitCost,
                'subtotal_minor' => PurchaseMoney::subtotal(
                    $quantity,
                    $unitCost
                ),
            ];
        }

        return $prepared;
    }

    /**
     * @param Collection<int, PurchaseOrderLine> $orderLines
     * @param Collection<int, PurchaseReceiptLine> $receiptLines
     */
    private function statusAfterReceipt(
        Collection $orderLines,
        Collection $receiptLines
    ): PurchaseOrderStatus {
        $received = [];

        foreach ($receiptLines as $line) {
            $lineId = (int) $line->purchase_order_line_id;
            $received[$lineId] = InventoryQuantity::add(
                $received[$lineId] ?? '0',
                $line->received_quantity
            );
        }

        $allComplete = true;

        foreach ($orderLines as $orderLine) {
            $quantity = $received[(int) $orderLine->id]
                ?? InventoryQuantity::signed('0');

            if (! InventoryQuantity::equal(
                $quantity,
                $orderLine->ordered_quantity
            )) {
                $allComplete = false;
            }
        }

        return $allComplete
            ? PurchaseOrderStatus::Received
            : PurchaseOrderStatus::PartiallyReceived;
    }
}
