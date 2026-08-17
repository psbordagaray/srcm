<?php

namespace App\Domain\Purchase;

use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierCreditApplication;
use App\Models\SupplierCreditNote;

final class SupplierCreditBalanceReader
{
    public function read(
        int $organizationId,
        int $supplierId,
        string $currencyCode
    ): array {
        $currencyCode = strtoupper(
            trim($currencyCode)
        );

        $notes = SupplierCreditNote::query()
            ->forOrganization($organizationId)
            ->where(
                'supplier_id',
                $supplierId
            )
            ->where(
                'currency_code',
                $currencyCode
            )
            ->with([
                'invoice:id,public_id,document_number,purchase_order_id',
            ])
            ->orderBy('issued_on')
            ->orderBy('id')
            ->get();

        $noteApplications =
            SupplierCreditApplication::query()
                ->forOrganization($organizationId)
                ->where(
                    'supplier_id',
                    $supplierId
                )
                ->where(
                    'currency_code',
                    $currencyCode
                )
                ->get([
                    'supplier_credit_note_id',
                    'amount_minor',
                ])
                ->groupBy(
                    'supplier_credit_note_id'
                );

        $advances = SupplierAdvance::query()
            ->forOrganization($organizationId)
            ->where(
                'supplier_id',
                $supplierId
            )
            ->where(
                'currency_code',
                $currencyCode
            )
            ->with([
                'originFinancialAccount:id,public_id,name,type',
            ])
            ->orderBy('executed_at')
            ->orderBy('id')
            ->get();

        $advanceApplications =
            SupplierAdvanceApplication::query()
                ->forOrganization($organizationId)
                ->where(
                    'supplier_id',
                    $supplierId
                )
                ->where(
                    'currency_code',
                    $currencyCode
                )
                ->get([
                    'supplier_advance_id',
                    'amount_minor',
                ])
                ->groupBy(
                    'supplier_advance_id'
                );

        $noteSourceMinor = 0;
        $noteAppliedMinor = 0;

        $noteRows = $notes
            ->map(function (
                SupplierCreditNote $note
            ) use (
                $noteApplications,
                &$noteSourceMinor,
                &$noteAppliedMinor
            ): array {
                $source =
                    (int) $note->amount_minor;
                $applied =
                    (int) $noteApplications
                        ->get(
                            $note->id,
                            collect()
                        )
                        ->sum('amount_minor');
                $available = max(
                    0,
                    $source - $applied
                );

                $noteSourceMinor += $source;
                $noteAppliedMinor += $applied;

                return [
                    'source_type' =>
                        'credit_note',
                    'id' => (int) $note->id,
                    'public_id' =>
                        (string) $note->public_id,
                    'document_number' =>
                        (string) $note
                            ->document_number,
                    'issued_on' =>
                        $note->issued_on
                            ->format('Y-m-d'),
                    'source_minor' =>
                        $source,
                    'applied_minor' =>
                        $applied,
                    'available_minor' =>
                        $available,
                    'supplier_invoice_id' =>
                        (int) $note
                            ->supplier_invoice_id,
                    'invoice_public_id' =>
                        (string) $note
                            ->invoice
                            ->public_id,
                    'invoice_document_number' =>
                        (string) $note
                            ->invoice
                            ->document_number,
                ];
            })
            ->values()
            ->all();

        $advanceSourceMinor = 0;
        $advanceAppliedMinor = 0;

        $advanceRows = $advances
            ->map(function (
                SupplierAdvance $advance
            ) use (
                $advanceApplications,
                &$advanceSourceMinor,
                &$advanceAppliedMinor
            ): array {
                $source =
                    (int) $advance->amount_minor;
                $applied =
                    (int) $advanceApplications
                        ->get(
                            $advance->id,
                            collect()
                        )
                        ->sum('amount_minor');
                $available = max(
                    0,
                    $source - $applied
                );

                $advanceSourceMinor += $source;
                $advanceAppliedMinor += $applied;

                return [
                    'source_type' =>
                        'advance',
                    'id' =>
                        (int) $advance->id,
                    'public_id' =>
                        (string) $advance
                            ->public_id,
                    'executed_at' =>
                        $advance->executed_at
                            ->toIso8601String(),
                    'channel' =>
                        (string) $advance
                            ->channel,
                    'source_minor' =>
                        $source,
                    'applied_minor' =>
                        $applied,
                    'available_minor' =>
                        $available,
                    'origin_financial_account_id' =>
                        (int) $advance
                            ->origin_financial_account_id,
                    'origin_financial_account_public_id' =>
                        (string) $advance
                            ->originFinancialAccount
                            ->public_id,
                    'origin_financial_account_name' =>
                        (string) $advance
                            ->originFinancialAccount
                            ->name,
                    'execution_reference' =>
                        $advance
                            ->execution_reference,
                ];
            })
            ->values()
            ->all();

        $sourceMinor =
            $noteSourceMinor
            + $advanceSourceMinor;
        $appliedMinor =
            $noteAppliedMinor
            + $advanceAppliedMinor;

        return [
            'supplier_id' => $supplierId,
            'currency_code' => $currencyCode,
            'note_source_minor' =>
                $noteSourceMinor,
            'advance_source_minor' =>
                $advanceSourceMinor,
            'source_minor' =>
                $sourceMinor,
            'note_applied_minor' =>
                $noteAppliedMinor,
            'advance_applied_minor' =>
                $advanceAppliedMinor,
            'applied_minor' =>
                $appliedMinor,
            'available_minor' => max(
                0,
                $sourceMinor - $appliedMinor
            ),
            'notes' => $noteRows,
            'advances' => $advanceRows,
        ];
    }
}
