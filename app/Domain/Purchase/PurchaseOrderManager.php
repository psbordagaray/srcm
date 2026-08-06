<?php

namespace App\Domain\Purchase;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\InventoryQuantity;
use App\Enums\PurchaseOrderStatus;
use App\Models\CatalogProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderManager
{
    public function __construct(
        private readonly PurchaseActorGuard $actors,
        private readonly AuditRecorder $audit
    ) {
    }

    public function draft(
        PurchaseOrderDraftData $data,
        User $actor
    ): PurchaseOrder {
        return DB::transaction(function () use (
            $data,
            $actor
        ): PurchaseOrder {
            $organizationId = $this->actors->authorize(
                $actor,
                'draft'
            );
            $idempotencyKey = PurchasePayload::idempotencyKey(
                $data->idempotencyKey
            );
            $currencyCode = PurchasePayload::currencyCode(
                $data->currencyCode
            );
            $logisticsCost = PurchaseMoney::nonNegative(
                $data->expectedLogisticsCostMinor,
                'El costo logístico esperado'
            );
            $notes = PurchasePayload::optionalText(
                $data->notes,
                'Las notas de la orden',
                4000
            );

            $supplier = Supplier::query()
                ->whereKey($data->supplierId)
                ->where('organization_id', $organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $supplier) {
                throw new DomainException(
                    'El proveedor no existe, está inactivo o pertenece a otra organización.'
                );
            }

            $preparedLines = $this->prepareLines(
                $data->lines,
                $organizationId,
                (int) $supplier->id
            );

            $merchandiseSubtotal = 0;

            foreach ($preparedLines as $line) {
                $merchandiseSubtotal = PurchaseMoney::add(
                    $merchandiseSubtotal,
                    $line['subtotal_minor'],
                    'El subtotal de la orden'
                );
            }

            $fingerprint = PurchasePayload::fingerprint([
                'supplier_id' => (int) $supplier->id,
                'currency_code' => $currencyCode,
                'expected_logistics_cost_minor' => $logisticsCost,
                'notes' => $notes,
                'lines' => $preparedLines,
            ]);

            $existing = PurchaseOrder::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals(
                    (string) $existing->fingerprint,
                    $fingerprint
                )) {
                    throw new DomainException(
                        'La clave de idempotencia de la orden ya fue utilizada con otro contenido.'
                    );
                }

                return $existing->load('lines');
            }

            $order = PurchaseOrder::query()->create([
                'organization_id' => $organizationId,
                'supplier_id' => $supplier->id,
                'status' => PurchaseOrderStatus::Draft,
                'currency_code' => $currencyCode,
                'expected_logistics_cost_minor' => $logisticsCost,
                'merchandise_subtotal_minor' =>
                    $merchandiseSubtotal,
                'expected_total_minor' => PurchaseMoney::add(
                    $merchandiseSubtotal,
                    $logisticsCost,
                    'El total esperado'
                ),
                'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'created_by_user_id' => $actor->id,
            ]);

            foreach ($preparedLines as $index => $line) {
                PurchaseOrderLine::query()->create([
                    'organization_id' => $organizationId,
                    'purchase_order_id' => $order->id,
                    'sequence' => $index + 1,
                    'supplier_id' => $supplier->id,
                    ...$line,
                ]);
            }

            $this->audit->record(
                $order,
                'purchase_order.drafted',
                null,
                [
                    'public_id' => $order->public_id,
                    'supplier_id' => $order->supplier_id,
                    'currency_code' => $order->currency_code,
                    'expected_total_minor' =>
                        $order->expected_total_minor,
                    'line_count' => count($preparedLines),
                ]
            );

            return $order->refresh()->load('lines');
        }, 3);
    }

    public function revise(
        PurchaseOrder|int $order,
        PurchaseOrderDraftData $data,
        User $actor
    ): PurchaseOrder {
        $orderId = $order instanceof PurchaseOrder
            ? (int) $order->getKey()
            : $order;

        return DB::transaction(function () use (
            $orderId,
            $data,
            $actor
        ): PurchaseOrder {
            $organizationId = $this->actors->authorize(
                $actor,
                'draft'
            );
            $locked = PurchaseOrder::query()
                ->whereKey($orderId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La orden no existe en la organización activa.'
                );
            }

            if ($locked->status !== PurchaseOrderStatus::Draft) {
                throw new DomainException(
                    'Solo una orden borrador puede revisarse.'
                );
            }

            $idempotencyKey = PurchasePayload::idempotencyKey(
                $data->idempotencyKey
            );

            if (! hash_equals(
                (string) $locked->idempotency_key,
                $idempotencyKey
            )) {
                throw new DomainException(
                    'La revisión debe conservar la clave de idempotencia de la orden.'
                );
            }

            $supplier = Supplier::query()
                ->whereKey($data->supplierId)
                ->where('organization_id', $organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $supplier) {
                throw new DomainException(
                    'El proveedor no existe, está inactivo o pertenece a otra organización.'
                );
            }

            $currencyCode = PurchasePayload::currencyCode(
                $data->currencyCode
            );
            $logisticsCost = PurchaseMoney::nonNegative(
                $data->expectedLogisticsCostMinor,
                'El costo logístico esperado'
            );
            $notes = PurchasePayload::optionalText(
                $data->notes,
                'Las notas de la orden',
                4000
            );
            $preparedLines = $this->prepareLines(
                $data->lines,
                $organizationId,
                (int) $supplier->id
            );
            $merchandiseSubtotal = 0;

            foreach ($preparedLines as $line) {
                $merchandiseSubtotal = PurchaseMoney::add(
                    $merchandiseSubtotal,
                    $line['subtotal_minor'],
                    'El subtotal de la orden'
                );
            }

            $fingerprint = PurchasePayload::fingerprint([
                'supplier_id' => (int) $supplier->id,
                'currency_code' => $currencyCode,
                'expected_logistics_cost_minor' => $logisticsCost,
                'notes' => $notes,
                'lines' => $preparedLines,
            ]);

            if (hash_equals(
                (string) $locked->fingerprint,
                $fingerprint
            )) {
                return $locked->load('lines');
            }

            $oldFingerprint = (string) $locked->fingerprint;

            PurchaseOrderLine::query()
                ->where('organization_id', $organizationId)
                ->where('purchase_order_id', $locked->id)
                ->delete();

            $locked->forceFill([
                'supplier_id' => $supplier->id,
                'currency_code' => $currencyCode,
                'expected_logistics_cost_minor' => $logisticsCost,
                'merchandise_subtotal_minor' =>
                    $merchandiseSubtotal,
                'expected_total_minor' => PurchaseMoney::add(
                    $merchandiseSubtotal,
                    $logisticsCost,
                    'El total esperado'
                ),
                'notes' => $notes,
                'fingerprint' => $fingerprint,
            ])->save();

            foreach ($preparedLines as $index => $line) {
                PurchaseOrderLine::query()->create([
                    'organization_id' => $organizationId,
                    'purchase_order_id' => $locked->id,
                    'sequence' => $index + 1,
                    'supplier_id' => $supplier->id,
                    ...$line,
                ]);
            }

            $this->audit->record(
                $locked,
                'purchase_order.revised',
                ['fingerprint' => $oldFingerprint],
                [
                    'fingerprint' => $fingerprint,
                    'supplier_id' => $locked->supplier_id,
                    'currency_code' => $locked->currency_code,
                    'expected_total_minor' =>
                        $locked->expected_total_minor,
                    'line_count' => count($preparedLines),
                ]
            );

            return $locked->refresh()->load('lines');
        }, 3);
    }

    public function issue(
        PurchaseOrder|int $order,
        User $actor
    ): PurchaseOrder {
        $orderId = $order instanceof PurchaseOrder
            ? (int) $order->getKey()
            : $order;

        return DB::transaction(function () use (
            $orderId,
            $actor
        ): PurchaseOrder {
            $organizationId = $this->actors->authorize(
                $actor,
                'issue'
            );
            $locked = PurchaseOrder::query()
                ->whereKey($orderId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La orden no existe en la organización activa.'
                );
            }

            if (in_array(
                $locked->status,
                [
                    PurchaseOrderStatus::Issued,
                    PurchaseOrderStatus::PartiallyReceived,
                    PurchaseOrderStatus::Received,
                ],
                true
            )) {
                return $locked->load(['lines', 'receipts.lines']);
            }

            if ($locked->status !== PurchaseOrderStatus::Draft) {
                throw new DomainException(
                    'Solo una orden borrador puede emitirse.'
                );
            }

            $supplier = Supplier::query()
                ->whereKey($locked->supplier_id)
                ->where('organization_id', $organizationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $supplier) {
                throw new DomainException(
                    'El proveedor dejó de estar activo antes de emitir la orden.'
                );
            }

            $lines = PurchaseOrderLine::query()
                ->where('organization_id', $organizationId)
                ->where('purchase_order_id', $locked->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw new DomainException(
                    'La orden requiere al menos una línea para emitirse.'
                );
            }

            $this->assertIssuableLines(
                $lines,
                $organizationId,
                (int) $supplier->id
            );

            $merchandiseSubtotal = 0;

            foreach ($lines as $line) {
                $merchandiseSubtotal = PurchaseMoney::add(
                    $merchandiseSubtotal,
                    (int) $line->subtotal_minor,
                    'El subtotal de la orden'
                );
            }

            $oldStatus = $locked->status;
            $locked->forceFill([
                'status' => PurchaseOrderStatus::Issued,
                'merchandise_subtotal_minor' =>
                    $merchandiseSubtotal,
                'expected_total_minor' => PurchaseMoney::add(
                    $merchandiseSubtotal,
                    (int) $locked->expected_logistics_cost_minor,
                    'El total esperado'
                ),
                'fingerprint' => $this->fingerprintFromPersisted(
                    $locked,
                    $lines
                ),
                'issued_by_user_id' => $actor->id,
                'issued_at' => now(),
            ])->save();

            $this->audit->record(
                $locked,
                'purchase_order.issued',
                ['status' => $oldStatus],
                [
                    'status' => $locked->status,
                    'issued_by_user_id' => $actor->id,
                    'issued_at' => $locked->issued_at,
                    'expected_total_minor' =>
                        $locked->expected_total_minor,
                ]
            );

            return $locked->refresh()->load('lines');
        }, 3);
    }

    public function cancel(
        PurchaseOrder|int $order,
        string $reason,
        User $actor
    ): PurchaseOrder {
        $orderId = $order instanceof PurchaseOrder
            ? (int) $order->getKey()
            : $order;
        $reason = PurchasePayload::requiredText(
            $reason,
            'El motivo de cancelación',
            1000
        );

        return DB::transaction(function () use (
            $orderId,
            $reason,
            $actor
        ): PurchaseOrder {
            $organizationId = $this->actors->authorize(
                $actor,
                'cancel'
            );
            $locked = PurchaseOrder::query()
                ->whereKey($orderId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException(
                    'La orden no existe en la organización activa.'
                );
            }

            if ($locked->status === PurchaseOrderStatus::Cancelled) {
                if ($locked->cancellation_reason !== $reason) {
                    throw new DomainException(
                        'La orden ya fue cancelada con otro motivo.'
                    );
                }

                return $locked->load('lines');
            }

            if ($locked->status !== PurchaseOrderStatus::Issued) {
                throw new DomainException(
                    'Solo una orden emitida y todavía no recibida puede cancelarse.'
                );
            }

            $hasReceipt = $locked->receipts()
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id']);

            if ($hasReceipt) {
                throw new DomainException(
                    'Una orden con recepciones confirmadas no puede cancelarse.'
                );
            }

            $oldStatus = $locked->status;
            $locked->forceFill([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_by_user_id' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->record(
                $locked,
                'purchase_order.cancelled',
                ['status' => $oldStatus],
                [
                    'status' => $locked->status,
                    'cancelled_by_user_id' => $actor->id,
                    'cancelled_at' => $locked->cancelled_at,
                    'cancellation_reason' => $reason,
                ]
            );

            return $locked->refresh()->load('lines');
        }, 3);
    }

    /**
     * @param list<PurchaseOrderLineData> $lines
     * @return list<array<string, mixed>>
     */
    private function prepareLines(
        array $lines,
        int $organizationId,
        int $supplierId
    ): array {
        if ($lines === []) {
            throw new DomainException(
                'La orden requiere al menos una línea.'
            );
        }

        $prepared = [];

        foreach ($lines as $line) {
            if (! $line instanceof PurchaseOrderLineData) {
                throw new DomainException(
                    'Las líneas de la orden son inválidas.'
                );
            }

            $product = CatalogProduct::query()
                ->whereKey($line->catalogProductId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $product) {
                throw new DomainException(
                    'Una línea referencia un producto inexistente o inactivo.'
                );
            }

            $quantity = InventoryQuantity::positive(
                $line->quantity
            );
            InventoryQuantity::assertFitsScale(
                $quantity,
                (int) $product->quantity_scale,
                'La cantidad ordenada'
            );

            $offer = null;

            if ($line->supplierOfferId !== null) {
                $offer = SupplierOffer::query()
                    ->whereKey($line->supplierOfferId)
                    ->where('organization_id', $organizationId)
                    ->where('supplier_id', $supplierId)
                    ->where(
                        'catalog_product_id',
                        $product->id
                    )
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $offer) {
                    throw new DomainException(
                        'La oferta no está activa o no coincide con proveedor y producto.'
                    );
                }
            }

            $unitCost = PurchaseMoney::nonNegative(
                $line->unitCostMinor,
                'El costo unitario acordado'
            );
            $supplierCode = PurchasePayload::optionalText(
                $line->supplierCode
                    ?? $offer?->supplier_code,
                'El código del proveedor',
                255
            );
            $description = PurchasePayload::requiredText(
                $line->description
                    ?? $offer?->published_description
                    ?? $product->name,
                'La descripción comercial',
                1000
            );

            $prepared[] = [
                'catalog_product_id' => (int) $product->id,
                'supplier_offer_id' => $offer?->id,
                'supplier_code' => $supplierCode,
                'description' => $description,
                'base_unit_code' => $product->base_unit_code,
                'quantity_scale' =>
                    (int) $product->quantity_scale,
                'ordered_quantity' => $quantity,
                'unit_cost_minor' => $unitCost,
                'subtotal_minor' => PurchaseMoney::subtotal(
                    $quantity,
                    $unitCost
                ),
            ];
        }

        return $prepared;
    }

    /**
     * @param Collection<int, PurchaseOrderLine> $lines
     */
    private function assertIssuableLines(
        Collection $lines,
        int $organizationId,
        int $supplierId
    ): void {
        foreach ($lines as $line) {
            $product = CatalogProduct::query()
                ->whereKey($line->catalog_product_id)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (
                ! $product
                || $product->base_unit_code
                    !== $line->base_unit_code
                || (int) $product->quantity_scale
                    !== (int) $line->quantity_scale
            ) {
                throw new DomainException(
                    'Un producto de la orden dejó de ser válido antes de la emisión.'
                );
            }

            InventoryQuantity::assertFitsScale(
                $line->ordered_quantity,
                (int) $line->quantity_scale,
                'La cantidad ordenada'
            );

            if ($line->supplier_offer_id === null) {
                continue;
            }

            $offerExists = SupplierOffer::query()
                ->whereKey($line->supplier_offer_id)
                ->where('organization_id', $organizationId)
                ->where('supplier_id', $supplierId)
                ->where(
                    'catalog_product_id',
                    $line->catalog_product_id
                )
                ->where('active', true)
                ->lockForUpdate()
                ->exists();

            if (! $offerExists) {
                throw new DomainException(
                    'Una oferta vinculada dejó de ser válida antes de la emisión.'
                );
            }
        }
    }

    /**
     * @param Collection<int, PurchaseOrderLine> $lines
     */
    private function fingerprintFromPersisted(
        PurchaseOrder $order,
        Collection $lines
    ): string {
        return PurchasePayload::fingerprint([
            'supplier_id' => (int) $order->supplier_id,
            'currency_code' => $order->currency_code,
            'expected_logistics_cost_minor' =>
                (int) $order->expected_logistics_cost_minor,
            'notes' => $order->notes,
            'lines' => $lines->map(
                fn (PurchaseOrderLine $line): array => [
                    'catalog_product_id' =>
                        (int) $line->catalog_product_id,
                    'supplier_offer_id' =>
                        $line->supplier_offer_id === null
                            ? null
                            : (int) $line->supplier_offer_id,
                    'supplier_code' => $line->supplier_code,
                    'description' => $line->description,
                    'base_unit_code' => $line->base_unit_code,
                    'quantity_scale' =>
                        (int) $line->quantity_scale,
                    'ordered_quantity' =>
                        (string) $line->ordered_quantity,
                    'unit_cost_minor' =>
                        (int) $line->unit_cost_minor,
                    'subtotal_minor' =>
                        (int) $line->subtotal_minor,
                ]
            )->all(),
        ]);
    }
}
