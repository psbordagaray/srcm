<?php

namespace App\Domain\Commerce;

use App\Domain\Numerics\NumericalDiscrepancyDecision;
use DomainException;
use InvalidArgumentException;

final class CommerceSettlementDiscrepancyDecisionException extends DomainException
{
    public const FOUNDATION_VERSION = 1;

    public const SCHEMA =
        'straleon.commerce-settlement-discrepancy-decision-runtime-evidence.v1';

    public const MESSAGE =
        CommerceSettlementDiscrepancyException::MESSAGE;

    public const RUNTIME_WIRING_STATUS =
        'MANAGER_DECISION_EVIDENCE_BINDING_WIRED_HARD_FAIL_PRESERVED';

    private function __construct(
        public readonly CommerceSettlementDiscrepancyException $runtimeEvidence,
        public readonly CommerceSettlementDiscrepancyDecisionEvidence $decisionEvidence,
    ) {
        parent::__construct(self::MESSAGE);

        if (
            $this->decisionEvidence->runtimeEvidence
                !== $this->runtimeEvidence
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement decision runtime evidence must be bound to the exact discrepancy evidence object.'
            );
        }
    }

    public static function fromInput(
        CommerceSettlementDiscrepancyException $runtimeEvidence,
        CommerceSettlementDiscrepancyDecisionInput $input,
    ): self {
        if (
            $input->decision
                !== NumericalDiscrepancyDecision::KeepReference
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement runtime decision binding currently supports KEEP_REFERENCE only.'
            );
        }

        $decisionEvidence =
            CommerceSettlementDiscrepancyDecisionEvidence::keepReference(
                runtimeEvidence: $runtimeEvidence,
                reason: $input->reason,
            );

        return new self(
            runtimeEvidence: $runtimeEvidence,
            decisionEvidence: $decisionEvidence,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $runtime = $this->runtimeEvidence->toArray();
        $decision = $this->decisionEvidence->toArray();

        return [
            'schema' => self::SCHEMA,
            'message' => self::MESSAGE,
            'runtime_wiring_status' => self::RUNTIME_WIRING_STATUS,
            'runtime_evidence_schema' =>
                CommerceSettlementDiscrepancyException::SCHEMA,
            'decision_evidence_schema' =>
                CommerceSettlementDiscrepancyDecisionEvidence::SCHEMA,
            'reference_value_minor' =>
                $decision['reference_value_minor'],
            'observed_value_minor' =>
                $decision['observed_value_minor'],
            'decision' => $decision['decision'],
            'final_value_minor' =>
                $decision['final_value_minor'],
            'reason' => $decision['reason'],
            'runtime_evidence' => $runtime,
            'decision_evidence' => $decision,
            'aggregate_discrepancy_unresolved' => true,
            'settlement_review_required' => true,
            'hard_fail_preserved' => true,
            'sale_confirmation_authorized' => false,
            'automatic_correction' => false,
            'business_mutation_authorized' => false,
            'persists_audit' => false,
            'controller_special_handling' => false,
        ];
    }
}
