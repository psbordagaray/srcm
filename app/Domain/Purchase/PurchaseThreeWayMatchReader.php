<?php

namespace App\Domain\Purchase;

use App\Domain\Inventory\InventoryQuantity;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierInvoiceLine;

final class PurchaseThreeWayMatchReader
{
    /**
     * @return array<string,mixed>
     */
    public function read(PurchaseOrder $order): array
    {
        $order->loadMissing([
            'supplier.party',
            'lines.product:id,sku,name,base_unit_code,quantity_scale',
            'lines.receiptLines:id,purchase_order_line_id,received_quantity,actual_unit_cost_minor,subtotal_minor',
            'supplierInvoices.lines:id,supplier_invoice_id,purchase_order_line_id,catalog_product_id,sequence,description,quantity,unit_cost_minor,subtotal_minor',
            'supplierInvoices.recordedBy:id,name',
        ]);

        $invoices = $order->supplierInvoices;
        $receiptLines = $order->lines
            ->flatMap(
                fn (PurchaseOrderLine $line) =>
                    $line->receiptLines
            );

        $documentLines = $invoices
            ->flatMap(
                fn ($invoice) => $invoice->lines
            );

        $lines = [];

        foreach ($order->lines as $orderLine) {
            $received = $receiptLines
                ->where(
                    'purchase_order_line_id',
                    $orderLine->id
                );
            $documented = $documentLines
                ->where(
                    'purchase_order_line_id',
                    $orderLine->id
                );

            $receivedQuantity = $this->sumQuantity(
                $received->pluck(
                    'received_quantity'
                )->all()
            );
            $documentedQuantity = $this->sumQuantity(
                $documented->pluck(
                    'quantity'
                )->all()
            );

            $receivedSubtotalMinor =
                (int) $received->sum(
                    'subtotal_minor'
                );
            $documentedSubtotalMinor =
                (int) $documented->sum(
                    'subtotal_minor'
                );

            $quantityExact =
                InventoryQuantity::equal(
                    $orderLine->ordered_quantity,
                    $receivedQuantity
                )
                && InventoryQuantity::equal(
                    $orderLine->ordered_quantity,
                    $documentedQuantity
                );

            $moneyExact =
                (int) $orderLine->subtotal_minor
                    === $receivedSubtotalMinor
                && (int) $orderLine->subtotal_minor
                    === $documentedSubtotalMinor;

            $lines[] = [
                'purchase_order_line_id' =>
                    (int) $orderLine->id,
                'sku' =>
                    (string) $orderLine->product->sku,
                'description' =>
                    (string) $orderLine->description,
                'base_unit_code' =>
                    (string) $orderLine->base_unit_code,
                'quantity_scale' =>
                    (int) $orderLine->quantity_scale,
                'ordered_quantity' =>
                    (string) $orderLine->ordered_quantity,
                'received_quantity' =>
                    $receivedQuantity,
                'documented_quantity' =>
                    $documentedQuantity,
                'order_subtotal_minor' =>
                    (int) $orderLine->subtotal_minor,
                'received_subtotal_minor' =>
                    $receivedSubtotalMinor,
                'documented_subtotal_minor' =>
                    $documentedSubtotalMinor,
                'quantity_order_receipt_delta' =>
                    InventoryQuantity::subtract(
                        $receivedQuantity,
                        $orderLine->ordered_quantity
                    ),
                'quantity_document_order_delta' =>
                    InventoryQuantity::subtract(
                        $documentedQuantity,
                        $orderLine->ordered_quantity
                    ),
                'quantity_document_receipt_delta' =>
                    InventoryQuantity::subtract(
                        $documentedQuantity,
                        $receivedQuantity
                    ),
                'money_receipt_order_delta_minor' =>
                    $receivedSubtotalMinor
                    - (int) $orderLine->subtotal_minor,
                'money_document_order_delta_minor' =>
                    $documentedSubtotalMinor
                    - (int) $orderLine->subtotal_minor,
                'money_document_receipt_delta_minor' =>
                    $documentedSubtotalMinor
                    - $receivedSubtotalMinor,
                'receipt_line_count' =>
                    $received->count(),
                'document_line_count' =>
                    $documented->count(),
                'quantity_exact' => $quantityExact,
                'money_exact' => $moneyExact,
                'exact' =>
                    $quantityExact && $moneyExact,
            ];
        }

        $unmatchedDocumentLines = $documentLines
            ->whereNull(
                'purchase_order_line_id'
            )
            ->values()
            ->map(
                fn (SupplierInvoiceLine $line):
                    array => [
                        'supplier_invoice_id' =>
                            (int) $line->supplier_invoice_id,
                        'sequence' =>
                            (int) $line->sequence,
                        'description' =>
                            (string) $line->description,
                        'quantity' =>
                            (string) $line->quantity,
                        'unit_cost_minor' =>
                            (int) $line->unit_cost_minor,
                        'subtotal_minor' =>
                            (int) $line->subtotal_minor,
                    ]
            )
            ->all();

        $receiptLogisticsMinor =
            (int) $order->receipts()
                ->sum('logistics_cost_minor');
        $receiptTotalMinor =
            (int) $order->receipts()
                ->sum('actual_total_minor');

        $documentLogisticsMinor =
            (int) $invoices->sum(
                'logistics_amount_minor'
            );
        $documentTotalMinor =
            (int) $invoices->sum(
                'total_minor'
            );

        $lineDifferenceCount = collect($lines)
            ->where('exact', false)
            ->count();

        $logisticsExact =
            (int) $order->expected_logistics_cost_minor
                === $receiptLogisticsMinor
            && (int) $order->expected_logistics_cost_minor
                === $documentLogisticsMinor;

        $totalExact =
            (int) $order->expected_total_minor
                === $receiptTotalMinor
            && (int) $order->expected_total_minor
                === $documentTotalMinor;

        $hasReceipts =
            $order->receipts()->exists();
        $hasDocuments =
            $invoices->isNotEmpty();

        $exact =
            $hasReceipts
            && $hasDocuments
            && $lineDifferenceCount === 0
            && $unmatchedDocumentLines === []
            && $logisticsExact
            && $totalExact;

        $status = match (true) {
            ! $hasDocuments => 'missing_document',
            ! $hasReceipts => 'pending_receipt',
            $exact => 'exact',
            default => 'different',
        };

        $statusLabel = match ($status) {
            'missing_document' =>
                'Falta documento del proveedor',
            'pending_receipt' =>
                'Documento registrado · recepción pendiente',
            'exact' =>
                'Coincidencia exacta',
            default =>
                'Diferencias explícitas',
        };

        return [
            'status' => $status,
            'status_label' => $statusLabel,
            'exact' => $exact,
            'has_receipts' => $hasReceipts,
            'has_documents' => $hasDocuments,
            'lines' => $lines,
            'unmatched_document_lines' =>
                $unmatchedDocumentLines,
            'documents' => $invoices
                ->map(
                    fn ($invoice): array => [
                        'id' => (int) $invoice->id,
                        'public_id' =>
                            (string) $invoice->public_id,
                        'document_number' =>
                            (string) $invoice->document_number,
                        'issued_on' =>
                            $invoice->issued_on
                                ->format('Y-m-d'),
                        'due_on' =>
                            $invoice->due_on?->format(
                                'Y-m-d'
                            ),
                        'currency_code' =>
                            (string) $invoice->currency_code,
                        'total_minor' =>
                            (int) $invoice->total_minor,
                        'recorded_at' =>
                            $invoice->recorded_at
                                ->format('Y-m-d H:i:s'),
                        'recorded_by' =>
                            (string) $invoice->recordedBy->name,
                    ]
                )
                ->values()
                ->all(),
            'summary' => [
                'currency_code' =>
                    (string) $order->currency_code,
                'order_total_minor' =>
                    (int) $order->expected_total_minor,
                'receipt_total_minor' =>
                    $receiptTotalMinor,
                'document_total_minor' =>
                    $documentTotalMinor,
                'order_logistics_minor' =>
                    (int) $order
                        ->expected_logistics_cost_minor,
                'receipt_logistics_minor' =>
                    $receiptLogisticsMinor,
                'document_logistics_minor' =>
                    $documentLogisticsMinor,
                'line_difference_count' =>
                    $lineDifferenceCount,
                'unmatched_document_line_count' =>
                    count($unmatchedDocumentLines),
                'logistics_exact' =>
                    $logisticsExact,
                'total_exact' =>
                    $totalExact,
                'document_count' =>
                    $invoices->count(),
                'receipt_count' =>
                    $order->receipts()->count(),
            ],
        ];
    }

    /**
     * @param list<mixed> $quantities
     */
    private function sumQuantity(
        array $quantities
    ): string {
        $total = InventoryQuantity::signed('0');

        foreach ($quantities as $quantity) {
            $total = InventoryQuantity::add(
                $total,
                $quantity
            );
        }

        return $total;
    }
}
