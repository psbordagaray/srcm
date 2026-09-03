<?php

namespace Tests\Feature\Numerics;

use App\Domain\Numerics\NumericalDiscrepancyAnalyzer;
use App\Domain\Numerics\NumericalDiscrepancyDecision;
use App\Domain\Numerics\NumericalDiscrepancyDecisionEvidence;
use App\Domain\Numerics\NumericalDiscrepancySignal;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class NumericalDiscrepancyDecisionEvidenceFoundationTest extends TestCase
{
    public function test_policy_defines_explicit_audit_ready_decision_evidence_without_runtime_wiring(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertSame(1, $policy['decision_evidence_foundation_version']);
        $this->assertSame(
            NumericalDiscrepancyDecisionEvidence::SCHEMA,
            $policy['decision_evidence_schema'],
        );
        $this->assertSame(
            NumericalDiscrepancyDecisionEvidence::class,
            $policy['decision_evidence_class'],
        );
        $this->assertSame(
            NumericalDiscrepancyDecision::values(),
            $policy['decision_values'],
        );
        $this->assertSame(
            NumericalDiscrepancyDecisionEvidence::WARNING_CODE,
            $policy['decision_warning_code'],
        );
        $this->assertTrue($policy['decision_requires_explicit_reason']);
        $this->assertTrue($policy['decision_requires_at_least_one_signal']);
        $this->assertTrue(
            $policy['decision_signals_must_match_reference_observed'],
        );
        $this->assertTrue($policy['decision_signal_rule_ids_must_be_unique']);
        $this->assertTrue($policy['decision_signal_order_is_deterministic']);
        $this->assertTrue(
            $policy['decision_original_value_equals_reference_value'],
        );
        $this->assertTrue(
            $policy['decision_final_value_must_match_explicit_choice'],
        );
        $this->assertFalse($policy['decision_normalization_allowed']);
        $this->assertFalse($policy['decision_silent_correction_allowed']);
        $this->assertSame(
            'foundation_only_not_yet_wired',
            $policy['decision_evidence_runtime_wiring_status'],
        );
        $this->assertTrue(
            $policy['decision_evidence_runtime_wiring_requires_separate_reviewed_cut'],
        );
        $this->assertSame(
            'not_in_foundation_cut',
            $policy['decision_capability_authorization_wiring_status'],
        );
        $this->assertSame(
            'not_in_foundation_cut',
            $policy['decision_audit_persistence_wiring_status'],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_decision_values_are_explicit_and_stable(): void
    {
        $this->assertSame([
            'KEEP_REFERENCE',
            'ACCEPT_OBSERVED',
        ], NumericalDiscrepancyDecision::values());
    }

    public function test_keep_reference_preserves_original_observed_signals_reason_and_final_value(): void
    {
        $signals = NumericalDiscrepancyAnalyzer::classifierPackV1()
            ->analyze('12345', '12435');

        $evidence = NumericalDiscrepancyDecisionEvidence::keepReference(
            referenceValue: '12345',
            observedValue: '12435',
            signals: $signals,
            reason: 'Reference value was independently verified.',
        );

        $payload = $evidence->toArray();

        $this->assertSame(
            NumericalDiscrepancyDecisionEvidence::SCHEMA,
            $payload['schema'],
        );
        $this->assertSame(
            NumericalDiscrepancyDecisionEvidence::WARNING_CODE,
            $payload['warning_code'],
        );
        $this->assertSame('12345', $payload['reference_value']);
        $this->assertSame('12345', $payload['original_value']);
        $this->assertSame('12435', $payload['observed_value']);
        $this->assertSame('KEEP_REFERENCE', $payload['decision']);
        $this->assertSame('12345', $payload['final_value']);
        $this->assertSame(
            'Reference value was independently verified.',
            $payload['reason'],
        );
        $this->assertTrue($payload['explicit_decision']);
        $this->assertFalse($payload['automatic_correction']);
        $this->assertCount(2, $payload['signals']);
        $this->assertSame(
            'numeric.transposition.adjacent.v1',
            $payload['signals'][0]['rule_id'],
        );
        $this->assertSame(
            'numeric.transposition.mod9.v1',
            $payload['signals'][1]['rule_id'],
        );
    }

    public function test_accept_observed_requires_an_explicit_decision_and_final_value_is_observed_exactly(): void
    {
        $signals = NumericalDiscrepancyAnalyzer::classifierPackV1()
            ->analyze('1234', '1294');

        $evidence = NumericalDiscrepancyDecisionEvidence::acceptObserved(
            referenceValue: '1234',
            observedValue: '1294',
            signals: $signals,
            reason: 'Observed value was explicitly verified against source evidence.',
        );

        $payload = $evidence->toArray();

        $this->assertSame(
            NumericalDiscrepancyDecision::AcceptObserved,
            $evidence->decision,
        );
        $this->assertSame('1294', $evidence->finalValue);
        $this->assertSame('ACCEPT_OBSERVED', $payload['decision']);
        $this->assertSame('1294', $payload['final_value']);
        $this->assertFalse($payload['automatic_correction']);
    }

    public function test_same_reference_and_observed_values_fail_closed(): void
    {
        $signal = new NumericalDiscrepancySignal(
            kind: \App\Domain\Numerics\NumericalDiscrepancyKind::DigitSubstitution,
            confidence: \App\Domain\Numerics\NumericalDiscrepancyConfidence::High,
            ruleId: 'numeric.test.synthetic.v1',
            referenceValue: '1234',
            observedValue: '1234',
            explanation: 'Synthetic test signal.',
        );

        $this->expectException(InvalidArgumentException::class);

        NumericalDiscrepancyDecisionEvidence::keepReference(
            referenceValue: '1234',
            observedValue: '1234',
            signals: [$signal],
            reason: 'Explicit synthetic test reason.',
        );
    }

    public function test_empty_signal_set_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NumericalDiscrepancyDecisionEvidence::keepReference(
            referenceValue: '1234',
            observedValue: '1294',
            signals: [],
            reason: 'Explicit test reason.',
        );
    }

    public function test_signal_reference_or_observed_mismatch_fails_closed(): void
    {
        $signals = NumericalDiscrepancyAnalyzer::classifierPackV1()
            ->analyze('1234', '1294');

        $this->expectException(InvalidArgumentException::class);

        NumericalDiscrepancyDecisionEvidence::keepReference(
            referenceValue: '1234',
            observedValue: '1284',
            signals: $signals,
            reason: 'Explicit mismatch test reason.',
        );
    }

    public function test_duplicate_signal_rule_ids_fail_closed(): void
    {
        $signals = NumericalDiscrepancyAnalyzer::classifierPackV1()
            ->analyze('1234', '1294');

        $this->assertCount(1, $signals);

        $this->expectException(InvalidArgumentException::class);

        NumericalDiscrepancyDecisionEvidence::acceptObserved(
            referenceValue: '1234',
            observedValue: '1294',
            signals: [$signals[0], $signals[0]],
            reason: 'Explicit duplicate test reason.',
        );
    }

    public function test_reason_must_be_explicit_trimmed_bounded_and_control_free(): void
    {
        $signals = NumericalDiscrepancyAnalyzer::classifierPackV1()
            ->analyze('1234', '1294');

        foreach ([
            '',
            ' leading',
            "contains\nnewline",
            str_repeat('x', 2049),
        ] as $reason) {
            try {
                NumericalDiscrepancyDecisionEvidence::keepReference(
                    referenceValue: '1234',
                    observedValue: '1294',
                    signals: $signals,
                    reason: $reason,
                );

                $this->fail('Invalid decision reason must fail closed.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_decision_evidence_does_not_authorize_runtime_wiring_or_special_case_inference(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertSame(
            'foundation_only_not_yet_wired',
            $policy['decision_evidence_runtime_wiring_status'],
        );
        $this->assertSame(
            'not_in_foundation_cut',
            $policy['decision_capability_authorization_wiring_status'],
        );
        $this->assertSame(
            'not_in_foundation_cut',
            $policy['decision_audit_persistence_wiring_status'],
        );
        $this->assertSame(
            'undefined_no_implementation_exact_spec_required',
            $policy['transposition_by_omission_special_case_status'],
        );
        $this->assertFalse(
            $policy['transposition_by_omission_implementation_allowed'],
        );
    }
}