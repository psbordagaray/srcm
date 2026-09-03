<?php

namespace App\Domain\Commerce;

use App\Domain\Numerics\NumericalDiscrepancyDecision;

final class CommerceSettlementDiscrepancyDecisionSemantics
{
    public const SCHEMA = 'straleon.commerce-settlement-discrepancy-decision-semantics.v1';

    public const REFERENCE_VALUE_ROLE = 'SYSTEM_DERIVED_SALE_TOTAL';

    public const OBSERVED_VALUE_ROLE = 'HUMAN_SETTLEMENT_TOTAL_PAYMENTS_PLUS_RECEIVABLE';

    public const CURRENT_MISMATCH_BEHAVIOR = 'HARD_FAIL_CLOSED';

    public const KEEP_REFERENCE_EFFECT = 'PRESERVE_SYSTEM_DERIVED_TOTAL_AND_REQUIRE_SETTLEMENT_REVIEW';

    public const ACCEPT_OBSERVED_EFFECT = 'NO_BUSINESS_MUTATION_TARGET_DEFINED';

    public const ACCEPT_OBSERVED_STATUS = 'BLOCKED';

    public const RUNTIME_WIRING_STATUS = 'FOUNDATION_ONLY_NOT_YET_WIRED';

    /**
     * Deterministic business semantics only.
     *
     * This map does not authorize runtime wiring or any mutation.
     *
     * @return array<string, array{
     *     status: string,
     *     effect: string,
     *     business_mutation_authorized: bool
     * }>
     */
    public static function decisionSemantics(): array
    {
        return [
            NumericalDiscrepancyDecision::KeepReference->value => [
                'status' => 'DEFINED',
                'effect' => self::KEEP_REFERENCE_EFFECT,
                'business_mutation_authorized' => false,
            ],
            NumericalDiscrepancyDecision::AcceptObserved->value => [
                'status' => self::ACCEPT_OBSERVED_STATUS,
                'effect' => self::ACCEPT_OBSERVED_EFFECT,
                'business_mutation_authorized' => false,
            ],
        ];
    }

    /** @return list<string> */
    public static function semanticallyDefinedDecisionValues(): array
    {
        return [
            NumericalDiscrepancyDecision::KeepReference->value,
        ];
    }

    /** @return list<string> */
    public static function blockedDecisionValues(): array
    {
        return [
            NumericalDiscrepancyDecision::AcceptObserved->value,
        ];
    }
}