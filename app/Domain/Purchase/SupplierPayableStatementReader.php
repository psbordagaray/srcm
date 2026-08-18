<?php

namespace App\Domain\Purchase;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\PurchasePaymentDisbursementAllocation;
use App\Models\PurchasePaymentExecution;
use App\Models\Supplier;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierCreditApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

final class SupplierPayableStatementReader
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly SupplierPayableAgingReader $aging
    ) {
    }

    /**
     * @return array{
     *   as_of:CarbonImmutable,
     *   supplier:Supplier,
     *   obligations:Collection<int,array<string,mixed>>,
     *   entries:Collection<int,array<string,mixed>>,
     *   totals:Collection<string,array<string,int>>
     * }
     */
    public function read(
        Supplier $supplier,
        User $actor,
        ?CarbonImmutable $asOf = null
    ): array {
        $organizationId = $this->currentOrganization->id($actor);

        if ((int) $supplier->organization_id !== $organizationId) {
            throw new DomainException(
                'El proveedor no pertenece a la organización activa.'
            );
        }

        $asOf = ($asOf ?? CarbonImmutable::today())
            ->startOfDay();
        $rows = $this->aging->rowsForSupplier(
            $supplier,
            $actor,
            $asOf
        );
        $obligationIds = $rows
            ->pluck('obligation.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $entries = collect();

        foreach ($rows as $row) {
            $obligation = $row['obligation'];
            $entries->push([
                'type' => 'obligation',
                'label' => 'Obligación reconocida',
                'occurred_at' =>
                    $obligation->recognized_at,
                'sequence' => 0,
                'source_id' => (int) $obligation->id,
                'source' => $obligation,
                'obligation' => $obligation,
                'beneficiary' => $row['beneficiary'],
                'currency_code' => $row['currency_code'],
                'debit_minor' => $row['original_minor'],
                'credit_minor' => 0,
                'reference' => $obligation->public_id,
            ]);
        }

        if ($obligationIds !== []) {
            $this->legacyEntries(
                $entries,
                $organizationId,
                $obligationIds
            );
            $this->disbursementEntries(
                $entries,
                $organizationId,
                $obligationIds
            );
            $this->creditNoteEntries(
                $entries,
                $organizationId,
                $obligationIds
            );
            $this->advanceEntries(
                $entries,
                $organizationId,
                $obligationIds
            );
        }

        $entries = $entries
            ->sort(function (array $left, array $right): int {
                $occurred = $left['occurred_at']->getTimestamp()
                    <=> $right['occurred_at']->getTimestamp();

                if ($occurred !== 0) {
                    return $occurred;
                }

                $sequence = $left['sequence']
                    <=> $right['sequence'];

                return $sequence !== 0
                    ? $sequence
                    : $left['source_id']
                        <=> $right['source_id'];
            })
            ->values();
        $running = [];

        $entries = $entries->map(
            function (array $entry) use (&$running): array {
                $key = implode('|', [
                    $entry['beneficiary']->id,
                    $entry['currency_code'],
                ]);
                $running[$key] = ($running[$key] ?? 0)
                    + (int) $entry['debit_minor']
                    - (int) $entry['credit_minor'];

                return [
                    ...$entry,
                    'running_balance_minor' =>
                        max(0, (int) $running[$key]),
                ];
            }
        );

        $totals = $rows
            ->groupBy('currency_code')
            ->map(
                fn (Collection $currencyRows): array => [
                    'original_minor' =>
                        (int) $currencyRows->sum(
                            'original_minor'
                        ),
                    'legacy_execution_minor' =>
                        (int) $currencyRows->sum(
                            'legacy_execution_minor'
                        ),
                    'disbursement_execution_minor' =>
                        (int) $currencyRows->sum(
                            'disbursement_execution_minor'
                        ),
                    'supplier_credit_note_applied_minor' =>
                        (int) $currencyRows->sum(
                            'supplier_credit_note_applied_minor'
                        ),
                    'supplier_advance_applied_minor' =>
                        (int) $currencyRows->sum(
                            'supplier_advance_applied_minor'
                        ),
                    'settled_minor' =>
                        (int) $currencyRows->sum(
                            'settled_minor'
                        ),
                    'outstanding_minor' =>
                        (int) $currencyRows->sum(
                            'outstanding_minor'
                        ),
                    'overdue_minor' =>
                        (int) $currencyRows
                            ->where('overdue', true)
                            ->sum('outstanding_minor'),
                ]
            );

        return [
            'as_of' => $asOf,
            'supplier' => $supplier,
            'obligations' => $rows,
            'entries' => $entries,
            'totals' => $totals,
        ];
    }

    /** @param array<int,int> $obligationIds */
    private function legacyEntries(
        Collection $entries,
        int $organizationId,
        array $obligationIds
    ): void {
        PurchasePaymentExecution::query()
            ->forOrganization($organizationId)
            ->whereIn('purchase_obligation_id', $obligationIds)
            ->with(['obligation.beneficiary'])
            ->get()
            ->each(function (
                PurchasePaymentExecution $execution
            ) use ($entries): void {
                $entries->push([
                    'type' => 'legacy_execution',
                    'label' => 'Pago legacy imputado',
                    'occurred_at' => $execution->executed_at,
                    'sequence' => 1,
                    'source_id' => (int) $execution->id,
                    'source' => $execution,
                    'obligation' => $execution->obligation,
                    'beneficiary' =>
                        $execution->obligation->beneficiary,
                    'currency_code' =>
                        (string) $execution->currency_code,
                    'debit_minor' => 0,
                    'credit_minor' =>
                        (int) $execution->amount_minor,
                    'reference' =>
                        $execution->execution_reference
                        ?: $execution->public_id,
                ]);
            });
    }

    /** @param array<int,int> $obligationIds */
    private function disbursementEntries(
        Collection $entries,
        int $organizationId,
        array $obligationIds
    ): void {
        PurchasePaymentDisbursementAllocation::query()
            ->forOrganization($organizationId)
            ->whereIn('purchase_obligation_id', $obligationIds)
            ->with([
                'obligation.beneficiary',
                'disbursement',
            ])
            ->get()
            ->each(function (
                PurchasePaymentDisbursementAllocation $allocation
            ) use ($entries): void {
                $disbursement = $allocation->disbursement;
                $entries->push([
                    'type' => 'disbursement_allocation',
                    'label' => 'Desembolso imputado',
                    'occurred_at' => $disbursement->executed_at,
                    'sequence' => 2,
                    'source_id' => (int) $allocation->id,
                    'source' => $allocation,
                    'obligation' => $allocation->obligation,
                    'beneficiary' =>
                        $allocation->obligation->beneficiary,
                    'currency_code' =>
                        (string) $disbursement->currency_code,
                    'debit_minor' => 0,
                    'credit_minor' =>
                        (int) $allocation->amount_minor,
                    'reference' =>
                        $disbursement->execution_reference
                        ?: $disbursement->public_id,
                ]);
            });
    }

    /** @param array<int,int> $obligationIds */
    private function creditNoteEntries(
        Collection $entries,
        int $organizationId,
        array $obligationIds
    ): void {
        SupplierCreditApplication::query()
            ->forOrganization($organizationId)
            ->whereIn('purchase_obligation_id', $obligationIds)
            ->with([
                'obligation.beneficiary',
                'creditNote',
            ])
            ->get()
            ->each(function (
                SupplierCreditApplication $application
            ) use ($entries): void {
                $entries->push([
                    'type' => 'supplier_credit_application',
                    'label' => 'Nota de crédito aplicada',
                    'occurred_at' => $application->applied_at,
                    'sequence' => 3,
                    'source_id' => (int) $application->id,
                    'source' => $application,
                    'obligation' => $application->obligation,
                    'beneficiary' =>
                        $application->obligation->beneficiary,
                    'currency_code' =>
                        (string) $application->currency_code,
                    'debit_minor' => 0,
                    'credit_minor' =>
                        (int) $application->amount_minor,
                    'reference' =>
                        $application->creditNote->document_number,
                ]);
            });
    }

    /** @param array<int,int> $obligationIds */
    private function advanceEntries(
        Collection $entries,
        int $organizationId,
        array $obligationIds
    ): void {
        SupplierAdvanceApplication::query()
            ->forOrganization($organizationId)
            ->whereIn('purchase_obligation_id', $obligationIds)
            ->with([
                'obligation.beneficiary',
                'advance',
            ])
            ->get()
            ->each(function (
                SupplierAdvanceApplication $application
            ) use ($entries): void {
                $entries->push([
                    'type' => 'supplier_advance_application',
                    'label' => 'Anticipo aplicado',
                    'occurred_at' => $application->applied_at,
                    'sequence' => 4,
                    'source_id' => (int) $application->id,
                    'source' => $application,
                    'obligation' => $application->obligation,
                    'beneficiary' =>
                        $application->obligation->beneficiary,
                    'currency_code' =>
                        (string) $application->currency_code,
                    'debit_minor' => 0,
                    'credit_minor' =>
                        (int) $application->amount_minor,
                    'reference' =>
                        $application->advance->execution_reference
                        ?: $application->advance->public_id,
                ]);
            });
    }
}
