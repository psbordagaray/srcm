<?php

namespace App\Domain\Purchase;

use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentDisbursementAllocation;
use App\Models\PurchasePaymentExecution;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierCreditApplication;

final class PurchaseObligationBalanceReader
{
    public function read(PurchaseObligation $obligation): array
    {
        return $this->calculate($obligation, false);
    }

    public function locked(PurchaseObligation $obligation): array
    {
        return $this->calculate($obligation, true);
    }

    private function calculate(
        PurchaseObligation $obligation,
        bool $lock
    ): array {
        $organizationId =
            (int) $obligation->organization_id;

        $legacyExecutions =
            PurchasePaymentExecution::query()
                ->forOrganization($organizationId)
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                );

        $disbursementAllocations =
            PurchasePaymentDisbursementAllocation::query()
                ->forOrganization($organizationId)
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                );

        $noteApplications =
            SupplierCreditApplication::query()
                ->forOrganization($organizationId)
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                );

        $advanceApplications =
            SupplierAdvanceApplication::query()
                ->forOrganization($organizationId)
                ->where(
                    'purchase_obligation_id',
                    $obligation->id
                );

        if ($lock) {
            $legacyExecutions->lockForUpdate();
            $disbursementAllocations->lockForUpdate();
            $noteApplications->lockForUpdate();
            $advanceApplications->lockForUpdate();
        }

        $legacyExecutedMinor =
            (int) $legacyExecutions
                ->get(['amount_minor'])
                ->sum('amount_minor');

        $disbursementExecutedMinor =
            (int) $disbursementAllocations
                ->get(['amount_minor'])
                ->sum('amount_minor');

        $executedMinor =
            $legacyExecutedMinor
            + $disbursementExecutedMinor;

        $noteAppliedMinor =
            (int) $noteApplications
                ->get(['amount_minor'])
                ->sum('amount_minor');

        $advanceAppliedMinor =
            (int) $advanceApplications
                ->get(['amount_minor'])
                ->sum('amount_minor');

        $supplierCreditAppliedMinor =
            $noteAppliedMinor
            + $advanceAppliedMinor;

        $obligationMinor =
            (int) $obligation->amount_minor;

        $settledMinor =
            $executedMinor
            + $supplierCreditAppliedMinor;

        return [
            'obligation_minor' =>
                $obligationMinor,
            'legacy_execution_minor' =>
                $legacyExecutedMinor,
            'disbursement_execution_minor' =>
                $disbursementExecutedMinor,
            'executed_minor' =>
                $executedMinor,
            'supplier_credit_note_applied_minor' =>
                $noteAppliedMinor,
            'supplier_advance_applied_minor' =>
                $advanceAppliedMinor,
            'supplier_credit_applied_minor' =>
                $supplierCreditAppliedMinor,
            'settled_minor' =>
                $settledMinor,
            'remaining_minor' => max(
                0,
                $obligationMinor - $settledMinor
            ),
        ];
    }
}
