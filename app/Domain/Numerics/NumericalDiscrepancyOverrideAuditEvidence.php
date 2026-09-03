<?php

namespace App\Domain\Numerics;

final readonly class NumericalDiscrepancyOverrideAuditEvidence
{
    public const SCHEMA = 'straleon.numeric-discrepancy-override-audit-evidence.v1';

    public const WARNING_EVENT = 'numerical_discrepancy_warning';

    public const DECISION_EVENT = 'numerical_discrepancy_decision';

    public function __construct(
        public NumericalDiscrepancyDecisionEvidence $decisionEvidence,
        public NumericalDiscrepancyOverrideAuthorization $authorization,
    ) {
    }

    /** @return array{event: string, old_values: null, new_values: array<string, mixed>} */
    public function warningAuditRecord(): array
    {
        $decision = $this->decisionEvidence->toArray();

        return [
            'event' => self::WARNING_EVENT,
            'old_values' => null,
            'new_values' => [
                'schema' => self::SCHEMA,
                'decision_evidence_schema' => $decision['schema'],
                'warning_code' => $decision['warning_code'],
                'reference_value' => $decision['reference_value'],
                'original_value' => $decision['original_value'],
                'observed_value' => $decision['observed_value'],
                'signals' => $decision['signals'],
            ],
        ];
    }

    /**
     * @return array{
     *     event: string,
     *     old_values: array<string, mixed>,
     *     new_values: array<string, mixed>
     * }
     */
    public function decisionAuditRecord(): array
    {
        $decision = $this->decisionEvidence->toArray();
        $authorization = $this->authorization->toArray();

        return [
            'event' => self::DECISION_EVENT,
            'old_values' => [
                'schema' => self::SCHEMA,
                'decision_evidence_schema' => $decision['schema'],
                'warning_code' => $decision['warning_code'],
                'reference_value' => $decision['reference_value'],
                'original_value' => $decision['original_value'],
                'observed_value' => $decision['observed_value'],
            ],
            'new_values' => [
                'schema' => self::SCHEMA,
                'decision_evidence_schema' => $decision['schema'],
                'decision' => $decision['decision'],
                'final_value' => $decision['final_value'],
                'reason' => $decision['reason'],
                'explicit_decision' => true,
                'automatic_correction' => false,
                'authorization' => $authorization['authorization'],
                'authorization_fingerprint' => $authorization['authorization_fingerprint'],
            ],
        ];
    }
}