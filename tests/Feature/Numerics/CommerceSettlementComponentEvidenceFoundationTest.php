<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceSettlementComponentEvidence;
use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionSemantics;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class CommerceSettlementComponentEvidenceFoundationTest extends TestCase
{
    public function test_policy_declares_component_evidence_foundation_without_runtime_wiring(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertSame(
            1,
            $policy['commerce_settlement_component_evidence_foundation_version'],
        );
        $this->assertSame(
            CommerceSettlementComponentEvidence::SCHEMA,
            $policy['commerce_settlement_component_evidence_schema'],
        );
        $this->assertSame(
            CommerceSettlementComponentEvidence::class,
            $policy['commerce_settlement_component_evidence_class'],
        );
        $this->assertSame(
            CommerceSettlementComponentEvidence::COMPONENT_TYPES,
            $policy['commerce_settlement_component_types'],
        );
        $this->assertSame(
            CommerceSettlementComponentEvidence::PAYMENT_COMPONENT_ID_PATTERN,
            $policy['commerce_settlement_payment_component_id_pattern'],
        );
        $this->assertSame(
            CommerceSettlementComponentEvidence::RECEIVABLE_COMPONENT_ID,
            $policy['commerce_settlement_receivable_component_id'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_tendered_amount_is_settlement_component'],
        );
        $this->assertSame(
            CommerceSettlementComponentEvidence::RUNTIME_WIRING_STATUS,
            $policy['commerce_settlement_component_evidence_runtime_wiring_status'],
        );
        $this->assertSame(
            \App\Domain\Commerce\CommerceSettlementMoneyAnalysisProjection::INPUT_SELECTION_STATUS,
            $policy['commerce_settlement_component_analysis_input_selection_status'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_accept_observed_runtime_authorized'],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_payment_evidence_preserves_raw_canonical_minor_and_conditional_candidate(): void
    {
        $evidence = CommerceSettlementComponentEvidence::payment(
            index: 0,
            rawHumanInput: '12,34',
            originalCanonicalValue: '12.34',
            minorValue: 1234,
            conditionalResidualReferenceValue: '13.34',
        );

        $this->assertSame(
            [
                'schema' => CommerceSettlementComponentEvidence::SCHEMA,
                'component_id' => 'payments.0.amount',
                'component_type' =>
                    CommerceSettlementComponentEvidence::TYPE_PAYMENT_AMOUNT,
                'raw_human_input' => '12,34',
                'original_canonical_value' => '12.34',
                'minor_value' => 1234,
                'conditional_residual_reference_value' => '13.34',
                'conditional_residual_formula' =>
                    CommerceSettlementComponentEvidence::CONDITIONAL_RESIDUAL_FORMULA,
                'conditional_residual_is_independent_fact' => false,
                'conditional_residual_assumption' =>
                    CommerceSettlementComponentEvidence::CONDITIONAL_RESIDUAL_ASSUMPTION,
                'review_candidate' => true,
                'cause_proven' => false,
                'automatic_field_correction' => false,
                'runtime_wiring' => false,
            ],
            $evidence->toArray(),
        );
    }

    public function test_receivable_evidence_preserves_component_identity_without_inventing_candidate(): void
    {
        $evidence = CommerceSettlementComponentEvidence::receivable(
            rawHumanInput: '500',
            originalCanonicalValue: '500',
            minorValue: 50000,
        );

        $this->assertSame(
            CommerceSettlementComponentEvidence::RECEIVABLE_COMPONENT_ID,
            $evidence->componentId,
        );
        $this->assertSame(
            CommerceSettlementComponentEvidence::TYPE_RECEIVABLE_AMOUNT,
            $evidence->componentType,
        );
        $this->assertFalse($evidence->hasConditionalResidualCandidate());
        $this->assertNull(
            $evidence->toArray()['conditional_residual_reference_value'],
        );
        $this->assertFalse($evidence->toArray()['review_candidate']);
    }

    public function test_component_evidence_fails_closed_on_inconsistent_or_invalid_numeric_evidence(): void
    {
        try {
            CommerceSettlementComponentEvidence::payment(
                index: 0,
                rawHumanInput: '12,34',
                originalCanonicalValue: '12.34',
                minorValue: 1235,
            );

            $this->fail('Canonical/minor mismatch must fail closed.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            CommerceSettlementComponentEvidence::payment(
                index: -1,
                rawHumanInput: '12,34',
                originalCanonicalValue: '12.34',
                minorValue: 1234,
            );

            $this->fail('Negative payment index must fail closed.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            CommerceSettlementComponentEvidence::receivable(
                rawHumanInput: ' 12,34',
                originalCanonicalValue: '12.34',
                minorValue: 1234,
            );

            $this->fail('Untrimmed raw human input must fail closed.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            CommerceSettlementComponentEvidence::receivable(
                rawHumanInput: '12,34',
                originalCanonicalValue: '12.34',
                minorValue: 1234,
                conditionalResidualReferenceValue: '12.34',
            );

            $this->fail('Non-discrepant residual candidate must fail closed.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_conditional_residual_is_review_evidence_not_cause_or_winner(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertSame(
            CommerceSettlementComponentEvidence::CONDITIONAL_RESIDUAL_FORMULA,
            $policy['commerce_settlement_conditional_residual_formula'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_conditional_residual_is_independent_fact'],
        );
        $this->assertSame(
            CommerceSettlementComponentEvidence::CONDITIONAL_RESIDUAL_ASSUMPTION,
            $policy['commerce_settlement_conditional_residual_assumption'],
        );
        $this->assertTrue(
            $policy['commerce_settlement_multiple_component_candidates_may_coexist'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_priority_or_automatic_winner_allowed'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_component_analysis_proves_cause'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_automatic_field_correction_allowed'],
        );
        $this->assertTrue(
            $policy['commerce_settlement_field_level_explicit_review_required'],
        );
    }

    public function test_foundation_preserves_runtime_and_override_blocks(): void
    {
        $manager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );
        $controller = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );
        $request = file_get_contents(
            app_path('Http/Requests/StoreCommerceSaleRequest.php')
        );
        $semantics = CommerceSettlementDiscrepancyDecisionSemantics::decisionSemantics();
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsString($manager);
        $this->assertIsString($controller);
        $this->assertIsString($request);

        foreach ([$manager, $controller, $request] as $runtimeSource) {
            $this->assertStringNotContainsString(
                'CommerceSettlementComponentEvidence',
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