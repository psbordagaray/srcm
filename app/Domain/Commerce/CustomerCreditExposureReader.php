<?php

namespace App\Domain\Commerce;

use App\Domain\Tenancy\CurrentOrganization;
use App\Models\BusinessParty;
use App\Models\Customer;
use App\Models\CustomerCreditPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use JsonException;

final class CustomerCreditExposureReader
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly CustomerReceivableAgingReader $agingReader
    ) {
    }

    /**
     * @return array{
     *     policy: ?CustomerCreditPolicy,
     *     policy_configured: bool,
     *     limit_minor: int,
     *     exposure_minor: int,
     *     overdue_minor: int,
     *     oldest_days_overdue: int,
     *     available_minor: int,
     *     snapshot_fingerprint: string
     * }
     */
    public function snapshot(
        Customer $customer,
        string $currencyCode,
        User $actor,
        ?CarbonImmutable $asOf = null
    ): array {
        $organizationId = $this->currentOrganization->id($actor);
        $currencyCode = strtoupper(trim($currencyCode));
        $asOf = ($asOf ?? CarbonImmutable::today())
            ->startOfDay();

        if (
            (int) $customer->organization_id !== $organizationId
            || preg_match(
                '/^[A-Z]{3}$/D',
                $currencyCode
            ) !== 1
        ) {
            throw new DomainException(
                'No puede derivarse la exposición de crédito solicitada.'
            );
        }

        $rows = $this->agingReader
            ->rowsForCustomer(
                $customer,
                $actor,
                $asOf
            )
            ->filter(
                fn (array $row): bool =>
                    $row['receivable']->currency_code
                        === $currencyCode
                    && $row['outstanding_minor'] > 0
            )
            ->values();

        $policy = CustomerCreditPolicy::query()
            ->forOrganization($organizationId)
            ->where(
                'business_party_id',
                $customer->business_party_id
            )
            ->where('currency_code', $currencyCode)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();

        $limitMinor = (int) ($policy?->limit_minor ?? 0);
        $exposureMinor = (int) $rows->sum(
            'outstanding_minor'
        );
        $overdueMinor = (int) $rows->sum(
            'overdue_minor'
        );
        $oldestDays = (int) $rows->max(
            fn (array $row): int =>
                $row['days_overdue'] ?? 0
        );

        return [
            'policy' => $policy,
            'policy_configured' => $policy !== null,
            'limit_minor' => $limitMinor,
            'exposure_minor' => $exposureMinor,
            'overdue_minor' => $overdueMinor,
            'oldest_days_overdue' => $oldestDays,
            'available_minor' => max(
                0,
                $limitMinor - $exposureMinor
            ),
            'snapshot_fingerprint' =>
                $this->snapshotFingerprint(
                    $customer,
                    $currencyCode,
                    $asOf,
                    $policy,
                    $rows
                ),
        ];
    }

    /**
     * @param Collection<int, BusinessParty> $parties
     * @param list<string> $currencies
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function matrixForParties(
        Collection $parties,
        User $actor,
        array $currencies = ['ARS', 'USD']
    ): array {
        $organizationId = $this->currentOrganization->id($actor);
        $matrix = [];

        $customers = Customer::query()
            ->forOrganization($organizationId)
            ->whereIn(
                'business_party_id',
                $parties->pluck('id')->all()
            )
            ->where('active', true)
            ->get()
            ->keyBy('business_party_id');

        foreach ($parties as $party) {
            /** @var Customer|null $customer */
            $customer = $customers->get($party->id);

            if (! $customer) {
                continue;
            }

            foreach ($currencies as $currency) {
                $snapshot = $this->snapshot(
                    $customer,
                    $currency,
                    $actor
                );

                $matrix[(int) $party->id][$currency] = [
                    'policy_configured' =>
                        $snapshot['policy_configured'],
                    'policy_public_id' =>
                        $snapshot['policy']?->public_id,
                    'policy_version' =>
                        $snapshot['policy']?->version,
                    'limit_minor' =>
                        $snapshot['limit_minor'],
                    'exposure_minor' =>
                        $snapshot['exposure_minor'],
                    'overdue_minor' =>
                        $snapshot['overdue_minor'],
                    'oldest_days_overdue' =>
                        $snapshot['oldest_days_overdue'],
                    'available_minor' =>
                        $snapshot['available_minor'],
                ];
            }
        }

        return $matrix;
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     */
    private function snapshotFingerprint(
        Customer $customer,
        string $currencyCode,
        CarbonImmutable $asOf,
        ?CustomerCreditPolicy $policy,
        Collection $rows
    ): string {
        $payload = [
            'organization_id' => (int) $customer->organization_id,
            'business_party_id' =>
                (int) $customer->business_party_id,
            'currency_code' => $currencyCode,
            'as_of' => $asOf->toDateString(),
            'policy' => $policy
                ? [
                    'id' => (int) $policy->id,
                    'public_id' => $policy->public_id,
                    'version' => (int) $policy->version,
                    'limit_minor' => (int) $policy->limit_minor,
                ]
                : null,
            'receivables' => $rows
                ->map(
                    fn (array $row): array => [
                        'id' => (int) $row['receivable']->id,
                        'public_id' =>
                            $row['receivable']->public_id,
                        'outstanding_minor' =>
                            (int) $row['outstanding_minor'],
                        'overdue_minor' =>
                            (int) $row['overdue_minor'],
                        'next_due_on' =>
                            $row['next_due_on']
                                ?->toDateString(),
                        'aging_bucket' =>
                            $row['aging_bucket'],
                        'installments' =>
                            $row['installments']
                                ->map(
                                    fn (array $line): array => [
                                        'sequence' =>
                                            $line['sequence'],
                                        'count' =>
                                            $line[
                                                'installment_count'
                                            ],
                                        'due_on' =>
                                            $line['due_on']
                                                ?->toDateString(),
                                        'outstanding_minor' =>
                                            $line[
                                                'outstanding_minor'
                                            ],
                                    ]
                                )
                                ->values()
                                ->all(),
                    ]
                )
                ->sortBy('id')
                ->values()
                ->all(),
        ];

        try {
            return hash(
                'sha256',
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'No pudo consolidarse la exposición de crédito.',
                previous: $exception
            );
        }
    }
}
