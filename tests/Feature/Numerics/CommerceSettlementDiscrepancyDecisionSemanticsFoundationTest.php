<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionSemantics;
use App\Domain\Numerics\NumericalDiscrepancyDecision;
use App\Domain\Release\ReleasePreflightInspector;
use Tests\TestCase;

final class CommerceSettlementDiscrepancyDecisionSemanticsFoundationTest extends TestCase
{
    public function test_policy_declares_commerce_settlement_semantics_foundation_without_runtime_wiring(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertSame(
            1,
            $policy['commerce_settlement_decision_semantics_foundation_version'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionSemantics::SCHEMA,
            $policy['commerce_settlement_decision_semantics_schema'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionSemantics::class,
            $policy['commerce_settlement_decision_semantics_class'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionSemantics::REFERENCE_VALUE_ROLE,
            $policy['commerce_settlement_reference_value_role'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionSemantics::OBSERVED_VALUE_ROLE,
            $policy['commerce_settlement_observed_value_role'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionSemantics::CURRENT_MISMATCH_BEHAVIOR,
            $policy['commerce_settlement_current_mismatch_behavior'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionSemantics::decisionSemantics(),
            $policy['commerce_settlement_decision_semantics'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_aggregate_signal_identifies_source_field'],
        );
        $this->assertTrue(
            $policy['commerce_settlement_field_level_reanalysis_required_before_correction'],
        );
        $this->assertSame(
            'undefined_not_authorized',
            $policy['commerce_settlement_accept_observed_business_mutation_target_status'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_accept_observed_runtime_authorized'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_accept_observed_may_change_system_total'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_accept_observed_may_rewrite_payment_or_receivable'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionSemantics::RUNTIME_WIRING_STATUS,
            $policy['commerce_settlement_runtime_wiring_status'],
        );
        $this->assertSame(
            'deferred_until_independent_reference_defined',
            $policy['service_cancellation_reference_mapping_status'],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_keep_reference_is_defined_but_accept_observed_remains_blocked(): void
    {
        $this->assertSame(
            [NumericalDiscrepancyDecision::KeepReference->value],
            CommerceSettlementDiscrepancyDecisionSemantics::semanticallyDefinedDecisionValues(),
        );
        $this->assertSame(
            [NumericalDiscrepancyDecision::AcceptObserved->value],
            CommerceSettlementDiscrepancyDecisionSemantics::blockedDecisionValues(),
        );

        $semantics =
            CommerceSettlementDiscrepancyDecisionSemantics::decisionSemantics();

        $this->assertSame(
            'DEFINED',
            $semantics[NumericalDiscrepancyDecision::KeepReference->value]['status'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionSemantics::KEEP_REFERENCE_EFFECT,
            $semantics[NumericalDiscrepancyDecision::KeepReference->value]['effect'],
        );
        $this->assertFalse(
            $semantics[NumericalDiscrepancyDecision::KeepReference->value]['business_mutation_authorized'],
        );

        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionSemantics::ACCEPT_OBSERVED_STATUS,
            $semantics[NumericalDiscrepancyDecision::AcceptObserved->value]['status'],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionSemantics::ACCEPT_OBSERVED_EFFECT,
            $semantics[NumericalDiscrepancyDecision::AcceptObserved->value]['effect'],
        );
        $this->assertFalse(
            $semantics[NumericalDiscrepancyDecision::AcceptObserved->value]['business_mutation_authorized'],
        );
    }

    public function test_commerce_runtime_remains_hard_fail_closed_and_unwired(): void
    {
        $manager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );

        $this->assertIsString($manager);
        $this->assertStringContainsString(
            '$settledTotal !== $total',
            $manager,
        );
        $this->assertStringContainsString(
            'Los pagos y el saldo pendiente deben cubrir exactamente el total de la venta.',
            $manager,
        );
        $this->assertStringNotContainsString(
            'CommerceSettlementDiscrepancyDecisionSemantics',
            $manager,
        );
        $this->assertStringNotContainsString(
            'NumericalDiscrepancyAnalyzer',
            $manager,
        );
        $this->assertStringNotContainsString(
            'NumericalDiscrepancyOverrideAuthorization',
            $manager,
        );
        $this->assertStringNotContainsString(
            'NumericalDiscrepancyOverrideAuditEvidence',
            $manager,
        );
        $this->assertStringNotContainsString('AuditRecorder', $manager);
    }

    public function test_aggregate_signal_does_not_prove_which_human_field_is_wrong(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertFalse(
            $policy['commerce_settlement_aggregate_signal_identifies_source_field'],
        );
        $this->assertTrue(
            $policy['commerce_settlement_field_level_reanalysis_required_before_correction'],
        );
        $this->assertFalse(
            $policy['commerce_settlement_accept_observed_may_rewrite_payment_or_receivable'],
        );
    }

    public function test_service_cancellation_remains_deferred_without_independent_reference(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');
        $manager = file_get_contents(
            app_path('Domain/Service/ServiceCancellationManager.php')
        );

        $this->assertSame(
            'deferred_until_independent_reference_defined',
            $policy['service_cancellation_reference_mapping_status'],
        );
        $this->assertIsString($manager);
        $this->assertStringNotContainsString(
            'CommerceSettlementDiscrepancyDecisionSemantics',
            $manager,
        );
        $this->assertStringNotContainsString(
            'NumericalDiscrepancyOverrideAuthorization',
            $manager,
        );
    }

    public function test_foundation_does_not_authorize_silent_correction_ai_or_special_case(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

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