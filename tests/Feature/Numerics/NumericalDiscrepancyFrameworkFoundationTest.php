<?php

namespace Tests\Feature\Numerics;

use App\Domain\Numerics\ModuloNineTranspositionSignalClassifier;
use App\Domain\Numerics\NumericalDiscrepancyAnalyzer;
use App\Domain\Numerics\NumericalDiscrepancyClassifier;
use App\Domain\Numerics\NumericalDiscrepancyConfidence;
use App\Domain\Numerics\NumericalDiscrepancyKind;
use App\Domain\Numerics\NumericalDiscrepancySignal;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class NumericalDiscrepancyFrameworkFoundationTest extends TestCase
{
    public function test_policy_is_versioned_deterministic_auditable_and_never_silently_corrects(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertSame(1, $policy['foundation_version']);
        $this->assertSame(
            NumericalDiscrepancySignal::SCHEMA,
            $policy['signal_schema'],
        );
        $this->assertSame(
            NumericalDiscrepancyKind::values(),
            $policy['kind_values'],
        );
        $this->assertSame(
            NumericalDiscrepancyConfidence::values(),
            $policy['confidence_values'],
        );
        $this->assertSame(
            NumericalDiscrepancyClassifier::class,
            $policy['classifier_interface'],
        );
        $this->assertSame(
            NumericalDiscrepancyAnalyzer::class,
            $policy['analyzer_class'],
        );
        $this->assertSame([
            ModuloNineTranspositionSignalClassifier::class,
        ], $policy['foundation_classifier_classes']);
        $this->assertTrue(
            $policy['transposition_modulo_nine_is_signal_only'],
        );
        $this->assertFalse(
            $policy['transposition_modulo_nine_is_proof'],
        );
        $this->assertFalse($policy['silent_autocorrection_allowed']);
        $this->assertTrue($policy['classifier_signal_is_not_correction']);
        $this->assertTrue($policy['deterministic_rules_are_authoritative']);
        $this->assertFalse($policy['ai_decision_authority_allowed']);
        $this->assertTrue(
            $policy['ai_explanation_may_summarize_deterministic_evidence'],
        );
        $this->assertTrue($policy['warning_audit_required']);
        $this->assertTrue($policy['decision_audit_required']);
        $this->assertTrue($policy['original_value_audit_required']);
        $this->assertTrue($policy['final_value_audit_required']);
        $this->assertSame(
            'undefined_no_implementation_exact_spec_required',
            $policy['transposition_by_omission_special_case_status'],
        );
        $this->assertFalse(
            $policy['transposition_by_omission_implementation_allowed'],
        );
        $this->assertSame(
            'not_in_foundation_cut',
            $policy['classifier_pack_status'],
        );
        $this->assertSame(
            'foundation_only_not_yet_wired',
            $policy['runtime_wiring_status'],
        );
        $this->assertTrue(
            $policy['runtime_wiring_requires_separate_reviewed_cut'],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_discrepancy_taxonomy_is_explicit_and_stable(): void
    {
        $this->assertSame([
            'TRANSPOSITION_MODULO_NINE_SIGNAL',
            'ADJACENT_TRANSPOSITION',
            'DIGIT_OMISSION',
            'DIGIT_DUPLICATION',
            'SEPARATOR_MISPLACEMENT',
            'DIGIT_SUBSTITUTION',
        ], NumericalDiscrepancyKind::values());

        $this->assertSame([
            'LOW',
            'MEDIUM',
            'HIGH',
        ], NumericalDiscrepancyConfidence::values());
    }

    public function test_modulo_nine_classifier_returns_low_confidence_signal_only(): void
    {
        $classifier = new ModuloNineTranspositionSignalClassifier();

        $signal = $classifier->classify('12345', '12435');

        $this->assertNotNull($signal);
        $this->assertSame(
            NumericalDiscrepancyKind::TranspositionModuloNineSignal,
            $signal->kind,
        );
        $this->assertSame(
            NumericalDiscrepancyConfidence::Low,
            $signal->confidence,
        );
        $this->assertSame(
            ModuloNineTranspositionSignalClassifier::IDENTIFIER,
            $signal->ruleId,
        );
        $this->assertSame('12345', $signal->referenceValue);
        $this->assertSame('12435', $signal->observedValue);
        $this->assertTrue($signal->evidence['signal_only']);
        $this->assertSame(6, $signal->evidence['reference_digit_sum_mod_9']);
        $this->assertSame(6, $signal->evidence['observed_digit_sum_mod_9']);
    }

    public function test_modulo_nine_signal_is_not_transposition_proof(): void
    {
        $classifier = new ModuloNineTranspositionSignalClassifier();

        $signal = $classifier->classify('1234', '2143');

        $this->assertNotNull($signal);
        $this->assertSame(
            NumericalDiscrepancyConfidence::Low,
            $signal->confidence,
        );
        $this->assertStringContainsString(
            'not proof',
            $signal->explanation,
        );
        $this->assertFalse(
            config(
                'release.numeric_integrity.discrepancy_framework.transposition_modulo_nine_is_proof'
            ),
        );
    }

    public function test_modulo_nine_classifier_returns_no_signal_when_rule_is_not_applicable(): void
    {
        $classifier = new ModuloNineTranspositionSignalClassifier();

        foreach ([
            ['1234', '1234'],
            ['1234', '123'],
            ['1234', '1235'],
            ['12A4', '124A'],
            ['1.23', '1.32'],
        ] as [$reference, $observed]) {
            $this->assertNull(
                $classifier->classify($reference, $observed),
            );
        }
    }

    public function test_analyzer_is_extensible_deterministic_and_preserves_both_values(): void
    {
        $analyzer = NumericalDiscrepancyAnalyzer::foundation();

        $signals = $analyzer->analyze('12345', '12435');

        $this->assertCount(1, $signals);
        $this->assertSame('12345', $signals[0]->referenceValue);
        $this->assertSame('12435', $signals[0]->observedValue);
        $this->assertSame(
            ModuloNineTranspositionSignalClassifier::IDENTIFIER,
            $signals[0]->ruleId,
        );
    }

    public function test_duplicate_classifier_identifiers_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NumericalDiscrepancyAnalyzer([
            new ModuloNineTranspositionSignalClassifier(),
            new ModuloNineTranspositionSignalClassifier(),
        ]);
    }
}