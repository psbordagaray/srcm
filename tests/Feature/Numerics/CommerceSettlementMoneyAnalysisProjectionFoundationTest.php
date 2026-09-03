<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceSettlementComponentEvidence;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionSemantics;
use App\Domain\Commerce\CommerceSettlementMoneyAnalysisProjection;
use App\Domain\Numerics\AdjacentTranspositionClassifier;
use App\Domain\Numerics\SeparatorMisplacementClassifier;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class CommerceSettlementMoneyAnalysisProjectionFoundationTest extends TestCase
{
    public function test_policy_declares_projection_foundation_without_runtime_wiring(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertSame(
            1,
            $policy['commerce_settlement_money_analysis_projection_foundation_version'],
        );
        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::SCHEMA,
            $policy['commerce_settlement_money_analysis_projection_schema'],
        );
        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::class,
            $policy['commerce_settlement_money_analysis_projection_class'],
        );
        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::PROJECTION_TYPES,
            $policy['commerce_settlement_money_analysis_projection_types'],
        );
        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::MONEY_SCALE,
            $policy['commerce_settlement_money_analysis_projection_scale'],
        );
        $this->assertTrue(
            $policy['commerce_settlement_money_analysis_projection_classifier_subset_required'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_money_analysis_projection_run_all_classifiers'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_money_analysis_projection_source_normalization'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_money_analysis_projection_source_replacement'],
        );
        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::INPUT_SELECTION_STATUS,
            $policy['commerce_settlement_component_analysis_input_selection_status'],
        );
        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::RUNTIME_WIRING_STATUS,
            $policy['commerce_settlement_money_analysis_projection_runtime_wiring_status'],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_digit_structural_projection_is_fixed_scale_digits_with_exact_classifier_subset(): void
    {
        $evidence = CommerceSettlementComponentEvidence::payment(
            index: 0,
            rawHumanInput: '13,24',
            originalCanonicalValue: '13.24',
            minorValue: 1324,
            conditionalResidualReferenceValue: '12.34',
        );

        $projection =
            CommerceSettlementMoneyAnalysisProjection::digitStructural(
                $evidence,
            );

        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::TYPE_DIGIT_STRUCTURAL,
            $projection->projectionType,
        );
        $this->assertSame('1234', $projection->referenceAnalysisValue);
        $this->assertSame('1324', $projection->observedAnalysisValue);
        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::DIGIT_STRUCTURAL_CLASSIFIER_IDS,
            $projection->targetClassifierIds,
        );

        $signal = (new AdjacentTranspositionClassifier())->classify(
            $projection->referenceAnalysisValue,
            $projection->observedAnalysisValue,
        );

        $this->assertNotNull($signal);
        $this->assertSame(
            AdjacentTranspositionClassifier::IDENTIFIER,
            $signal->ruleId,
        );
    }

    public function test_separator_projection_preserves_observed_raw_and_uses_only_separator_classifier(): void
    {
        $evidence = CommerceSettlementComponentEvidence::receivable(
            rawHumanInput: '123,4',
            originalCanonicalValue: '123.4',
            minorValue: 12340,
            conditionalResidualReferenceValue: '12.34',
        );

        $projection =
            CommerceSettlementMoneyAnalysisProjection::separatorMisplacement(
                $evidence,
            );

        $this->assertNotNull($projection);
        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::TYPE_SEPARATOR_MISPLACEMENT,
            $projection->projectionType,
        );
        $this->assertSame('12,34', $projection->referenceAnalysisValue);
        $this->assertSame('123,4', $projection->observedAnalysisValue);
        $this->assertSame(
            CommerceSettlementMoneyAnalysisProjection::SEPARATOR_CLASSIFIER_IDS,
            $projection->targetClassifierIds,
        );

        $signal = (new SeparatorMisplacementClassifier())->classify(
            $projection->referenceAnalysisValue,
            $projection->observedAnalysisValue,
        );

        $this->assertNotNull($signal);
        $this->assertSame(
            SeparatorMisplacementClassifier::IDENTIFIER,
            $signal->ruleId,
        );
    }

    public function test_projection_payload_keeps_source_evidence_auditable_and_never_authorizes_correction(): void
    {
        $evidence = CommerceSettlementComponentEvidence::payment(
            index: 2,
            rawHumanInput: '13.24',
            originalCanonicalValue: '13.24',
            minorValue: 1324,
            conditionalResidualReferenceValue: '12.34',
        );

        $projection =
            CommerceSettlementMoneyAnalysisProjection::digitStructural(
                $evidence,
            );
        $payload = $projection->toArray();

        $this->assertSame(
            CommerceSettlementComponentEvidence::SCHEMA,
            $payload['source_component_schema'],
        );
        $this->assertSame(
            'payments.2.amount',
            $payload['source_component_id'],
        );
        $this->assertSame('13.24', $payload['source_raw_human_input']);
        $this->assertSame(
            '13.24',
            $payload['source_original_canonical_value'],
        );
        $this->assertSame(1324, $payload['source_minor_value']);
        $this->assertSame(
            '12.34',
            $payload['source_conditional_residual_reference_value'],
        );
        $this->assertTrue($payload['classifier_subset_enforced']);
        $this->assertFalse($payload['run_all_classifiers']);
        $this->assertTrue($payload['derived_analysis_view']);
        $this->assertFalse($payload['source_normalization']);
        $this->assertFalse($payload['source_replacement']);
        $this->assertFalse($payload['authorizes_correction']);
        $this->assertFalse($payload['proves_human_cause']);
        $this->assertFalse($payload['runtime_wiring']);
    }

    public function test_integer_raw_is_not_separator_eligible_and_missing_residual_fails_closed(): void
    {
        $integerRaw = CommerceSettlementComponentEvidence::receivable(
            rawHumanInput: '123',
            originalCanonicalValue: '123',
            minorValue: 12300,
            conditionalResidualReferenceValue: '12.30',
        );

        $this->assertNull(
            CommerceSettlementMoneyAnalysisProjection::separatorMisplacement(
                $integerRaw,
            )
        );

        $withoutResidual = CommerceSettlementComponentEvidence::payment(
            index: 0,
            rawHumanInput: '12,34',
            originalCanonicalValue: '12.34',
            minorValue: 1234,
        );

        $this->expectException(InvalidArgumentException::class);

        CommerceSettlementMoneyAnalysisProjection::digitStructural(
            $withoutResidual,
        );
    }

    public function test_foundation_does_not_wire_commerce_runtime_or_accept_observed(): void
    {
        $request = file_get_contents(
            app_path('Http/Requests/StoreCommerceSaleRequest.php')
        );
        $controller = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );
        $manager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );
        $checkoutData = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutData.php')
        );
        $paymentData = file_get_contents(
            app_path('Domain/Commerce/CommercePaymentData.php')
        );
        $semantics =
            CommerceSettlementDiscrepancyDecisionSemantics::decisionSemantics();
        $policy = config('release.numeric_integrity.discrepancy_framework');

        foreach (
            [$request, $controller, $manager, $checkoutData, $paymentData]
            as $runtimeSource
        ) {
            $this->assertIsString($runtimeSource);
            $this->assertStringNotContainsString(
                'CommerceSettlementMoneyAnalysisProjection',
                $runtimeSource,
            );
        }

        $this->assertSame(
            'BLOCKED',
            $semantics['ACCEPT_OBSERVED']['status'],
        );
        $this->assertFalse(
            $semantics['ACCEPT_OBSERVED']['business_mutation_authorized'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_accept_observed_runtime_authorized'],
        );
        $this->assertFalse($policy['silent_autocorrection_allowed']);
        $this->assertFalse($policy['ai_decision_authority_allowed']);
        $this->assertSame(
            'undefined_no_implementation_exact_spec_required',
            $policy['transposition_by_omission_special_case_status'],
        );
        $this->assertFalse(
            $policy['transposition_by_omission_implementation_allowed'],
        );
    }
}