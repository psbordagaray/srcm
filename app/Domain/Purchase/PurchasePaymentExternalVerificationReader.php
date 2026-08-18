<?php

namespace App\Domain\Purchase;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementStatus;
use App\Enums\PurchasePaymentDisbursementChannel;
use App\Models\FinancialExternalMovement;
use App\Models\PurchasePaymentDisbursement;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;

final class PurchasePaymentExternalVerificationReader
{
    private const WINDOW_DAYS = 7;

    private const QUERY_LIMIT = 40;

    private const DISPLAY_LIMIT = 8;

    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    /**
     * @return list<array{
     *   movement_id:int,
     *   movement_public_id:string,
     *   source:string,
     *   source_key:string,
     *   external_operation_id:string|null,
     *   raw_reference:string|null,
     *   gross_amount_minor:int,
     *   net_amount_minor:int,
     *   fee_amount_minor:int,
     *   withholding_amount_minor:int,
     *   amount_difference_minor:int,
     *   occurred_at:CarbonImmutable,
     *   distance_seconds:int,
     *   reference_match_kind:string,
     *   evidence_codes:list<string>,
     *   evidence_level:string,
     *   ordering_score:int,
     *   note_required:bool
     * }>
     */
    public function candidates(
        PurchasePaymentDisbursement $disbursement,
        User $actor
    ): array {
        $organizationId = $this->organizationId($actor);

        $disbursement =
            PurchasePaymentDisbursement::query()
                ->forOrganization($organizationId)
                ->whereKey($disbursement->getKey())
                ->with('externalVerification')
                ->first();

        if (! $disbursement) {
            throw new DomainException(
                'El desembolso no pertenece a la organización activa.'
            );
        }

        if (
            $disbursement->channel
                !== PurchasePaymentDisbursementChannel::NonCash
            || $disbursement->externalVerification !== null
        ) {
            return [];
        }

        $executedAt = CarbonImmutable::instance(
            $disbursement->executed_at
        )->utc();

        $movements = FinancialExternalMovement::query()
            ->forOrganization($organizationId)
            ->where(
                'financial_account_id',
                $disbursement->origin_financial_account_id
            )
            ->where(
                'direction',
                FinancialMovementDirection::Debit->value
            )
            ->where(
                'status',
                FinancialMovementStatus::Posted->value
            )
            ->where(
                'currency_code',
                $disbursement->currency_code
            )
            ->whereBetween(
                'occurred_at',
                [
                    $executedAt->subDays(self::WINDOW_DAYS),
                    $executedAt->addDays(self::WINDOW_DAYS),
                ]
            )
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from(
                        'purchase_payment_external_verifications as verification'
                    )
                    ->whereColumn(
                        'verification.financial_external_movement_id',
                        'financial_external_movements.id'
                    );
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from(
                        'payment_reconciliation_allocations as allocation'
                    )
                    ->whereColumn(
                        'allocation.financial_external_movement_id',
                        'financial_external_movements.id'
                    );
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from(
                        'commerce_post_sale_external_refund_evidence as refund_evidence'
                    )
                    ->whereColumn(
                        'refund_evidence.financial_external_movement_id',
                        'financial_external_movements.id'
                    );
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::QUERY_LIMIT)
            ->get();

        return $movements
            ->map(
                fn (FinancialExternalMovement $movement): array =>
                    $this->candidate(
                        $disbursement,
                        $movement,
                        $executedAt
                    )
            )
            ->sort(function (array $left, array $right): int {
                if (
                    $left['ordering_score']
                        !== $right['ordering_score']
                ) {
                    return $right['ordering_score']
                        <=> $left['ordering_score'];
                }

                if (
                    abs($left['amount_difference_minor'])
                        !== abs($right['amount_difference_minor'])
                ) {
                    return abs($left['amount_difference_minor'])
                        <=> abs($right['amount_difference_minor']);
                }

                if (
                    $left['distance_seconds']
                        !== $right['distance_seconds']
                ) {
                    return $left['distance_seconds']
                        <=> $right['distance_seconds'];
                }

                return $right['movement_id']
                    <=> $left['movement_id'];
            })
            ->values()
            ->take(self::DISPLAY_LIMIT)
            ->all();
    }

    /** @return array<string,mixed> */
    private function candidate(
        PurchasePaymentDisbursement $disbursement,
        FinancialExternalMovement $movement,
        CarbonImmutable $executedAt
    ): array {
        $occurredAt = CarbonImmutable::instance(
            $movement->occurred_at
        )->utc();
        $distanceSeconds = abs(
            $occurredAt->getTimestamp()
                - $executedAt->getTimestamp()
        );
        $referenceMatchKind = $this->referenceMatchKind(
            (string) $disbursement->execution_reference,
            $movement
        );
        $difference =
            (int) $movement->gross_amount_minor
            - (int) $disbursement->amount_minor;
        $evidence = [];
        $score = 0;

        if ($referenceMatchKind !== 'operator_confirmed') {
            $evidence[] = 'reference_exact:'
                .$referenceMatchKind;
            $score += 100;
        }

        if ($difference === 0) {
            $evidence[] = 'gross_exact';
            $score += 60;
        } else {
            $tolerance = max(
                100,
                (int) floor(
                    (int) $disbursement->amount_minor
                    * 0.01
                )
            );

            if (abs($difference) <= $tolerance) {
                $evidence[] =
                    'gross_within_one_percent';
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

        if (
            (int) $movement->fee_amount_minor !== 0
            || (int) $movement
                ->withholding_amount_minor !== 0
        ) {
            $evidence[] = 'charges_require_review';
        }

        $level = match (true) {
            $score >= 150 => 'strong',
            $score >= 80 => 'medium',
            default => 'weak',
        };

        return [
            'movement_id' => (int) $movement->id,
            'movement_public_id' =>
                (string) $movement->public_id,
            'source' => $movement->source->value,
            'source_key' =>
                (string) $movement->source_key,
            'external_operation_id' =>
                filled($movement->external_operation_id)
                    ? (string) $movement
                        ->external_operation_id
                    : null,
            'raw_reference' =>
                filled($movement->raw_reference)
                    ? (string) $movement->raw_reference
                    : null,
            'gross_amount_minor' =>
                (int) $movement->gross_amount_minor,
            'net_amount_minor' =>
                (int) $movement->net_amount_minor,
            'fee_amount_minor' =>
                (int) $movement->fee_amount_minor,
            'withholding_amount_minor' =>
                (int) $movement
                    ->withholding_amount_minor,
            'amount_difference_minor' => $difference,
            'occurred_at' => $occurredAt,
            'distance_seconds' => $distanceSeconds,
            'reference_match_kind' =>
                $referenceMatchKind,
            'evidence_codes' => $evidence,
            'evidence_level' => $level,
            'ordering_score' => $score,
            'note_required' =>
                $referenceMatchKind
                    === 'operator_confirmed'
                || $difference !== 0
                || (int) $movement
                    ->fee_amount_minor !== 0
                || (int) $movement
                    ->withholding_amount_minor !== 0,
        ];
    }

    private function referenceMatchKind(
        string $reference,
        FinancialExternalMovement $movement
    ): string {
        $reference = trim($reference);

        foreach ([
            'external_operation_id' =>
                $movement->external_operation_id,
            'source_key' =>
                $movement->source_key,
            'raw_reference' =>
                $movement->raw_reference,
        ] as $kind => $candidate) {
            if (
                filled($candidate)
                && hash_equals(
                    $reference,
                    trim((string) $candidate)
                )
            ) {
                return $kind;
            }
        }

        return 'operator_confirmed';
    }

    private function organizationId(User $actor): int
    {
        $organizationId =
            $this->currentOrganization->id($actor);
        $role =
            $this->currentOrganization->roleFor($actor);

        if (! ($role?->canReviewFinancialReconciliation() ?? false)) {
            throw new DomainException(
                'No posee permiso para revisar evidencia financiera externa.'
            );
        }

        return $organizationId;
    }
}
