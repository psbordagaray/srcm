<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CustomerCollectionStatus;
use App\Models\Customer;
use App\Models\CustomerReceivable;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        private readonly CurrentOrganization $currentOrganization
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

        return $this->rows(
            $organizationId,
            (int) $customer->business_party_id,
            $asOf
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
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

        $openRows = $this->rowsForOrganization(
            $actor,
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
        $collectedMinor = (int) DB::table(
            'customer_collection_allocations as allocation'
        )
            ->join(
                'customer_collections as collection',
                'collection.id',
                '=',
                'allocation.customer_collection_id'
            )
            ->where(
                'allocation.organization_id',
                $receivable->organization_id
            )
            ->where(
                'allocation.customer_receivable_id',
                $receivable->id
            )
            ->where(
                'collection.status',
                CustomerCollectionStatus::Confirmed->value
            )
            ->sum('allocation.amount_minor');

        return max(
            0,
            $receivable->amount_minor - $collectedMinor
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(
        int $organizationId,
        ?int $businessPartyId,
        ?CarbonImmutable $asOf
    ): Collection {
        $asOf = ($asOf ?? CarbonImmutable::today())
            ->startOfDay();

        $query = CustomerReceivable::query()
            ->forOrganization($organizationId)
            ->with([
                'sale',
                'customer.customer',
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

        $receivables = $query->get();

        $collected = DB::table(
            'customer_collection_allocations as allocation'
        )
            ->join(
                'customer_collections as collection',
                'collection.id',
                '=',
                'allocation.customer_collection_id'
            )
            ->where(
                'allocation.organization_id',
                $organizationId
            )
            ->where(
                'collection.organization_id',
                $organizationId
            )
            ->where(
                'collection.status',
                CustomerCollectionStatus::Confirmed->value
            )
            ->whereIn(
                'allocation.customer_receivable_id',
                $receivables->pluck('id')->all()
            )
            ->groupBy('allocation.customer_receivable_id')
            ->selectRaw(
                'allocation.customer_receivable_id, '
                .'SUM(allocation.amount_minor) AS collected_minor'
            )
            ->pluck(
                'collected_minor',
                'customer_receivable_id'
            );

        return $receivables->map(
            function (
                CustomerReceivable $receivable
            ) use ($collected, $asOf): array {
                $collectedMinor = (int) (
                    $collected[$receivable->id] ?? 0
                );

                $outstandingMinor = max(
                    0,
                    $receivable->amount_minor - $collectedMinor
                );

                [
                    $bucket,
                    $daysOverdue,
                ] = $this->classify(
                    $receivable,
                    $outstandingMinor,
                    $asOf
                );

                return [
                    'receivable' => $receivable,
                    'sale' => $receivable->sale,
                    'party' => $receivable->customer,
                    'customer' =>
                        $receivable->customer?->customer,
                    'original_minor' =>
                        $receivable->amount_minor,
                    'collected_minor' => $collectedMinor,
                    'outstanding_minor' => $outstandingMinor,
                    'overdue' => $daysOverdue !== null
                        && $daysOverdue > 0,
                    'days_overdue' => $daysOverdue,
                    'aging_bucket' => $bucket,
                    'aging_label' =>
                        $this->bucketLabels()[$bucket],
                ];
            }
        );
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    private function classify(
        CustomerReceivable $receivable,
        int $outstandingMinor,
        CarbonImmutable $asOf
    ): array {
        if ($outstandingMinor <= 0) {
            return [self::BUCKET_SETTLED, 0];
        }

        if ($receivable->due_on === null) {
            return [self::BUCKET_UNDATED, null];
        }

        $dueOn = $receivable->due_on->startOfDay();

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
