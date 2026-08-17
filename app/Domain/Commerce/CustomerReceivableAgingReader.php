<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\Customer;
use App\Models\CustomerReceivable;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

final class CustomerReceivableAgingReader
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
        private readonly CustomerReceivableInstallmentScheduleReader $scheduleReader
    ) {
    }

    /**
     * @return array<string, string>
     */
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

    /**
     * One aggregate row per receivable, preserving the P9.3/P9.4
     * account and collection contract.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rowsForCustomer(
        Customer $customer,
        User $actor,
        ?CarbonImmutable $asOf = null
    ): Collection {
        $organizationId = $this->currentOrganization->id($actor);

        if ((int) $customer->organization_id !== $organizationId) {
            throw new DomainException(
                'El cliente no pertenece a la organización activa.'
            );
        }

        return $this->aggregateRows(
            $organizationId,
            (int) $customer->business_party_id,
            $asOf
        );
    }

    /**
     * One aggregate row per receivable.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rowsForOrganization(
        User $actor,
        ?CarbonImmutable $asOf = null
    ): Collection {
        return $this->aggregateRows(
            $this->currentOrganization->id($actor),
            null,
            $asOf
        );
    }

    /**
     * @return array{
     *     as_of: CarbonImmutable,
     *     bucket_labels: array<string, string>,
     *     totals: Collection<string, array<string, mixed>>,
     *     customers: Collection<int, array<string, mixed>>,
     *     receivables: Collection<int, array<string, mixed>>
     * }
     */
    public function report(
        User $actor,
        ?CarbonImmutable $asOf = null
    ): array {
        $asOf = ($asOf ?? CarbonImmutable::today())
            ->startOfDay();

        $openRows = $this->expandedRows(
            $this->currentOrganization->id($actor),
            null,
            $asOf
        )
            ->filter(
                fn (array $row): bool =>
                    $row['outstanding_minor'] > 0
            )
            ->values();

        $bucketKeys = [
            self::BUCKET_CURRENT,
            self::BUCKET_1_30,
            self::BUCKET_31_60,
            self::BUCKET_61_90,
            self::BUCKET_91_PLUS,
            self::BUCKET_UNDATED,
        ];

        $totals = $openRows
            ->groupBy(
                fn (array $row): string =>
                    $row['receivable']->currency_code
            )
            ->map(
                function (
                    Collection $currencyRows
                ) use ($bucketKeys): array {
                    $buckets = array_fill_keys(
                        $bucketKeys,
                        0
                    );

                    foreach ($currencyRows as $row) {
                        $buckets[$row['aging_bucket']]
                            += $row['outstanding_minor'];
                    }

                    return [
                        'outstanding_minor' =>
                            (int) $currencyRows->sum(
                                'outstanding_minor'
                            ),
                        'overdue_minor' =>
                            (int) $currencyRows
                                ->where('overdue', true)
                                ->sum('outstanding_minor'),
                        'receivable_count' =>
                            $currencyRows
                                ->pluck('receivable.id')
                                ->unique()
                                ->count(),
                        'installment_line_count' =>
                            $currencyRows->count(),
                        'buckets' => $buckets,
                    ];
                }
            );

        $customers = $openRows
            ->groupBy(
                fn (array $row): string =>
                    $row['receivable']->business_party_id
                    .'|'
                    .$row['receivable']->currency_code
            )
            ->map(
                function (
                    Collection $customerRows
                ): array {
                    $first = $customerRows->first();

                    return [
                        'party' => $first['party'],
                        'customer' => $first['customer'],
                        'currency_code' =>
                            $first['receivable']->currency_code,
                        'outstanding_minor' =>
                            (int) $customerRows->sum(
                                'outstanding_minor'
                            ),
                        'overdue_minor' =>
                            (int) $customerRows
                                ->where('overdue', true)
                                ->sum('outstanding_minor'),
                        'receivable_count' =>
                            $customerRows
                                ->pluck('receivable.id')
                                ->unique()
                                ->count(),
                        'installment_line_count' =>
                            $customerRows->count(),
                        'oldest_days_overdue' =>
                            (int) $customerRows->max(
                                fn (array $row): int =>
                                    $row['days_overdue'] ?? 0
                            ),
                    ];
                }
            )
            ->sort(
                static function (
                    array $left,
                    array $right
                ): int {
                    $byOverdue =
                        $right['overdue_minor']
                        <=> $left['overdue_minor'];

                    if ($byOverdue !== 0) {
                        return $byOverdue;
                    }

                    $byOutstanding =
                        $right['outstanding_minor']
                        <=> $left['outstanding_minor'];

                    if ($byOutstanding !== 0) {
                        return $byOutstanding;
                    }

                    return strcmp(
                        (string) $left['party']->name,
                        (string) $right['party']->name
                    );
                }
            )
            ->values();

        return [
            'as_of' => $asOf,
            'bucket_labels' => $this->bucketLabels(),
            'totals' => $totals,
            'customers' => $customers,
            'receivables' => $openRows,
        ];
    }

    public function outstandingMinor(
        CustomerReceivable $receivable
    ): int {
        return $this->scheduleReader
            ->outstandingMinor($receivable);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function aggregateRows(
        int $organizationId,
        ?int $businessPartyId,
        ?CarbonImmutable $asOf
    ): Collection {
        $asOf = ($asOf ?? CarbonImmutable::today())
            ->startOfDay();

        $receivables = $this->receivables(
            $organizationId,
            $businessPartyId
        );
        $schedules = $this->scheduleReader
            ->rowsForReceivables($receivables);

        return $receivables->map(
            function (
                CustomerReceivable $receivable
            ) use ($schedules, $asOf): array {
                /** @var Collection<int, array<string, mixed>> $lines */
                $lines = $schedules->get(
                    (int) $receivable->id,
                    collect()
                );

                $enriched = $lines->map(
                    fn (array $line): array =>
                        $this->enrichLine(
                            $receivable,
                            $line,
                            $asOf
                        )
                );

                $open = $enriched
                    ->where('outstanding_minor', '>', 0)
                    ->values();
                $overdue = $open
                    ->where('overdue', true)
                    ->values();

                $next = $open
                    ->sort(
                        static function (
                            array $left,
                            array $right
                        ): int {
                            $leftDue = $left['due_on'];
                            $rightDue = $right['due_on'];

                            if (
                                $leftDue === null
                                && $rightDue === null
                            ) {
                                return $left['sequence']
                                    <=> $right['sequence'];
                            }

                            if ($leftDue === null) {
                                return 1;
                            }

                            if ($rightDue === null) {
                                return -1;
                            }

                            $byDue =
                                $leftDue->getTimestamp()
                                <=> $rightDue->getTimestamp();

                            return $byDue !== 0
                                ? $byDue
                                : $left['sequence']
                                    <=> $right['sequence'];
                        }
                    )
                    ->first();

                if ($open->isEmpty()) {
                    $bucket = self::BUCKET_SETTLED;
                    $daysOverdue = 0;
                } elseif ($overdue->isNotEmpty()) {
                    $oldest = $overdue
                        ->sortBy('due_on')
                        ->first();
                    $bucket = $oldest['aging_bucket'];
                    $daysOverdue =
                        $oldest['days_overdue'];
                } else {
                    $bucket = $next['aging_bucket'];
                    $daysOverdue =
                        $next['days_overdue'];
                }

                return [
                    'receivable' => $receivable,
                    'sale' => $receivable->sale,
                    'party' => $receivable->customer,
                    'customer' =>
                        $receivable->customer?->customer,
                    'original_minor' =>
                        (int) $enriched->sum(
                            'original_minor'
                        ),
                    'collected_minor' =>
                        (int) $enriched->sum(
                            'collected_minor'
                        ),
                    'outstanding_minor' =>
                        (int) $open->sum(
                            'outstanding_minor'
                        ),
                    'overdue_minor' =>
                        (int) $overdue->sum(
                            'outstanding_minor'
                        ),
                    'overdue' =>
                        $overdue->isNotEmpty(),
                    'days_overdue' =>
                        $daysOverdue,
                    'aging_bucket' => $bucket,
                    'aging_label' =>
                        $this->bucketLabels()[$bucket],
                    'next_due_on' =>
                        $next['due_on'] ?? null,
                    'installment_count' =>
                        (int) (
                            $enriched
                                ->max('installment_count')
                            ?? 1
                        ),
                    'planned_installments' =>
                        (bool) $enriched
                            ->contains('planned', true),
                    'installments' => $enriched,
                ];
            }
        );
    }

    /**
     * One row per scheduled installment. Legacy receivables remain one
     * synthetic line, preserving the same total debt without duplication.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function expandedRows(
        int $organizationId,
        ?int $businessPartyId,
        CarbonImmutable $asOf
    ): Collection {
        $receivables = $this->receivables(
            $organizationId,
            $businessPartyId
        );
        $schedules = $this->scheduleReader
            ->rowsForReceivables($receivables);

        return $receivables
            ->flatMap(
                function (
                    CustomerReceivable $receivable
                ) use ($schedules, $asOf): Collection {
                    /** @var Collection<int, array<string, mixed>> $lines */
                    $lines = $schedules->get(
                        (int) $receivable->id,
                        collect()
                    );

                    return $lines->map(
                        function (
                            array $line
                        ) use (
                            $receivable,
                            $asOf
                        ): array {
                            $line = $this->enrichLine(
                                $receivable,
                                $line,
                                $asOf
                            );

                            return [
                                'receivable' => $receivable,
                                'sale' => $receivable->sale,
                                'party' =>
                                    $receivable->customer,
                                'customer' =>
                                    $receivable
                                        ->customer
                                        ?->customer,
                                ...$line,
                            ];
                        }
                    );
                }
            )
            ->values();
    }

    /**
     * @return Collection<int, CustomerReceivable>
     */
    private function receivables(
        int $organizationId,
        ?int $businessPartyId
    ): Collection {
        $query = CustomerReceivable::query()
            ->forOrganization($organizationId)
            ->with([
                'sale',
                'customer.customer',
                'installmentPlan',
                'installments',
            ])
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->orderBy('recognized_at')
            ->orderBy('id');

        if ($businessPartyId !== null) {
            $query->where(
                'business_party_id',
                $businessPartyId
            );
        }

        return $query->get();
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function enrichLine(
        CustomerReceivable $receivable,
        array $line,
        CarbonImmutable $asOf
    ): array {
        [
            $bucket,
            $daysOverdue,
        ] = $this->classify(
            $line['due_on'],
            (int) $line['outstanding_minor'],
            $asOf
        );

        return [
            ...$line,
            'receivable_public_id' =>
                $receivable->public_id,
            'overdue' =>
                $daysOverdue !== null
                && $daysOverdue > 0,
            'days_overdue' => $daysOverdue,
            'aging_bucket' => $bucket,
            'aging_label' =>
                $this->bucketLabels()[$bucket],
        ];
    }

    /**
     * @return array{0: string, 1: ?int}
     */
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

        $dueOn = $dueOn->startOfDay();

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
}
