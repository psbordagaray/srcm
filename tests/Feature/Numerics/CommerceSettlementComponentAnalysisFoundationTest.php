<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceSettlementComponentAnalysis;
use App\Domain\Commerce\CommerceSettlementComponentAnalyzer;
use App\Domain\Commerce\CommerceSettlementComponentEvidence;
use App\Domain\Commerce\CommerceSettlementDiscrepancyException;
use App\Domain\Commerce\CommerceSettlementMoneyAnalysisProjection;
use App\Domain\Numerics\AdjacentTranspositionClassifier;
use App\Domain\Numerics\ModuloNineTranspositionSignalClassifier;
use App\Domain\Numerics\SeparatorMisplacementClassifier;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class CommerceSettlementComponentAnalysisFoundationTest extends TestCase
{
    public function test_policy_declares_component_analysis_foundation_with_non_authoritative_manager_wiring(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertSame(
            1,
            $policy['commerce_settlement_component_analysis_foundation_version'],
        );
        $this->assertSame(
            CommerceSettlementComponentAnalysis::SCHEMA,
            $policy['commerce_settlement_component_analysis_schema'],
        );
        $this->assertSame(
            CommerceSettlementComponentAnalyzer::class,
            $policy['commerce_settlement_component_analyzer_class'],
        );
        $this->assertSame(
            CommerceSettlementComponentAnalyzer::ANALYSIS_TRIGGER,
            $policy['commerce_settlement_component_analysis_trigger'],
        );
        $this->assertSame(
            CommerceSettlementComponentAnalyzer::
                MANAGER_INSERTION_BOUNDARY,
            $policy['commerce_settlement_component_analysis_manager_insertion_boundary'],
        );
        $this->assertSame(
            CommerceSettlementComponentAnalysis::
                STATUS_NOT_ANALYZABLE_NON_POSITIVE_CONDITIONAL_RESIDUAL,
            $policy['commerce_settlement_component_analysis_non_positive_candidate_status'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_analysis_uses_full_classifier_pack'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_analysis_changes_business_mismatch_outcome'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_analysis_authorizes_correction'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_analysis_authorizes_accept_observed'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyException::RUNTIME_WIRING_STATUS,
            $policy['commerce_settlement_component_analysis_runtime_wiring_status'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyException::SCHEMA,
            $policy['commerce_settlement_component_analysis_runtime_exception_schema'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyException::MESSAGE,
            $policy['commerce_settlement_component_analysis_runtime_exception_message'],
        );
        $this->assertTrue(
            $policy['commerce_settlement_component_analysis_runtime_hard_fail_preserved'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_analysis_runtime_decision_wiring'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_analysis_runtime_keep_reference_authorized'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_analysis_runtime_accept_observed_authorized'],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_digit_projection_uses_exact_subset_and_preserves_multiple_signals(): void
    {
        $source = CommerceSettlementComponentEvidence::payment(
            index: 0,
            rawHumanInput: '12.34',
            originalCanonicalValue: '12.34',
            minorValue: 1234,
        );

        $result = (new CommerceSettlementComponentAnalyzer())->analyze(
            evidence: $source,
            systemTotalMinor: 2134,
            settledTotalMinor: 1234,
        );

        $this->assertSame(
            CommerceSettlementComponentAnalysis::STATUS_ANALYZED,
            $result->status,
        );
        $this->assertSame(2134, $result->conditionalResidualMinor);
        $this->assertSame(
            '21.34',
            $result->conditionalResidualCanonical,
        );
        $this->assertSame(
            '21.34',
            $result->analysisEvidence
                ?->conditionalResidualReferenceValue,
        );

        $digit = $result->projectionAnalyses[0]['projection'];

        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::
                TYPE_DIGIT_STRUCTURAL,
            $digit->projectionType,
        );
        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::
                DIGIT_STRUCTURAL_CLASSIFIER_IDS,
            $digit->targetClassifierIds,
        );

        $ruleIds = array_map(
            static fn ($signal): string => $signal->ruleId,
            $result->signals(),
        );

        $this->assertContains(
            AdjacentTranspositionClassifier::IDENTIFIER,
            $ruleIds,
        );
        $this->assertContains(
            ModuloNineTranspositionSignalClassifier::IDENTIFIER,
            $ruleIds,
        );

        $audit = $result->toArray();

        $this->assertTrue(
            $audit['aggregate_discrepancy_unresolved'],
        );
        $this->assertNull($audit['signal_priority_or_winner']);
        $this->assertFalse($audit['authorizes_correction']);
        $this->assertFalse($audit['authorizes_accept_observed']);
    }

    public function test_separator_projection_runs_separator_classifier_only(): void
    {
        $source = CommerceSettlementComponentEvidence::receivable(
            rawHumanInput: '123.4',
            originalCanonicalValue: '123.4',
            minorValue: 12340,
        );

        $result = (new CommerceSettlementComponentAnalyzer())->analyze(
            evidence: $source,
            systemTotalMinor: 1234,
            settledTotalMinor: 12340,
        );

        $separatorAnalyses = array_values(array_filter(
            $result->projectionAnalyses,
            static fn (array $analysis): bool =>
                $analysis['projection']->projectionType
                    === CommerceSettlementMoneyAnalysisProjection::
                        TYPE_SEPARATOR_MISPLACEMENT,
        ));

        $this->assertCount(1, $separatorAnalyses);
        $this->assertSame(
            [SeparatorMisplacementClassifier::IDENTIFIER],
            $separatorAnalyses[0]['projection']
                ->targetClassifierIds,
        );

        $ruleIds = array_map(
            static fn ($signal): string => $signal->ruleId,
            $separatorAnalyses[0]['signals'],
        );

        $this->assertSame(
            [SeparatorMisplacementClassifier::IDENTIFIER],
            $ruleIds,
        );
    }

    public function test_integer_raw_skips_separator_projection(): void
    {
        $source = CommerceSettlementComponentEvidence::payment(
            index: 0,
            rawHumanInput: '1234',
            originalCanonicalValue: '1234',
            minorValue: 123400,
        );

        $result = (new CommerceSettlementComponentAnalyzer())->analyze(
            evidence: $source,
            systemTotalMinor: 124300,
            settledTotalMinor: 123400,
        );

        $types = array_map(
            static fn (array $analysis): string =>
                $analysis['projection']->projectionType,
            $result->projectionAnalyses,
        );

        $this->assertSame(
            [
                CommerceSettlementMoneyAnalysisProjection::
                    TYPE_DIGIT_STRUCTURAL,
            ],
            $types,
        );
    }

    public function test_non_positive_conditional_residual_is_not_analyzable_without_inventing_evidence(): void
    {
        $source = CommerceSettlementComponentEvidence::payment(
            index: 0,
            rawHumanInput: '10.00',
            originalCanonicalValue: '10.00',
            minorValue: 1000,
        );

        $result = (new CommerceSettlementComponentAnalyzer())->analyze(
            evidence: $source,
            systemTotalMinor: 500,
            settledTotalMinor: 2000,
        );

        $this->assertSame(
            CommerceSettlementComponentAnalysis::
                STATUS_NOT_ANALYZABLE_NON_POSITIVE_CONDITIONAL_RESIDUAL,
            $result->status,
        );
        $this->assertSame(1000, $result->otherObservedMinor);
        $this->assertNull($result->conditionalResidualMinor);
        $this->assertNull($result->conditionalResidualCanonical);
        $this->assertNull($result->analysisEvidence);
        $this->assertSame([], $result->projectionAnalyses);
        $this->assertSame([], $result->signals());

        $audit = $result->toArray();

        $this->assertTrue(
            $audit['aggregate_discrepancy_unresolved'],
        );
    }

    public function test_equal_aggregate_and_invalid_component_context_are_rejected(): void
    {
        $source = CommerceSettlementComponentEvidence::payment(
            index: 0,
            rawHumanInput: '10.00',
            originalCanonicalValue: '10.00',
            minorValue: 1000,
        );
        $analyzer = new CommerceSettlementComponentAnalyzer();

        try {
            $analyzer->analyze(
                evidence: $source,
                systemTotalMinor: 1000,
                settledTotalMinor: 1000,
            );

            $this->fail(
                'Healthy equal settlement must not enter component analysis.'
            );
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);

        $analyzer->analyze(
            evidence: $source,
            systemTotalMinor: 500,
            settledTotalMinor: 500,
        );
    }

    public function test_manager_runtime_analysis_wiring_preserves_current_hard_fail_contract(): void
    {
        $manager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );

        $this->assertIsString($manager);
        $this->assertStringContainsString(
            'CommerceSettlementComponentAnalyzer $settlementComponentAnalyzer',
            $manager,
        );
        $this->assertStringContainsString(
            'CommerceSettlementDiscrepancyException::',
            $manager,
        );
        $this->assertStringContainsString(
            'if ($total <= 0 || $settledTotal !== $total) {',
            $manager,
        );
        $this->assertStringContainsString(
            CommerceSettlementDiscrepancyException::MESSAGE,
            $manager,
        );
    }
}