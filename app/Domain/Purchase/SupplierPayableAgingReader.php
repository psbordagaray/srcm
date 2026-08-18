<?php

namespace App\Domain\Purchase;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\PurchaseObligationCondition;
use App\Models\PurchaseObligation;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

final class SupplierPayableAgingReader
{
    public const BUCKET_CURRENT = 'current';
    public const BUCKET_1_30 = 'overdue_1_30';
    public const BUCKET_31_60 = 'overdue_31_60';
    public const BUCKET_61_90 = 'overdue_61_90';
    public const BUCKET_91_PLUS = 'overdue_91_plus';
    public const BUCKET_UNDATED = 'undated';
    public const BUCKET_SETTLED = 'settled';

    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly PurchaseObligationBalanceReader $balances
    ) {
    }

    /** @return array<string,string> */
    public function bucketLabels(): array
    {
        return [
            self::BUCKET_CURRENT => 'Al día',
            self::BUCKET_1_30 => 'Vencido 1–30 días',
            self::BUCKET_31_60 => 'Vencido 31–60 días',
            self::BUCKET_61_90 => 'Vencido 61–90 días',
            self::BUCKET_91_PLUS => 'Vencido 91+ días',
            self::BUCKET_UNDATED => 'Sin vencimiento',
            self::BUCKET_SETTLED => 'Cancelado',
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    public function rowsForSupplier(
        Supplier $supplier,
        User $actor,
        ?CarbonImmutable $asOf = null
    ): Collection {
        $organizationId = $this->currentOrganization->id($actor);

        if ((int) $supplier->organization_id !== $organizationId) {
            throw new DomainException(
                'El proveedor no pertenece a la organización activa.'
            );
        }

        return $this->rows(
            $organizationId,
            (int) $supplier->id,
            $asOf
        );
    }

    /** @return Collection<int,array<string,mixed>> */
    public function rowsForOrganization(
        User $actor,
        ?CarbonImmutable $asOf = null
    ): Collection {
        return $this->rows(
            $this->currentOrganization->id($actor),
            null,
            $asOf
        );
    }

    /**
     * @return array{
     *   as_of:CarbonImmutable,
     *   bucket_labels:array<string,string>,
     *   totals:Collection<string,array<string,mixed>>,
     *   suppliers:Collection<int,array<string,mixed>>,
     *   obligations:Collection<int,array<string,mixed>>
     * }
     */
    public function report(
        User $actor,
        ?CarbonImmutable $asOf = null
    ): array {
        $asOf = ($asOf ?? CarbonImmutable::today())
            ->startOfDay();
        $openRows = $this->rowsForOrganization(
            $actor,
            $asOf
        )
            ->where('outstanding_minor', '>', 0)
            ->values();
        $bucketKeys = $this->openBucketKeys();

        $totals = $openRows
            ->groupBy('currency_code')
            ->map(function (Collection $rows) use (
                $bucketKeys
            ): array {
                return [
                    'original_minor' =>
                        (int) $rows->sum('original_minor'),
                    'settled_minor' =>
                        (int) $rows->sum('settled_minor'),
                    'outstanding_minor' =>
                        (int) $rows->sum('outstanding_minor'),
                    'overdue_minor' =>
                        (int) $rows
                            ->where('overdue', true)
                            ->sum('outstanding_minor'),
                    'obligation_count' => $rows->count(),
                    'buckets' => $this->bucketTotals(
                        $rows,
                        $bucketKeys
                    ),
                ];
            });

        $suppliers = $openRows
            ->groupBy(
                fn (array $row): string => implode('|', [
                    $row['obligation']->supplier_id,
                    $row['obligation']
                        ->beneficiary_business_party_id,
                    $row['currency_code'],
                ])
            )
            ->map(function (Collection $rows) use (
                $bucketKeys
            ): array {
                $first = $rows->first();

                return [
                    'supplier' => $first['supplier'],
                    'supplier_party' =>
                        $first['supplier']->party,
                    'beneficiary' => $first['beneficiary'],
                    'currency_code' =>
                        $first['currency_code'],
                    'original_minor' =>
                        (int) $rows->sum('original_minor'),
                    'settled_minor' =>
                        (int) $rows->sum('settled_minor'),
                    'outstanding_minor' =>
                        (int) $rows->sum('outstanding_minor'),
                    'overdue_minor' =>
                        (int) $rows
                            ->where('overdue', true)
                            ->sum('outstanding_minor'),
                    'obligation_count' => $rows->count(),
                    'oldest_days_overdue' =>
                        (int) $rows->max(
                            fn (array $row): int =>
                                $row['days_overdue'] ?? 0
                        ),
                    'buckets' => $this->bucketTotals(
                        $rows,
                        $bucketKeys
                    ),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $overdue = $right['overdue_minor']
                    <=> $left['overdue_minor'];

                if ($overdue !== 0) {
                    return $overdue;
                }

                $outstanding = $right['outstanding_minor']
                    <=> $left['outstanding_minor'];

                if ($outstanding !== 0) {
                    return $outstanding;
                }

                return strcmp(
                    (string) $left['supplier_party']->name,
                    (string) $right['supplier_party']->name
                );
            })
            ->values();

        return [
            'as_of' => $asOf,
            'bucket_labels' => $this->bucketLabels(),
            'totals' => $totals,
            'suppliers' => $suppliers,
            'obligations' => $openRows,
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function rows(
        int $organizationId,
        ?int $supplierId,
        ?CarbonImmutable $asOf
    ): Collection {
        $asOf = ($asOf ?? CarbonImmutable::today())
            ->startOfDay();
        $query = PurchaseObligation::query()
            ->forOrganization($organizationId)
            ->with([
                'supplier.party:id,name,tax_id',
                'beneficiary:id,name,tax_id',
                'order:id,public_id',
                'receipt:id,public_id,received_at,document_reference',
            ])
            ->orderBy('recognized_at')
            ->orderBy('id');

        if ($supplierId !== null) {
            $query->where('supplier_id', $supplierId);
        }

        $obligations = $query->get();
        $balances = $this->balances->readMany($obligations);

        return $obligations
            ->map(function (
                PurchaseObligation $obligation
            ) use ($balances, $asOf): array {
                $balance = $balances->get(
                    (int) $obligation->id
                );
                [
                    $effectiveDueOn,
                    $dueSource,
                ] = $this->effectiveDue($obligation);
                [
                    $bucket,
                    $daysOverdue,
                ] = $this->classify(
                    $effectiveDueOn,
                    (int) $balance['remaining_minor'],
                    $asOf
                );

                return [
                    'obligation' => $obligation,
                    'supplier' => $obligation->supplier,
                    'beneficiary' => $obligation->beneficiary,
                    'order' => $obligation->order,
                    'receipt' => $obligation->receipt,
                    'currency_code' =>
                        (string) $obligation->currency_code,
                    'original_minor' =>
                        (int) $balance['obligation_minor'],
                    'legacy_execution_minor' =>
                        (int) $balance['legacy_execution_minor'],
                    'disbursement_execution_minor' =>
                        (int) $balance[
                            'disbursement_execution_minor'
                        ],
                    'supplier_credit_note_applied_minor' =>
                        (int) $balance[
                            'supplier_credit_note_applied_minor'
                        ],
                    'supplier_advance_applied_minor' =>
                        (int) $balance[
                            'supplier_advance_applied_minor'
                        ],
                    'settled_minor' =>
                        (int) $balance['settled_minor'],
                    'outstanding_minor' =>
                        (int) $balance['remaining_minor'],
                    'effective_due_on' => $effectiveDueOn,
                    'due_source' => $dueSource,
                    'overdue' =>
                        $daysOverdue !== null
                        && $daysOverdue > 0,
                    'days_overdue' => $daysOverdue,
                    'aging_bucket' => $bucket,
                    'aging_label' =>
                        $this->bucketLabels()[$bucket],
                ];
            })
            ->sort(function (array $left, array $right): int {
                $leftDue = $left['effective_due_on'];
                $rightDue = $right['effective_due_on'];

                if ($leftDue === null && $rightDue !== null) {
                    return 1;
                }

                if ($leftDue !== null && $rightDue === null) {
                    return -1;
                }

                if ($leftDue !== null && $rightDue !== null) {
                    $due = $leftDue->getTimestamp()
                        <=> $rightDue->getTimestamp();

                    if ($due !== 0) {
                        return $due;
                    }
                }

                return $left['obligation']->id
                    <=> $right['obligation']->id;
            })
            ->values();
    }

    /** @return array{0:?CarbonImmutable,1:string} */
    private function effectiveDue(
        PurchaseObligation $obligation
    ): array {
        return match ($obligation->payment_condition) {
            PurchaseObligationCondition::DueDate => [
                $obligation->due_on?->startOfDay(),
                'due_date',
            ],
            PurchaseObligationCondition::OnReceipt => [
                $obligation->receipt
                    ?->received_at
                    ?->toImmutable()
                    ?->startOfDay(),
                'on_receipt',
            ],
            PurchaseObligationCondition::Other => [
                null,
                'undated_other',
            ],
        };
    }

    /** @return array{0:string,1:?int} */
    private function classify(
        ?CarbonImmutable $dueOn,
        int $outstandingMinor,
        CarbonImmutable $asOf
    ): array {
        if ($outstandingMinor <= 0) {
            return [self::BUCKET_SETTLED, 0];
        }

        if ($dueOn === null) {
            return [self::BUCKET_UNDATED, null];
        }

        if ($dueOn->gte($asOf)) {
            return [self::BUCKET_CURRENT, 0];
        }

        $days = (int) $dueOn->diffInDays($asOf);

        if ($days <= 30) {
            return [self::BUCKET_1_30, $days];
        }

        if ($days <= 60) {
            return [self::BUCKET_31_60, $days];
        }

        if ($days <= 90) {
            return [self::BUCKET_61_90, $days];
        }

        return [self::BUCKET_91_PLUS, $days];
    }

    /** @return array<int,string> */
    private function openBucketKeys(): array
    {
        return [
            self::BUCKET_CURRENT,
            self::BUCKET_1_30,
            self::BUCKET_31_60,
            self::BUCKET_61_90,
            self::BUCKET_91_PLUS,
            self::BUCKET_UNDATED,
        ];
    }

    /**
     * @param Collection<int,array<string,mixed>> $rows
     * @param array<int,string> $bucketKeys
     * @return array<string,int>
     */
    private function bucketTotals(
        Collection $rows,
        array $bucketKeys
    ): array {
        $buckets = array_fill_keys($bucketKeys, 0);

        foreach ($rows as $row) {
            $buckets[$row['aging_bucket']]
                += (int) $row['outstanding_minor'];
        }

        return $buckets;
    }
}
