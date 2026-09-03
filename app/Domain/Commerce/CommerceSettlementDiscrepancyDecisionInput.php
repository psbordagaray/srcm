<?php

namespace App\Domain\Commerce;

use App\Domain\Numerics\NumericalDiscrepancyDecision;
use InvalidArgumentException;

final readonly class CommerceSettlementDiscrepancyDecisionInput
{
    public const FOUNDATION_VERSION = 1;

    public const SCHEMA =
        'straleon.commerce-settlement-discrepancy-decision-input.v1';

    public const AUTHORIZED_DECISION_VALUES = [
        'KEEP_REFERENCE',
    ];

    public const BLOCKED_DECISION_VALUES = [
        'ACCEPT_OBSERVED',
    ];

    public const REASON_MAX_BYTES = 2048;

    public const RUNTIME_WIRING_STATUS =
        'FOUNDATION_ONLY_NOT_RUNTIME_WIRED';

    private function __construct(
        public NumericalDiscrepancyDecision $decision,
        public string $reason,
    ) {
        if ($this->decision !== NumericalDiscrepancyDecision::KeepReference) {
            throw new InvalidArgumentException(
                'Commerce settlement decision input currently supports KEEP_REFERENCE only.'
            );
        }

        if (
            trim($this->reason) === ''
            || trim($this->reason) !== $this->reason
            || strlen($this->reason) > self::REASON_MAX_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $this->reason) === 1
        ) {
            throw new InvalidArgumentException(
                'Commerce settlement decision input reason must be explicit, bounded and free of control characters.'
            );
        }
    }

    public static function keepReference(string $reason): self
    {
        return new self(
            decision: NumericalDiscrepancyDecision::KeepReference,
            reason: $reason,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'decision' => $this->decision->value,
            'reason' => $this->reason,
            'explicit_decision' => true,
            'is_decision_evidence' => false,
            'requires_runtime_discrepancy_evidence' => false,
            'business_mutation_authorized' => false,
            'keep_reference_allows_sale_confirmation' => false,
            'override_authorization_required' => false,
            'persists_audit' => false,
            'request_runtime_wired' => false,
            'controller_runtime_wired' => false,
            'checkout_data_runtime_wired' => false,
            'manager_runtime_wired' => false,
        ];
    }
}
