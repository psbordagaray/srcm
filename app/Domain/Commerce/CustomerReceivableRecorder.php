<?php

namespace App\Domain\Commerce;

use App\Enums\CommerceSaleStatus;
use App\Enums\CustomerCreditDecisionType;
use App\Models\CommerceSale;
use App\Models\CustomerCreditOverride;
use App\Models\CustomerReceivable;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use JsonException;

final class CustomerReceivableRecorder
{
    public function recordForSale(
        CommerceSale $sale,
        int $amountMinor,
        ?string $dueOn,
        CustomerCreditDecision $creditDecision,
        ?CustomerCreditOverride $creditOverride,
        User $actor
    ): CustomerReceivable {
        $organizationId = (int) $sale->organization_id;

        $lockedSale = CommerceSale::query()
            ->forOrganization($organizationId)
            ->whereKey($sale->id)
            ->lockForUpdate()
            ->first();

        if (
            ! $lockedSale
            || $lockedSale->status
                !== CommerceSaleStatus::Building
            || $lockedSale->customer_business_party_id === null
            || $amountMinor <= 0
            || $amountMinor > (int) $lockedSale->total_minor
        ) {
            throw new DomainException(
                'La venta no admite registrar ese saldo pendiente.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $membership
            || ! $membership->role
                ->canCreateCustomerReceivable()
        ) {
            throw new DomainException(
                'El usuario no puede registrar una venta con saldo pendiente.'
            );
        }

        if (
            $creditDecision->projectedExposureMinor
                !== $creditDecision->exposureBeforeMinor
                    + $amountMinor
            || (
                $creditDecision->type
                    === CustomerCreditDecisionType::LegacyAdmin
                && ! $membership->role
                    ->canOverrideCustomerCredit()
            )
            || (
                $creditDecision->type
                    === CustomerCreditDecisionType::WithinPolicy
                && (
                    ! $creditDecision->policy
                    || $creditDecision->limitMinor === null
                    || $creditDecision->overLimit
                    || $creditDecision->overdue
                    || $creditOverride !== null
                )
            )
            || (
                $creditDecision->type
                    === CustomerCreditDecisionType::AdminOverride
                && (
                    ! $membership->role
                        ->canOverrideCustomerCredit()
                    || ! $creditOverride
                    || (int) $creditOverride->commerce_sale_id
                        !== (int) $lockedSale->id
                    || (
                        $creditDecision->policy
                        && (int) $creditOverride
                            ->customer_credit_policy_id
                            !== (int) $creditDecision
                                ->policy->id
                    )
                    || (
                        ! $creditDecision->policy
                        && $creditOverride
                            ->customer_credit_policy_id
                            !== null
                    )
                )
            )
        ) {
            throw new DomainException(
                'La decisión de crédito no coincide con la venta pendiente.'
            );
        }

        $normalizedDueOn = $dueOn === null
            ? null
            : CarbonImmutable::parse($dueOn)
                ->toDateString();

        if (
            $normalizedDueOn !== null
            && CarbonImmutable::parse($normalizedDueOn)
                ->startOfDay()
                ->lt($lockedSale->sold_at->startOfDay())
        ) {
            throw new DomainException(
                'El vencimiento de la cuenta por cobrar no puede ser anterior a la venta.'
            );
        }

        $idempotencyKey = 'sale-receivable:'
            .$lockedSale->public_id;
        $fingerprint = $this->fingerprint([
            'sale_public_id' => $lockedSale->public_id,
            'business_party_id' =>
                (int) $lockedSale->customer_business_party_id,
            'currency_code' => $lockedSale->currency_code,
            'amount_minor' => $amountMinor,
            'due_on' => $normalizedDueOn,
            'credit_decision' =>
                $creditDecision->type->value,
            'credit_policy_public_id' =>
                $creditDecision->policy?->public_id,
            'credit_override_public_id' =>
                $creditOverride?->public_id,
            'credit_limit_minor' =>
                $creditDecision->limitMinor,
            'credit_exposure_before_minor' =>
                $creditDecision->exposureBeforeMinor,
            'credit_projected_exposure_minor' =>
                $creditDecision->projectedExposureMinor,
            'credit_overdue_minor' =>
                $creditDecision->overdueMinor,
            'credit_oldest_days_overdue' =>
                $creditDecision->oldestDaysOverdue,
            'credit_snapshot_fingerprint' =>
                $creditDecision->snapshotFingerprint,
        ]);

        $existing = CustomerReceivable::query()
            ->forOrganization($organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if (! hash_equals(
                $existing->fingerprint,
                $fingerprint
            )) {
                throw new DomainException(
                    'La cuenta por cobrar ya fue registrada con otros datos.'
                );
            }

            return $existing;
        }

        if (CustomerReceivable::query()
            ->forOrganization($organizationId)
            ->where('commerce_sale_id', $lockedSale->id)
            ->lockForUpdate()
            ->exists()
        ) {
            throw new DomainException(
                'La venta ya posee una cuenta por cobrar reconocida.'
            );
        }

        $now = CarbonImmutable::now();

        return CustomerReceivable::query()->create([
            'organization_id' => $organizationId,
            'business_party_id' =>
                $lockedSale->customer_business_party_id,
            'commerce_sale_id' => $lockedSale->id,
            'currency_code' => $lockedSale->currency_code,
            'amount_minor' => $amountMinor,
            'due_on' => $normalizedDueOn,
            'customer_credit_policy_id' =>
                $creditDecision->policy?->id,
            'customer_credit_override_id' =>
                $creditOverride?->id,
            'credit_decision' =>
                $creditDecision->type->value,
            'credit_limit_minor' =>
                $creditDecision->limitMinor,
            'credit_exposure_before_minor' =>
                $creditDecision->exposureBeforeMinor,
            'credit_projected_exposure_minor' =>
                $creditDecision->projectedExposureMinor,
            'credit_overdue_minor' =>
                $creditDecision->overdueMinor,
            'credit_oldest_days_overdue' =>
                $creditDecision->oldestDaysOverdue,
            'credit_snapshot_fingerprint' =>
                $creditDecision->snapshotFingerprint,
            'idempotency_key' => $idempotencyKey,
            'fingerprint' => $fingerprint,
            'recognized_by_user_id' => $actor->id,
            'recognized_at' => $now,
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
                'No se pudo consolidar la cuenta por cobrar.',
                previous: $exception
            );
        }
    }
}
