<?php

namespace Tests\Feature\Numerics;

use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionEvidence;
use App\Domain\Commerce\CommerceSettlementDiscrepancyException;
use App\Domain\Numerics\NumericalDiscrepancyDecision;
use App\Domain\Numerics\NumericalDiscrepancyDecisionEvidence;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class CommerceSettlementDiscrepancyDecisionEvidenceFoundationTest extends TestCase
{
    public function test_policy_declares_commerce_aggregate_decision_evidence_foundation_without_runtime_wiring(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionEvidence::FOUNDATION_VERSION,
            $policy[
                'commerce_settlement_aggregate_decision_evidence_foundation_version'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionEvidence::SCHEMA,
            $policy[
                'commerce_settlement_aggregate_decision_evidence_schema'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionEvidence::class,
            $policy[
                'commerce_settlement_aggregate_decision_evidence_class'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionEvidence::WARNING_CODE,
            $policy[
                'commerce_settlement_aggregate_decision_warning_code'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionEvidence::
                AUTHORIZED_DECISION_VALUES,
            $policy[
                'commerce_settlement_aggregate_decision_authorized_values'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionEvidence::
                BLOCKED_DECISION_VALUES,
            $policy[
                'commerce_settlement_aggregate_decision_blocked_values'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_aggregate_decision_generic_evidence_created'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_aggregate_decision_business_mutation_authorized'
            ],
        );
        $this->assertFalse(
            $policy[
                'commerce_settlement_aggregate_decision_audit_persistence'
            ],
        );
        $this->assertSame(
            CommerceSettlementDiscrepancyDecisionEvidence::
                RUNTIME_WIRING_STATUS,
            $policy[
                'commerce_settlement_aggregate_decision_runtime_wiring_status'
            ],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_keep_reference_preserves_system_total_and_allows_zero_component_signals_as_context(): void
    {
        $runtimeEvidence = $this->runtimeEvidenceWithMissingTransportOnly();

        $evidence =
            CommerceSettlementDiscrepancyDecisionEvidence::keepReference(
                runtimeEvidence: $runtimeEvidence,
                reason: 'Settlement discrepancy reviewed; preserve system-derived sale total.',
            );

        $array = $evidence->toArray();

        $this->assertSame(
            NumericalDiscrepancyDecision::KeepReference,
            $evidence->decision,
        );
        $this->assertSame(10000, $evidence->finalValueMinor);
        $this->assertSame(
            NumericalDiscrepancyDecision::KeepReference->value,
            $array['decision'],
        );
        $this->assertSame(10000, $array['reference_value_minor']);
        $this->assertSame(10000, $array['original_value_minor']);
        $this->assertSame(9000, $array['observed_value_minor']);
        $this->assertSame(10000, $array['final_value_minor']);
        $this->assertSame(
            ['payments.0.amount'],
            $array['observed_component_ids'],
        );
        $this->assertSame([], $array['component_analyses']);
        $this->assertSame(
            ['payments.0.amount'],
            $array['missing_transport_evidence_component_ids'],
        );
        $this->assertTrue(
            $array[
                'component_analyses_are_context_not_aggregate_proof'
            ],
        );
        $this->assertNull(
            $array['component_signal_priority_or_winner'],
        );
        $this->assertTrue($array['aggregate_discrepancy_unresolved']);
        $this->assertTrue($array['settlement_review_required']);
        $this->assertTrue($array['explicit_decision']);
        $this->assertFalse(
            $array['generic_numerical_decision_evidence_created'],
        );
        $this->assertFalse($array['automatic_correction']);
        $this->assertFalse($array['business_mutation_authorized']);
        $this->assertFalse($array['payment_rewrite_authorized']);
        $this->assertFalse($array['receivable_rewrite_authorized']);
        $this->assertFalse($array['system_total_rewrite_authorized']);
        $this->assertFalse($array['override_authorization_required']);
        $this->assertFalse($array['persists_audit']);
        $this->assertFalse($array['manager_runtime_wired']);
        $this->assertFalse($array['controller_runtime_wired']);

        $this->assertTrue(
            $this->genericDecisionEvidenceRequiresAtLeastOneSignal(),
        );
    }

    public function test_reason_is_explicit_bounded_and_free_of_control_characters(): void
    {
        $runtimeEvidence = $this->runtimeEvidenceWithMissingTransportOnly();

        foreach (
            [
                '',
                ' leading',
                "control\ncharacter",
                str_repeat('x', 2049),
            ] as $invalidReason
        ) {
            try {
                CommerceSettlementDiscrepancyDecisionEvidence::
                    keepReference(
                        runtimeEvidence: $runtimeEvidence,
                        reason: $invalidReason,
                    );

                $this->fail(
                    'Invalid Commerce settlement decision reason was accepted.'
                );
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Commerce settlement decision reason must be explicit, bounded and free of control characters.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_accept_observed_and_runtime_business_effects_remain_blocked(): void
    {
        $this->assertFalse(
            method_exists(
                CommerceSettlementDiscrepancyDecisionEvidence::class,
                'acceptObserved',
            ),
        );
        $this->assertSame(
            ['KEEP_REFERENCE'],
            CommerceSettlementDiscrepancyDecisionEvidence::
                AUTHORIZED_DECISION_VALUES,
        );
        $this->assertSame(
            ['ACCEPT_OBSERVED'],
            CommerceSettlementDiscrepancyDecisionEvidence::
                BLOCKED_DECISION_VALUES,
        );

        $manager = file_get_contents(
            app_path('Domain/Commerce/CommerceCheckoutManager.php')
        );
        $controller = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );

        $this->assertIsString($manager);
        $this->assertIsString($controller);
        $this->assertStringNotContainsString(
            'CommerceSettlementDiscrepancyDecisionEvidence',
            $manager,
        );
        $this->assertStringNotContainsString(
            'CommerceSettlementDiscrepancyDecisionEvidence',
            $controller,
        );
        $this->assertStringContainsString(
            'CommerceSettlementDiscrepancyException::',
            $manager,
        );
        $this->assertStringContainsString(
            'fromCheckoutData(',
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

    private function runtimeEvidenceWithMissingTransportOnly(): CommerceSettlementDiscrepancyException
    {
        return new CommerceSettlementDiscrepancyException(
            systemTotalMinor: 10000,
            settledTotalMinor: 9000,
            observedComponentIds: ['payments.0.amount'],
            componentAnalyses: [],
            missingTransportEvidenceComponentIds: ['payments.0.amount'],
        );
    }

    private function genericDecisionEvidenceRequiresAtLeastOneSignal(): bool
    {
        try {
            NumericalDiscrepancyDecisionEvidence::keepReference(
                referenceValue: '100.00',
                observedValue: '90.00',
                signals: [],
                reason: 'Explicit generic decision.',
            );
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    }
}
