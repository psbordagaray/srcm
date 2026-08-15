<?php

namespace App\Domain\Finance;

use App\Enums\FinancialAccountType;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementStatus;
use App\Models\CommercePayment;
use App\Models\FinancialExternalMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class FinancialReconciliationCenterReader
{
    private const PAYMENT_LIMIT = 100;
    private const CANDIDATE_QUERY_LIMIT = 50;
    private const CANDIDATE_DISPLAY_LIMIT = 5;
    private const WINDOW_DAYS = 7;

    /**
     * @return Collection<int, FinancialReconciliationCenterItem>
     */
    public function read(int $organizationId): Collection
    {
        return CommercePayment::query()
            ->forOrganization($organizationId)
            ->whereNotNull('financial_account_id')
            ->whereHas(
                'financialAccount',
                fn (Builder $query) => $query->whereNotIn(
                    'type',
                    [
                        FinancialAccountType::CashBox->value,
                        FinancialAccountType::CashReserve->value,
                    ]
                )
            )
            ->with([
                'sale',
                'financialAccount',
                'reconciliation.events',
            ])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit(self::PAYMENT_LIMIT)
            ->get()
            ->map(
                fn (CommercePayment $payment) =>
                    $this->item($organizationId, $payment)
            );
    }

    private function item(
        int $organizationId,
        CommercePayment $payment
    ): FinancialReconciliationCenterItem {
        $paidAt = CarbonImmutable::instance(
            $payment->paid_at ?? $payment->created_at
        )->utc();

        $latestEvent = $payment->reconciliation
            ?->events
            ?->sortByDesc('id')
            ?->first();

        return new FinancialReconciliationCenterItem(
            paymentId: $payment->getKey(),
            salePublicId: (string) $payment->sale->public_id,
            accountName: (string) $payment->financialAccount->name,
            accountType: $payment->financialAccount->type->label(),
            currencyCode: (string) $payment->sale->currency_code,
            paymentMethod: $payment->method->value,
            expectedGrossAmountMinor: (int) $payment->amount_minor,
            declaredExternalOperationId:
                filled($payment->external_operation_id)
                    ? (string) $payment->external_operation_id
                    : null,
            paidAt: $paidAt,
            reconciliationStatus:
                $latestEvent?->status->value ?? 'unreconciled',
            latestAllocatedGrossAmountMinor:
                $latestEvent
                    ? (int) $latestEvent->allocated_gross_amount_minor
                    : null,
            latestDifferenceMinor:
                $latestEvent
                    ? (int) $latestEvent->difference_minor
                    : null,
            candidates: $this->candidates(
                $organizationId,
                $payment,
                $paidAt
            )
        );
    }

    /**
     * @return list<FinancialReconciliationCandidate>
     */
    private function candidates(
        int $organizationId,
        CommercePayment $payment,
        CarbonImmutable $paidAt
    ): array {
        $windowStart = $paidAt->subDays(self::WINDOW_DAYS);
        $windowEnd = $paidAt->addDays(self::WINDOW_DAYS);

        $movements = FinancialExternalMovement::query()
            ->forOrganization($organizationId)
            ->where(
                'financial_account_id',
                $payment->financial_account_id
            )
            ->where(
                'direction',
                FinancialMovementDirection::Credit->value
            )
            ->where(
                'status',
                FinancialMovementStatus::Posted->value
            )
            ->where(
                'currency_code',
                $payment->sale->currency_code
            )
            ->whereBetween(
                'occurred_at',
                [$windowStart, $windowEnd]
            )
            ->whereNotExists(function ($query) use ($payment): void {
                $query->selectRaw('1')
                    ->from(
                        'payment_reconciliation_allocations as allocation'
                    )
                    ->join(
                        'payment_reconciliation_events as event',
                        'event.id',
                        '=',
                        'allocation.payment_reconciliation_event_id'
                    )
                    ->join(
                        'payment_reconciliations as reconciliation',
                        'reconciliation.id',
                        '=',
                        'event.payment_reconciliation_id'
                    )
                    ->whereColumn(
                        'allocation.financial_external_movement_id',
                        'financial_external_movements.id'
                    )
                    ->where(
                        'reconciliation.commerce_payment_id',
                        '<>',
                        $payment->getKey()
                    );
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::CANDIDATE_QUERY_LIMIT)
            ->get();

        $candidates = $movements
            ->map(
                fn (FinancialExternalMovement $movement) =>
                    $this->candidate($payment, $paidAt, $movement)
            )
            ->sort(function (
                FinancialReconciliationCandidate $left,
                FinancialReconciliationCandidate $right
            ): int {
                if ($left->orderingScore !== $right->orderingScore) {
                    return $right->orderingScore
                        <=> $left->orderingScore;
                }

                if (
                    abs($left->grossDifferenceMinor)
                        !== abs($right->grossDifferenceMinor)
                ) {
                    return abs($left->grossDifferenceMinor)
                        <=> abs($right->grossDifferenceMinor);
                }

                if (
                    $left->distanceSeconds
                        !== $right->distanceSeconds
                ) {
                    return $left->distanceSeconds
                        <=> $right->distanceSeconds;
                }

                return $right->movementId <=> $left->movementId;
            })
            ->values()
            ->take(self::CANDIDATE_DISPLAY_LIMIT)
            ->all();

        return $candidates;
    }

    private function candidate(
        CommercePayment $payment,
        CarbonImmutable $paidAt,
        FinancialExternalMovement $movement
    ): FinancialReconciliationCandidate {
        $occurredAt = CarbonImmutable::instance(
            $movement->occurred_at
        )->utc();

        $distanceSeconds = abs(
            $occurredAt->getTimestamp() - $paidAt->getTimestamp()
        );

        $evidence = [];
        $score = 0;

        if (
            filled($payment->external_operation_id)
            && filled($movement->external_operation_id)
            && hash_equals(
                (string) $payment->external_operation_id,
                (string) $movement->external_operation_id
            )
        ) {
            $evidence[] = 'external_operation_exact';
            $score += 100;
        }

        $grossDifference =
            (int) $movement->gross_amount_minor
            - (int) $payment->amount_minor;

        if ($grossDifference === 0) {
            $evidence[] = 'gross_exact';
            $score += 60;
        } else {
            $tolerance = max(
                100,
                (int) floor(
                    (int) $payment->amount_minor * 0.01
                )
            );

            if (abs($grossDifference) <= $tolerance) {
                $evidence[] = 'gross_within_one_percent';
                $score += 20;
            }
        }

        if ($distanceSeconds <= 300) {
            $evidence[] = 'time_within_five_minutes';
            $score += 30;
        } elseif ($distanceSeconds <= 3600) {
            $evidence[] = 'time_within_one_hour';
            $score += 20;
        } elseif ($distanceSeconds <= 86400) {
            $evidence[] = 'time_within_one_day';
            $score += 10;
        } else {
            $evidence[] = 'time_within_seven_days';
            $score += 2;
        }

        $level = match (true) {
            $score >= 150 => 'strong',
            $score >= 80 => 'medium',
            default => 'weak',
        };

        return new FinancialReconciliationCandidate(
            movementId: $movement->getKey(),
            movementPublicId: (string) $movement->public_id,
            source: $movement->source->value,
            sourceKey: (string) $movement->source_key,
            externalOperationId:
                filled($movement->external_operation_id)
                    ? (string) $movement->external_operation_id
                    : null,
            grossAmountMinor: (int) $movement->gross_amount_minor,
            netAmountMinor: (int) $movement->net_amount_minor,
            feeAmountMinor: (int) $movement->fee_amount_minor,
            withholdingAmountMinor:
                (int) $movement->withholding_amount_minor,
            grossDifferenceMinor: $grossDifference,
            occurredAt: $occurredAt,
            distanceSeconds: $distanceSeconds,
            evidenceCodes: $evidence,
            orderingScore: $score,
            evidenceLevel: $level
        );
    }
}
