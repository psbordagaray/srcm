<?php

namespace App\Domain\Purchase;

use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentExecution;
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
        $executions = PurchasePaymentExecution::query()
            ->forOrganization((int) $obligation->organization_id)
            ->where('purchase_obligation_id', $obligation->id);

        $applications = SupplierCreditApplication::query()
            ->forOrganization((int) $obligation->organization_id)
            ->where('purchase_obligation_id', $obligation->id);

        if ($lock) {
            $executions->lockForUpdate();
            $applications->lockForUpdate();
        }

        $executedMinor = (int) $executions
            ->get(['amount_minor'])
            ->sum('amount_minor');
        $creditMinor = (int) $applications
            ->get(['amount_minor'])
            ->sum('amount_minor');

        $obligationMinor = (int) $obligation->amount_minor;
        $settledMinor = $executedMinor + $creditMinor;

        return [
            'obligation_minor' => $obligationMinor,
            'executed_minor' => $executedMinor,
            'supplier_credit_applied_minor' => $creditMinor,
            'settled_minor' => $settledMinor,
            'remaining_minor' => max(
                0,
                $obligationMinor - $settledMinor
            ),
        ];
    }
}
