<?php

namespace Tests\Feature\Numerics;

use App\Domain\Authorization\Capability;
use App\Domain\Authorization\CapabilityAuthorizationContract;
use App\Domain\Authorization\CapabilityDecision;
use App\Domain\Authorization\CapabilityPrincipal;
use App\Domain\Authorization\CapabilityScope;
use App\Domain\Numerics\NumericalDiscrepancyAnalyzer;
use App\Domain\Numerics\NumericalDiscrepancyDecisionEvidence;
use App\Domain\Numerics\NumericalDiscrepancyOverrideAuditEvidence;
use App\Domain\Numerics\NumericalDiscrepancyOverrideAuthorization;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class NumericalDiscrepancyOverrideAuthorizationAuditFoundationTest extends TestCase
{
    public function test_policy_defines_override_authorization_and_audit_foundation_without_business_runtime_wiring(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertIsArray($policy);
        $this->assertSame(1, $policy['override_authorization_foundation_version']);
        $this->assertSame(
            NumericalDiscrepancyOverrideAuthorization::SCHEMA,
            $policy['override_authorization_schema'],
        );
        $this->assertSame(
            NumericalDiscrepancyOverrideAuthorization::CAPABILITY,
            $policy['override_capability'],
        );
        $this->assertSame(
            NumericalDiscrepancyOverrideAuthorization::class,
            $policy['override_authorization_class'],
        );
        $this->assertSame(
            NumericalDiscrepancyOverrideAuditEvidence::SCHEMA,
            $policy['override_audit_evidence_schema'],
        );
        $this->assertSame(
            NumericalDiscrepancyOverrideAuditEvidence::class,
            $policy['override_audit_evidence_class'],
        );
        $this->assertSame(
            NumericalDiscrepancyOverrideAuditEvidence::WARNING_EVENT,
            $policy['override_warning_audit_event'],
        );
        $this->assertSame(
            NumericalDiscrepancyOverrideAuditEvidence::DECISION_EVENT,
            $policy['override_decision_audit_event'],
        );
        $this->assertTrue($policy['override_requires_capability_allow']);
        $this->assertTrue($policy['override_authorization_source_required']);
        $this->assertTrue($policy['override_authorization_evidence_ref_required']);
        $this->assertTrue($policy['override_authorization_fingerprint_required']);
        $this->assertTrue(
            $policy['override_warning_and_decision_are_separate_audit_events'],
        );
        $this->assertTrue(
            $policy['override_audit_payload_preserves_reference_observed_final'],
        );
        $this->assertSame(
            'foundation_only_not_yet_wired',
            $policy['override_audit_persistence_status'],
        );
        $this->assertSame(
            'not_in_foundation_cut',
            $policy['override_business_runtime_wiring_status'],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_exact_override_capability_allow_is_required(): void
    {
        $authorization = new NumericalDiscrepancyOverrideAuthorization(
            $this->authorizationContract(
                capability: NumericalDiscrepancyOverrideAuthorization::CAPABILITY,
                decision: CapabilityDecision::Allow,
            ),
        );

        $this->assertSame(
            NumericalDiscrepancyOverrideAuthorization::CAPABILITY,
            $authorization->authorization->capability->value,
        );
        $this->assertSame(
            CapabilityDecision::Allow,
            $authorization->authorization->decision,
        );
        $this->assertSame(
            'explicit_numeric_review',
            $authorization->authorization->authorizationSource,
        );
        $this->assertSame(
            'numeric-review:case-42',
            $authorization->authorization->evidenceRef,
        );

        $payload = $authorization->toArray();

        $this->assertSame(
            NumericalDiscrepancyOverrideAuthorization::SCHEMA,
            $payload['schema'],
        );
        $this->assertSame(
            NumericalDiscrepancyOverrideAuthorization::CAPABILITY,
            $payload['capability'],
        );
        $this->assertSame(
            $authorization->authorization->fingerprint(),
            $payload['authorization_fingerprint'],
        );
    }

    public function test_deny_or_wrong_capability_fails_closed(): void
    {
        foreach ([
            [
                NumericalDiscrepancyOverrideAuthorization::CAPABILITY,
                CapabilityDecision::Deny,
            ],
            [
                'commerce.price.override',
                CapabilityDecision::Allow,
            ],
        ] as [$capability, $decision]) {
            try {
                new NumericalDiscrepancyOverrideAuthorization(
                    $this->authorizationContract(
                        capability: $capability,
                        decision: $decision,
                    ),
                );

                $this->fail('Invalid override authorization must fail closed.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_capability_allow_requires_existing_authorization_source_and_evidence_reference_contract(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CapabilityAuthorizationContract(
            capability: new Capability(
                NumericalDiscrepancyOverrideAuthorization::CAPABILITY,
            ),
            principalType: CapabilityPrincipal::User,
            principalId: '42',
            scopeType: CapabilityScope::Organization,
            scopeId: '7',
            environmentId: null,
            organizationId: 7,
            releaseSha: null,
            decision: CapabilityDecision::Allow,
            authorizationSource: null,
            evidenceRef: null,
        );
    }

    public function test_warning_and_decision_audit_records_are_separate_deterministic_and_preserve_required_evidence(): void
    {
        $signals = NumericalDiscrepancyAnalyzer::classifierPackV1()
            ->analyze('12345', '12435');

        $decision = NumericalDiscrepancyDecisionEvidence::keepReference(
            referenceValue: '12345',
            observedValue: '12435',
            signals: $signals,
            reason: 'Reference value was independently verified.',
        );

        $authorization = new NumericalDiscrepancyOverrideAuthorization(
            $this->authorizationContract(
                capability: NumericalDiscrepancyOverrideAuthorization::CAPABILITY,
                decision: CapabilityDecision::Allow,
            ),
        );

        $audit = new NumericalDiscrepancyOverrideAuditEvidence(
            decisionEvidence: $decision,
            authorization: $authorization,
        );

        $warning = $audit->warningAuditRecord();
        $resolution = $audit->decisionAuditRecord();

        $this->assertSame(
            NumericalDiscrepancyOverrideAuditEvidence::WARNING_EVENT,
            $warning['event'],
        );
        $this->assertNull($warning['old_values']);
        $this->assertSame('12345', $warning['new_values']['reference_value']);
        $this->assertSame('12345', $warning['new_values']['original_value']);
        $this->assertSame('12435', $warning['new_values']['observed_value']);
        $this->assertCount(2, $warning['new_values']['signals']);

        $this->assertSame(
            NumericalDiscrepancyOverrideAuditEvidence::DECISION_EVENT,
            $resolution['event'],
        );
        $this->assertSame(
            '12345',
            $resolution['old_values']['original_value'],
        );
        $this->assertSame(
            '12435',
            $resolution['old_values']['observed_value'],
        );
        $this->assertSame(
            'KEEP_REFERENCE',
            $resolution['new_values']['decision'],
        );
        $this->assertSame(
            '12345',
            $resolution['new_values']['final_value'],
        );
        $this->assertSame(
            'Reference value was independently verified.',
            $resolution['new_values']['reason'],
        );
        $this->assertTrue(
            $resolution['new_values']['explicit_decision'],
        );
        $this->assertFalse(
            $resolution['new_values']['automatic_correction'],
        );
        $this->assertSame(
            $authorization->authorization->fingerprint(),
            $resolution['new_values']['authorization_fingerprint'],
        );
        $this->assertSame(
            CapabilityDecision::Allow->value,
            $resolution['new_values']['authorization']['decision'],
        );
        $this->assertSame(
            NumericalDiscrepancyOverrideAuthorization::CAPABILITY,
            $resolution['new_values']['authorization']['capability'],
        );
    }

    public function test_foundation_has_no_audit_recorder_database_or_business_runtime_dependency(): void
    {
        $authorizationBody = file_get_contents(
            app_path(
                'Domain/Numerics/NumericalDiscrepancyOverrideAuthorization.php'
            )
        );
        $auditBody = file_get_contents(
            app_path(
                'Domain/Numerics/NumericalDiscrepancyOverrideAuditEvidence.php'
            )
        );

        $this->assertIsString($authorizationBody);
        $this->assertIsString($auditBody);

        foreach ([$authorizationBody, $auditBody] as $body) {
            $this->assertStringNotContainsString('AuditRecorder', $body);
            $this->assertStringNotContainsString('AuditLog', $body);
            $this->assertStringNotContainsString('DB::', $body);
            $this->assertStringNotContainsString('CommerceSaleController', $body);
            $this->assertStringNotContainsString(
                'ServiceCancellationController',
                $body,
            );
        }
    }

    public function test_policy_keeps_business_runtime_and_special_case_blocked(): void
    {
        $policy = config('release.numeric_integrity.discrepancy_framework');

        $this->assertSame(
            'foundation_contract_defined_not_runtime_wired',
            $policy['decision_capability_authorization_wiring_status'],
        );
        $this->assertSame(
            'audit_payload_foundation_defined_not_runtime_wired',
            $policy['decision_audit_persistence_wiring_status'],
        );
        $this->assertSame(
            'not_in_foundation_cut',
            $policy['override_business_runtime_wiring_status'],
        );
        $this->assertSame(
            'undefined_no_implementation_exact_spec_required',
            $policy['transposition_by_omission_special_case_status'],
        );
        $this->assertFalse(
            $policy['transposition_by_omission_implementation_allowed'],
        );
    }

    private function authorizationContract(
        string $capability,
        CapabilityDecision $decision,
    ): CapabilityAuthorizationContract {
        return new CapabilityAuthorizationContract(
            capability: new Capability($capability),
            principalType: CapabilityPrincipal::User,
            principalId: '42',
            scopeType: CapabilityScope::Organization,
            scopeId: '7',
            environmentId: null,
            organizationId: 7,
            releaseSha: null,
            decision: $decision,
            authorizationSource: $decision === CapabilityDecision::Allow
                ? 'explicit_numeric_review'
                : null,
            evidenceRef: $decision === CapabilityDecision::Allow
                ? 'numeric-review:case-42'
                : null,
        );
    }
}