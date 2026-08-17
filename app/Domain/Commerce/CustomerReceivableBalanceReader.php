<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CustomerCollectionStatus;
use App\Models\Customer;
use App\Models\CustomerCollection;
use App\Models\CustomerReceivable;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CustomerReceivableBalanceReader
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    /**
     * @return array{
     *     receivables: Collection<int, array<string, mixed>>,
     *     collections: Collection<int, CustomerCollection>,
     *     totals: Collection<string, array<string, int>>
     * }
     */
    public function read(Customer $customer, User $actor): array
    {
        $organizationId = $this->currentOrganization->id($actor);

        if ((int) $customer->organization_id !== $organizationId) {
            throw new DomainException(
                'El cliente no pertenece a la organización activa.'
            );
        }

        $receivables = CustomerReceivable::query()
            ->forOrganization($organizationId)
            ->where(
                'business_party_id',
                $customer->business_party_id
            )
            ->with('sale')
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->orderBy('recognized_at')
            ->orderBy('id')
            ->get();

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

        $today = CarbonImmutable::today();

        $rows = $receivables->map(
            function (CustomerReceivable $receivable) use (
                $collected,
                $today
            ): array {
                $collectedMinor = (int) (
                    $collected[$receivable->id] ?? 0
                );
                $outstandingMinor = max(
                    0,
                    $receivable->amount_minor - $collectedMinor
                );

                return [
                    'receivable' => $receivable,
                    'sale' => $receivable->sale,
                    'original_minor' => $receivable->amount_minor,
                    'collected_minor' => $collectedMinor,
                    'outstanding_minor' => $outstandingMinor,
                    'overdue' => $outstandingMinor > 0
                        && $receivable->due_on !== null
                        && $receivable->due_on
                            ->startOfDay()
                            ->lt($today),
                ];
            }
        );

        $totals = $rows
            ->groupBy(
                fn (array $row): string =>
                    $row['receivable']->currency_code
            )
            ->map(
                fn (Collection $currencyRows): array => [
                    'original_minor' => (int) $currencyRows->sum(
                        'original_minor'
                    ),
                    'collected_minor' => (int) $currencyRows->sum(
                        'collected_minor'
                    ),
                    'outstanding_minor' => (int) $currencyRows->sum(
                        'outstanding_minor'
                    ),
                ]
            );

        $collections = CustomerCollection::query()
            ->forOrganization($organizationId)
            ->where(
                'business_party_id',
                $customer->business_party_id
            )
            ->where(
                'status',
                CustomerCollectionStatus::Confirmed->value
            )
            ->with([
                'financialAccount',
                'receivedBy',
                'allocations.receivable.sale',
            ])
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return [
            'receivables' => $rows,
            'collections' => $collections,
            'totals' => $totals,
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
}
