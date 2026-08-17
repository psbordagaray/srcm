<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CustomerCollectionStatus;
use App\Models\Customer;
use App\Models\CustomerCollection;
use App\Models\CustomerReceivable;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

final class CustomerReceivableBalanceReader
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly CustomerReceivableAgingReader $agingReader
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

        $rows = $this->agingReader->rowsForCustomer(
            $customer,
            $actor
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
        return $this->agingReader->outstandingMinor(
            $receivable
        );
    }
}
