<?php

namespace App\Domain\Commerce;

use App\Enums\CustomerAdvanceStatus;
use App\Models\CommercePostSaleExchangeCreditGrant;
use App\Models\CustomerAdvance;
use App\Models\CustomerCreditConsumptionAllocation;
use App\Models\CustomerCreditGrant;

final class CustomerCreditBalanceReader
{
    public function balanceMinor(
        int $organizationId,
        int $businessPartyId,
        string $currencyCode
    ): int {
        $currencyCode =
            strtoupper(trim($currencyCode));

        $standardGranted =
            (int) CustomerCreditGrant::query()
                ->forOrganization($organizationId)
                ->where(
                    'business_party_id',
                    $businessPartyId
                )
                ->where(
                    'currency_code',
                    $currencyCode
                )
                ->sum('amount_minor');

        $exchangeGranted =
            (int) CommercePostSaleExchangeCreditGrant::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->where(
                    'business_party_id',
                    $businessPartyId
                )
                ->where(
                    'currency_code',
                    $currencyCode
                )
                ->sum('amount_minor');

        $advanceGranted =
            (int) CustomerAdvance::query()
                ->forOrganization($organizationId)
                ->where(
                    'business_party_id',
                    $businessPartyId
                )
                ->where(
                    'currency_code',
                    $currencyCode
                )
                ->where(
                    'status',
                    CustomerAdvanceStatus::Confirmed->value
                )
                ->sum('amount_minor');

        $consumed =
            (int) CustomerCreditConsumptionAllocation::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->whereHas(
                    'consumption',
                    fn ($query) =>
                        $query
                            ->where(
                                'business_party_id',
                                $businessPartyId
                            )
                            ->where(
                                'currency_code',
                                $currencyCode
                            )
                )
                ->sum('amount_minor');

        return max(
            0,
            $standardGranted
            + $exchangeGranted
            + $advanceGranted
            - $consumed
        );
    }

    /**
     * @return array<int, array<string, int>>
     */
    public function matrixForOrganization(
        int $organizationId
    ): array {
        $matrix = [];

        $push = static function (
            array &$target,
            int $partyId,
            string $currencyCode,
            int $delta
        ): void {
            $target[$partyId] ??= [];
            $target[$partyId][$currencyCode] =
                ($target[$partyId][$currencyCode] ?? 0)
                + $delta;
        };

        foreach (
            CustomerCreditGrant::query()
                ->forOrganization($organizationId)
                ->get([
                    'business_party_id',
                    'currency_code',
                    'amount_minor',
                ])
            as $grant
        ) {
            $push(
                $matrix,
                (int) $grant->business_party_id,
                (string) $grant->currency_code,
                (int) $grant->amount_minor
            );
        }

        foreach (
            CommercePostSaleExchangeCreditGrant::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->get([
                    'business_party_id',
                    'currency_code',
                    'amount_minor',
                ])
            as $grant
        ) {
            $push(
                $matrix,
                (int) $grant->business_party_id,
                (string) $grant->currency_code,
                (int) $grant->amount_minor
            );
        }

        foreach (
            CustomerAdvance::query()
                ->forOrganization($organizationId)
                ->where(
                    'status',
                    CustomerAdvanceStatus::Confirmed->value
                )
                ->get([
                    'business_party_id',
                    'currency_code',
                    'amount_minor',
                ])
            as $advance
        ) {
            $push(
                $matrix,
                (int) $advance->business_party_id,
                (string) $advance->currency_code,
                (int) $advance->amount_minor
            );
        }

        foreach (
            CustomerCreditConsumptionAllocation::query()
                ->where(
                    'organization_id',
                    $organizationId
                )
                ->with(
                    'consumption:id,business_party_id,currency_code'
                )
                ->get()
            as $allocation
        ) {
            if (! $allocation->consumption) {
                continue;
            }

            $push(
                $matrix,
                (int) $allocation
                    ->consumption
                    ->business_party_id,
                (string) $allocation
                    ->consumption
                    ->currency_code,
                -((int) $allocation->amount_minor)
            );
        }

        foreach ($matrix as $partyId => $currencies) {
            foreach ($currencies as $currency => $amount) {
                $matrix[$partyId][$currency] =
                    max(0, (int) $amount);
            }
        }

        return $matrix;
    }
}