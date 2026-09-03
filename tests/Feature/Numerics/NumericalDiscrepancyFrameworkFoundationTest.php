<?php

namespace Tests\Feature\Numerics;

use App\Domain\Numerics\AdjacentTranspositionClassifier;
use App\Domain\Numerics\DigitDuplicationClassifier;
use App\Domain\Numerics\DigitOmissionClassifier;
use App\Domain\Numerics\DigitSubstitutionClassifier;
use App\Domain\Numerics\ModuloNineTranspositionSignalClassifier;
use App\Domain\Numerics\NumericalDiscrepancyAnalyzer;
use App\Domain\Numerics\NumericalDiscrepancyClassifier;
use App\Domain\Numerics\NumericalDiscrepancyConfidence;
use App\Domain\Numerics\NumericalDiscrepancyKind;
use App\Domain\Numerics\NumericalDiscrepancySignal;
use App\Domain\Numerics\SeparatorMisplacementClassifier;
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
        $this->assertSame(
            NumericalDiscrepancyAnalyzer::FOUNDATION_CLASSIFIERS,
            $policy['foundation_classifier_classes'],
        );
        $this->assertSame(1, $policy['classifier_pack_version']);
        $this->assertSame(
            NumericalDiscrepancyAnalyzer::CLASSIFIER_PACK_V1,
            $policy['classifier_pack_classes'],
        );
        $this->assertSame(
            'implemented_v1_not_runtime_wired',
            $policy['classifier_pack_status'],
        );
        $this->assertTrue(
            $policy['classifier_pack_runtime_wiring_requires_separate_reviewed_cut'],
        );
        $this->assertTrue($policy['multiple_signals_may_coexist']);
        $this->assertFalse(
            $policy['signal_priority_or_autocorrection_winner_allowed'],
        );
        $this->assertTrue(
            $policy['structural_match_is_not_human_cause_proof'],
        );
        $this->assertSame(
            NumericalDiscrepancyConfidence::High->value,
            $policy['unique_structural_match_confidence'],
        );
        $this->assertSame(
            NumericalDiscrepancyConfidence::Medium->value,
            $policy['ambiguous_single_edit_match_confidence'],
        );
        $this->assertTrue(
            $policy['separator_misplacement_requires_same_separator_symbol'],
        );
        $this->assertTrue(
            $policy['separator_misplacement_requires_same_digit_sequence'],
        );
        $this->assertTrue(
            $policy['generic_omission_classifier_must_not_infer_special_case'],
        );
        $this->assertSame(
            'after_structural_classifiers',
            $policy['modulo_nine_classifier_order'],
        );
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

    public function test_adjacent_transposition_is_high_confidence_structural_match_not_cause_proof(): void
    {
        $classifier = new AdjacentTranspositionClassifier();

        $signal = $classifier->classify('12345', '12435');

        $this->assertNotNull($signal);
        $this->assertSame(
            NumericalDiscrepancyKind::AdjacentTransposition,
            $signal->kind,
        );
        $this->assertSame(
            NumericalDiscrepancyConfidence::High,
            $signal->confidence,
        );
        $this->assertSame(2, $signal->evidence['first_index']);
        $this->assertSame(3, $signal->evidence['second_index']);
        $this->assertFalse($signal->evidence['human_cause_proven']);

        foreach ([
            ['1234', '1234'],
            ['1234', '1325'],
            ['1123', '1123'],
            ['12A4', '124A'],
        ] as [$reference, $observed]) {
            $this->assertNull(
                $classifier->classify($reference, $observed),
            );
        }
    }

    public function test_digit_omission_reports_unique_and_ambiguous_structural_matches_without_special_case_inference(): void
    {
        $classifier = new DigitOmissionClassifier();

        $unique = $classifier->classify('12345', '1235');

        $this->assertNotNull($unique);
        $this->assertSame(
            NumericalDiscrepancyKind::DigitOmission,
            $unique->kind,
        );
        $this->assertSame(
            NumericalDiscrepancyConfidence::High,
            $unique->confidence,
        );
        $this->assertSame(1, $unique->evidence['candidate_count']);
        $this->assertSame(3, $unique->evidence['first_candidate_index']);
        $this->assertSame('4', $unique->evidence['omitted_digit']);
        $this->assertFalse($unique->evidence['special_case_inferred']);

        $ambiguous = $classifier->classify('1223', '123');

        $this->assertNotNull($ambiguous);
        $this->assertSame(
            NumericalDiscrepancyConfidence::Medium,
            $ambiguous->confidence,
        );
        $this->assertSame(2, $ambiguous->evidence['candidate_count']);

        $this->assertNull($classifier->classify('1234', '1245'));
        $this->assertSame(
            'undefined_no_implementation_exact_spec_required',
            config(
                'release.numeric_integrity.discrepancy_framework.transposition_by_omission_special_case_status'
            ),
        );
    }

    public function test_digit_duplication_reports_unique_and_ambiguous_source_matches(): void
    {
        $classifier = new DigitDuplicationClassifier();

        $unique = $classifier->classify('1234', '12234');

        $this->assertNotNull($unique);
        $this->assertSame(
            NumericalDiscrepancyKind::DigitDuplication,
            $unique->kind,
        );
        $this->assertSame(
            NumericalDiscrepancyConfidence::High,
            $unique->confidence,
        );
        $this->assertSame(1, $unique->evidence['candidate_count']);
        $this->assertSame(1, $unique->evidence['first_source_index']);
        $this->assertSame('2', $unique->evidence['duplicated_digit']);

        $ambiguous = $classifier->classify('1223', '12223');

        $this->assertNotNull($ambiguous);
        $this->assertSame(
            NumericalDiscrepancyConfidence::Medium,
            $ambiguous->confidence,
        );
        $this->assertSame(2, $ambiguous->evidence['candidate_count']);

        $this->assertNull($classifier->classify('1234', '12934'));
    }

    public function test_separator_misplacement_requires_same_separator_symbol_and_digit_sequence(): void
    {
        $classifier = new SeparatorMisplacementClassifier();

        $signal = $classifier->classify('12.34', '1.234');

        $this->assertNotNull($signal);
        $this->assertSame(
            NumericalDiscrepancyKind::SeparatorMisplacement,
            $signal->kind,
        );
        $this->assertSame(
            NumericalDiscrepancyConfidence::High,
            $signal->confidence,
        );
        $this->assertSame('.', $signal->evidence['separator']);
        $this->assertSame(2, $signal->evidence['reference_separator_index']);
        $this->assertSame(1, $signal->evidence['observed_separator_index']);
        $this->assertTrue($signal->evidence['same_digit_sequence']);

        $this->assertNull($classifier->classify('12.34', '12,34'));
        $this->assertNull($classifier->classify('12.34', '12.35'));
        $this->assertNull($classifier->classify('1234', '12.34'));
    }

    public function test_digit_substitution_requires_exactly_one_different_ascii_digit(): void
    {
        $classifier = new DigitSubstitutionClassifier();

        $signal = $classifier->classify('1234', '1294');

        $this->assertNotNull($signal);
        $this->assertSame(
            NumericalDiscrepancyKind::DigitSubstitution,
            $signal->kind,
        );
        $this->assertSame(
            NumericalDiscrepancyConfidence::High,
            $signal->confidence,
        );
        $this->assertSame(2, $signal->evidence['difference_index']);
        $this->assertSame('3', $signal->evidence['reference_digit']);
        $this->assertSame('9', $signal->evidence['observed_digit']);

        $this->assertNull($classifier->classify('1234', '1295'));
        $this->assertNull($classifier->classify('1234', '123'));
        $this->assertNull($classifier->classify('12A4', '1294'));
    }

    public function test_classifier_pack_order_is_deterministic_and_multiple_signals_may_coexist(): void
    {
        $this->assertSame([
            AdjacentTranspositionClassifier::class,
            DigitOmissionClassifier::class,
            DigitDuplicationClassifier::class,
            SeparatorMisplacementClassifier::class,
            DigitSubstitutionClassifier::class,
            ModuloNineTranspositionSignalClassifier::class,
        ], NumericalDiscrepancyAnalyzer::CLASSIFIER_PACK_V1);

        $signals = NumericalDiscrepancyAnalyzer::classifierPackV1()
            ->analyze('12345', '12435');

        $this->assertCount(2, $signals);
        $this->assertSame(
            NumericalDiscrepancyKind::AdjacentTransposition,
            $signals[0]->kind,
        );
        $this->assertSame(
            NumericalDiscrepancyConfidence::High,
            $signals[0]->confidence,
        );
        $this->assertSame(
            NumericalDiscrepancyKind::TranspositionModuloNineSignal,
            $signals[1]->kind,
        );
        $this->assertSame(
            NumericalDiscrepancyConfidence::Low,
            $signals[1]->confidence,
        );
    }

    public function test_foundation_analyzer_remains_backward_compatible_with_modulo_nine_only(): void
    {
        $signals = NumericalDiscrepancyAnalyzer::foundation()
            ->analyze('12345', '12435');

        $this->assertCount(1, $signals);
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