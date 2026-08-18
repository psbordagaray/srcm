<?php

namespace App\Domain\Purchase;

use App\Models\PurchaseObligation;
use App\Models\PurchasePaymentDisbursementAllocation;
use App\Models\PurchasePaymentExecution;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierCreditApplication;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PurchaseObligationBalanceReader
{
    public function read(PurchaseObligation $obligation): array
    {
        return $this->readMany(collect([$obligation]))
            ->get((int) $obligation->id);
    }

    public function locked(PurchaseObligation $obligation): array
    {
        return $this->calculateLocked($obligation);
    }

    /**
     * @param Collection<int,PurchaseObligation> $obligations
     * @return Collection<int,array<string,int>>
     */
    public function readMany(Collection $obligations): Collection
    {
        if ($obligations->isEmpty()) {
            return collect();
        }

        $organizationIds = $obligations
            ->map(function ($obligation): int {
                if (! $obligation instanceof PurchaseObligation) {
                    throw new DomainException(
                        'La proyección CxP sólo admite obligaciones válidas.'
                    );
                }

                return (int) $obligation->organization_id;
            })
            ->unique()
            ->values();

        if ($organizationIds->count() !== 1) {
            throw new DomainException(
                'La proyección CxP no puede mezclar organizaciones.'
            );
        }

        $organizationId = (int) $organizationIds->first();
        $obligationIds = $obligations
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $legacy = $this->sumByObligation(
            PurchasePaymentExecution::query()
                ->forOrganization($organizationId)
                ->whereIn('purchase_obligation_id', $obligationIds)
        );
        $disbursements = $this->sumByObligation(
            PurchasePaymentDisbursementAllocation::query()
                ->forOrganization($organizationId)
                ->whereIn('purchase_obligation_id', $obligationIds)
        );
        $notes = $this->sumByObligation(
            SupplierCreditApplication::query()
                ->forOrganization($organizationId)
                ->whereIn('purchase_obligation_id', $obligationIds)
        );
        $advances = $this->sumByObligation(
            SupplierAdvanceApplication::query()
                ->forOrganization($organizationId)
                ->whereIn('purchase_obligation_id', $obligationIds)
        );

        return $obligations->mapWithKeys(
            fn (PurchaseObligation $obligation): array => [
                (int) $obligation->id => $this->result(
                    (int) $obligation->amount_minor,
                    (int) $legacy->get($obligation->id, 0),
                    (int) $disbursements->get($obligation->id, 0),
                    (int) $notes->get($obligation->id, 0),
                    (int) $advances->get($obligation->id, 0)
                ),
            ]
        );
    }

    private function calculateLocked(
        PurchaseObligation $obligation
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

        $legacyExecutions->lockForUpdate();
        $disbursementAllocations->lockForUpdate();
        $noteApplications->lockForUpdate();
        $advanceApplications->lockForUpdate();

        $legacyExecutedMinor =
            (int) $legacyExecutions
                ->get(['amount_minor'])
                ->sum('amount_minor');

        $disbursementExecutedMinor =
            (int) $disbursementAllocations
                ->get(['amount_minor'])
                ->sum('amount_minor');

        $noteAppliedMinor =
            (int) $noteApplications
                ->get(['amount_minor'])
                ->sum('amount_minor');

        $advanceAppliedMinor =
            (int) $advanceApplications
                ->get(['amount_minor'])
                ->sum('amount_minor');

        return $this->result(
            (int) $obligation->amount_minor,
            $legacyExecutedMinor,
            $disbursementExecutedMinor,
            $noteAppliedMinor,
            $advanceAppliedMinor
        );
    }

    /** @return Collection<int,int> */
    private function sumByObligation(Builder $query): Collection
    {
        return $query
            ->selectRaw(
                'purchase_obligation_id, '
                .'SUM(amount_minor) AS aggregate'
            )
            ->groupBy('purchase_obligation_id')
            ->pluck('aggregate', 'purchase_obligation_id')
            ->map(fn ($amount): int => (int) $amount);
    }

    /** @return array<string,int> */
    private function result(
        int $obligationMinor,
        int $legacyExecutedMinor,
        int $disbursementExecutedMinor,
        int $noteAppliedMinor,
        int $advanceAppliedMinor
    ): array {
        $executedMinor =
            $legacyExecutedMinor
            + $disbursementExecutedMinor;
        $supplierCreditAppliedMinor =
            $noteAppliedMinor
            + $advanceAppliedMinor;
        $settledMinor =
            $executedMinor
            + $supplierCreditAppliedMinor;

        return [
            'obligation_minor' => $obligationMinor,
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
