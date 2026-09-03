<?php

namespace App\Domain\Commerce;

use App\Domain\Numerics\NumericalDiscrepancyDecision;
use App\Domain\Numerics\NumericalDiscrepancyDecisionEvidence;
use InvalidArgumentException;

final readonly class CommerceSettlementDiscrepancyDecisionEvidence
{
    public const FOUNDATION_VERSION = 1;

    public const SCHEMA =
        'straleon.commerce-settlement-discrepancy-decision-evidence.v1';

    public const WARNING_CODE =
        NumericalDiscrepancyDecisionEvidence::WARNING_CODE;

    public const AUTHORIZED_DECISION_VALUES = [
        'KEEP_REFERENCE',
    ];

    public const BLOCKED_DECISION_VALUES = [
        'ACCEPT_OBSERVED',
    ];

    public const RUNTIME_WIRING_STATUS =
        'FOUNDATION_ONLY_NOT_RUNTIME_WIRED';

    private function __construct(
        public CommerceSettlementDiscrepancyException $runtimeEvidence,
        public NumericalDiscrepancyDecision $decision,
        public int $finalValueMinor,
        public string $reason,
    ) {
        if ($this->decision !== NumericalDiscrepancyDecision::KeepReference) {
            throw new InvalidArgumentException(
                'Commerce settlement aggregate decision evidence currently supports KEEP_REFERENCE only.'
            );
        }

        if ($this->finalValueMinor !== $this->runtimeEvidence->systemTotalMinor) {
            throw new InvalidArgumentException(
                'Commerce settlement KEEP_REFERENCE final value must preserve the system-derived total exactly.'
            );
        }

        if (
            trim($this->reason) === ''
            || trim($this->reason) !== $this->reason
            || strlen($this->reason) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $this->reason) === 1
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement decision reason must be explicit, bounded and free of control characters.'
            );
        }
    }

    public static function keepReference(
        CommerceSettlementDiscrepancyException $runtimeEvidence,
        string $reason,
    ): self {
        return new self(
            runtimeEvidence: $runtimeEvidence,
            decision: NumericalDiscrepancyDecision::KeepReference,
            finalValueMinor: $runtimeEvidence->systemTotalMinor,
            reason: $reason,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $runtime = $this->runtimeEvidence->toArray();

        return [
            'schema' => self::SCHEMA,
            'warning_code' => self::WARNING_CODE,
            'runtime_evidence_schema' =>
                CommerceSettlementDiscrepancyException::SCHEMA,
            'reference_value_role' =>
                CommerceSettlementDiscrepancyDecisionSemantics::
                    REFERENCE_VALUE_ROLE,
            'observed_value_role' =>
                CommerceSettlementDiscrepancyDecisionSemantics::
                    OBSERVED_VALUE_ROLE,
            'reference_value_minor' =>
                $this->runtimeEvidence->systemTotalMinor,
            'original_value_minor' =>
                $this->runtimeEvidence->systemTotalMinor,
            'observed_value_minor' =>
                $this->runtimeEvidence->settledTotalMinor,
            'decision' => $this->decision->value,
            'final_value_minor' => $this->finalValueMinor,
            'reason' => $this->reason,
            'explicit_decision' => true,
            'observed_component_ids' =>
                $runtime['observed_component_ids'],
            'component_analyses' =>
                $runtime['component_analyses'],
            'missing_transport_evidence_component_ids' =>
                $runtime['missing_transport_evidence_component_ids'],
            'component_analyses_are_context_not_aggregate_proof' => true,
            'component_signal_priority_or_winner' => null,
            'aggregate_discrepancy_unresolved' => true,
            'settlement_review_required' => true,
            'generic_numerical_decision_evidence_created' => false,
            'automatic_correction' => false,
            'business_mutation_authorized' => false,
            'payment_rewrite_authorized' => false,
            'receivable_rewrite_authorized' => false,
            'system_total_rewrite_authorized' => false,
            'override_authorization_required' => false,
            'persists_audit' => false,
            'manager_runtime_wired' => false,
            'controller_runtime_wired' => false,
        ];
    }
}
