<?php

namespace App\Domain\Purchase;

use App\Models\SupplierCreditApplication;
use App\Models\SupplierCreditNote;

final class SupplierCreditBalanceReader
{
    public function read(
        int $organizationId,
        int $supplierId,
        string $currencyCode
    ): array {
        $currencyCode = strtoupper(trim($currencyCode));

        $notes = SupplierCreditNote::query()
            ->forOrganization($organizationId)
            ->where('supplier_id', $supplierId)
            ->where('currency_code', $currencyCode)
            ->with([
                'invoice:id,public_id,document_number,purchase_order_id',
            ])
            ->orderBy('issued_on')
            ->orderBy('id')
            ->get();

        $applications = SupplierCreditApplication::query()
            ->forOrganization($organizationId)
            ->where('supplier_id', $supplierId)
            ->where('currency_code', $currencyCode)
            ->get([
                'supplier_credit_note_id',
                'amount_minor',
            ])
            ->groupBy('supplier_credit_note_id');

        $sourceMinor = 0;
        $appliedMinor = 0;

        $rows = $notes
            ->map(function (SupplierCreditNote $note) use (
                $applications,
                &$sourceMinor,
                &$appliedMinor
            ): array {
                $source = (int) $note->amount_minor;
                $applied = (int) $applications
                    ->get($note->id, collect())
                    ->sum('amount_minor');
                $available = max(0, $source - $applied);

                $sourceMinor += $source;
                $appliedMinor += $applied;

                return [
                    'id' => (int) $note->id,
                    'public_id' => (string) $note->public_id,
                    'document_number' => (string) $note->document_number,
                    'issued_on' => $note->issued_on->format('Y-m-d'),
                    'source_minor' => $source,
                    'applied_minor' => $applied,
                    'available_minor' => $available,
                    'supplier_invoice_id' =>
                        (int) $note->supplier_invoice_id,
                    'invoice_public_id' =>
                        (string) $note->invoice->public_id,
                    'invoice_document_number' =>
                        (string) $note->invoice->document_number,
                ];
            })
            ->values()
            ->all();

        return [
            'supplier_id' => $supplierId,
            'currency_code' => $currencyCode,
            'source_minor' => $sourceMinor,
            'applied_minor' => $appliedMinor,
            'available_minor' => max(
                0,
                $sourceMinor - $appliedMinor
            ),
            'notes' => $rows,
        ];
    }
}
