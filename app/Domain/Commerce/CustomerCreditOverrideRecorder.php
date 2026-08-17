<?php

namespace App\Domain\Commerce;

use App\Enums\CustomerCreditDecisionType;
use App\Models\CommerceSale;
use App\Models\CustomerCreditOverride;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use JsonException;

final class CustomerCreditOverrideRecorder
{
    public function recordForSale(
        CommerceSale $sale,
        CustomerCreditDecision $decision,
        User $administrator
    ): CustomerCreditOverride {
        if (
            $decision->type
                !== CustomerCreditDecisionType::AdminOverride
            || blank($decision->overrideReason)
        ) {
            throw new DomainException(
                'La venta no posee una excepción de crédito registrable.'
            );
        }

        $amountMinor =
            $decision->projectedExposureMinor
            - $decision->exposureBeforeMinor;

        $fingerprint = $this->fingerprint([
            'sale_public_id' => $sale->public_id,
            'business_party_id' =>
                (int) $sale->customer_business_party_id,
            'policy_public_id' =>
                $decision->policy?->public_id,
            'currency_code' => $sale->currency_code,
            'amount_minor' => $amountMinor,
            'exposure_before_minor' =>
                $decision->exposureBeforeMinor,
            'projected_exposure_minor' =>
                $decision->projectedExposureMinor,
            'overdue_minor' =>
                $decision->overdueMinor,
            'oldest_days_overdue' =>
                $decision->oldestDaysOverdue,
            'limit_minor' => $decision->limitMinor,
            'over_limit' => $decision->overLimit,
            'overdue' => $decision->overdue,
            'snapshot_fingerprint' =>
                $decision->snapshotFingerprint,
            'reason' => $decision->overrideReason,
            'approved_by_user_id' =>
                $administrator->id,
        ]);

        $existing = CustomerCreditOverride::query()
            ->forOrganization($sale->organization_id)
            ->where('commerce_sale_id', $sale->id)
            ->first();

        if ($existing) {
            if (! hash_equals(
                $existing->fingerprint,
                $fingerprint
            )) {
                throw new DomainException(
                    'La venta ya posee otra excepción de crédito.'
                );
            }

            return $existing;
        }

        $now = CarbonImmutable::now();

        return CustomerCreditOverride::query()->create([
            'organization_id' => $sale->organization_id,
            'business_party_id' =>
                $sale->customer_business_party_id,
            'commerce_sale_id' => $sale->id,
            'customer_credit_policy_id' =>
                $decision->policy?->id,
            'currency_code' => $sale->currency_code,
            'amount_minor' => $amountMinor,
            'exposure_before_minor' =>
                $decision->exposureBeforeMinor,
            'projected_exposure_minor' =>
                $decision->projectedExposureMinor,
            'overdue_minor' =>
                $decision->overdueMinor,
            'oldest_days_overdue' =>
                $decision->oldestDaysOverdue,
            'limit_minor' => $decision->limitMinor,
            'over_limit' => $decision->overLimit,
            'overdue' => $decision->overdue,
            'snapshot_fingerprint' =>
                $decision->snapshotFingerprint,
            'reason' => $decision->overrideReason,
            'approved_by_user_id' =>
                $administrator->id,
            'approved_at' => $now,
            'fingerprint' => $fingerprint,
            'created_at' => $now,
        ])->refresh();
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
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
                'No pudo consolidarse la excepción de crédito.',
                previous: $exception
            );
        }
    }
}
