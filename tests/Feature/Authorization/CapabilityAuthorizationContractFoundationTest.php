<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\Capability;
use App\Domain\Authorization\CapabilityAuthorizationContract;
use App\Domain\Authorization\CapabilityDecision;
use App\Domain\Authorization\CapabilityPrincipal;
use App\Domain\Authorization\CapabilityScope;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class CapabilityAuthorizationContractFoundationTest extends TestCase
{
    public function test_policy_is_versioned_fail_closed_and_not_runtime_wired(): void
    {
        $policy = config('release.capability_authorization');

        $this->assertIsArray($policy);
        $this->assertSame(1, $policy['foundation_version']);
        $this->assertSame(
            CapabilityAuthorizationContract::SCHEMA,
            $policy['schema']
        );
        $this->assertSame(
            CapabilityAuthorizationContract::REQUIRED_FIELDS,
            $policy['required_fields']
        );
        $this->assertSame(
            'namespaced_immutable_value_object',
            $policy['capability_identifier_model']
        );
        $this->assertSame(Capability::PATTERN, $policy['capability_identifier_pattern']);
        $this->assertFalse($policy['wildcard_capabilities_allowed']);
        $this->assertTrue($policy['unknown_or_invalid_capability_fails_closed']);
        $this->assertSame(CapabilityScope::values(), $policy['scope_values']);
        $this->assertTrue($policy['scope_must_be_explicit']);
        $this->assertSame(CapabilityPrincipal::values(), $policy['principal_values']);
        $this->assertFalse($policy['anonymous_principal_allowed']);
        $this->assertTrue($policy['principal_id_required']);
        $this->assertFalse($policy['principal_secret_material_allowed']);
        $this->assertSame(CapabilityDecision::values(), $policy['decision_values']);
        $this->assertSame(CapabilityDecision::Deny->value, $policy['default_or_missing_decision']);
        $this->assertTrue($policy['allow_requires_authorization_source']);
        $this->assertTrue($policy['allow_requires_evidence_ref']);
        $this->assertTrue($policy['contract_is_immutable']);
        $this->assertTrue($policy['contract_sha256_required']);
        $this->assertFalse($policy['application_admin_role_alone_can_authorize_production']);
        $this->assertFalse($policy['global_admin_bypass_allowed']);
        $this->assertSame(
            'foundation_only_not_yet_wired',
            $policy['runtime_wiring_status']
        );
        $this->assertTrue($policy['runtime_wiring_requires_separate_reviewed_cut']);

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_capability_authorization_policy_contract']
        );
        $this->assertFalse($result['production_authorized']);
        $this->assertFalse(config('release.production_release_enabled'));
    }

    public function test_capability_identifier_is_namespaced_lowercase_and_forbids_wildcards(): void
    {
        $this->assertSame(
            'inventory.adjust',
            (string) new Capability('inventory.adjust')
        );

        foreach (['inventory', 'Inventory.adjust', 'inventory.*', 'inventory..adjust'] as $invalid) {
            try {
                new Capability($invalid);
                $this->fail("Capability [{$invalid}] must fail closed.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_scope_principal_and_decision_values_are_exact(): void
    {
        $this->assertSame([
            'ORGANIZATION',
            'INSTALLATION',
            'ENVIRONMENT',
            'RELEASE',
        ], CapabilityScope::values());

        $this->assertSame([
            'USER',
            'EXTERNAL_REVIEWER',
            'AUTOMATION',
            'SYSTEM_OPERATOR',
        ], CapabilityPrincipal::values());

        $this->assertSame([
            'ALLOW',
            'DENY',
        ], CapabilityDecision::values());
    }

    public function test_missing_decision_defaults_to_deny(): void
    {
        $data = $this->organizationContractData();
        unset($data['decision']);

        $contract = CapabilityAuthorizationContract::fromArray($data);

        $this->assertSame(CapabilityDecision::Deny, $contract->decision);
        $this->assertSame('DENY', $contract->toArray()['decision']);
    }

    public function test_allow_requires_explicit_authorization_source_and_evidence(): void
    {
        $data = $this->organizationContractData();
        $data['decision'] = 'ALLOW';
        $data['authorization_source'] = null;
        $data['evidence_ref'] = null;

        $this->expectException(InvalidArgumentException::class);

        CapabilityAuthorizationContract::fromArray($data);
    }

    public function test_scope_binding_is_explicit_and_exact(): void
    {
        $data = $this->organizationContractData();
        $data['scope_id'] = '8';

        $this->expectException(InvalidArgumentException::class);

        CapabilityAuthorizationContract::fromArray($data);
    }

    public function test_schema_mismatch_fails_closed(): void
    {
        $data = $this->organizationContractData();
        $data['schema'] = 'straleon.capability-authorization.v0';

        $this->expectException(InvalidArgumentException::class);

        CapabilityAuthorizationContract::fromArray($data);
    }

    public function test_unknown_or_extra_fields_fail_closed(): void
    {
        $data = $this->organizationContractData();
        $data['uncontracted'] = true;

        $this->expectException(InvalidArgumentException::class);

        CapabilityAuthorizationContract::fromArray($data);
    }

    public function test_canonical_serialization_is_exact_and_fingerprint_is_deterministic(): void
    {
        $data = $this->organizationContractData();
        $data['decision'] = 'ALLOW';
        $data['authorization_source'] = 'organization-role:admin';
        $data['evidence_ref'] = 'membership:42';

        $first = CapabilityAuthorizationContract::fromArray($data);
        $second = CapabilityAuthorizationContract::fromArray($data);

        $this->assertSame(
            CapabilityAuthorizationContract::REQUIRED_FIELDS,
            array_keys($first->toArray())
        );
        $this->assertSame($first->canonicalJson(), $second->canonicalJson());
        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->fingerprint());
    }

    public function test_application_admin_and_production_review_authority_remain_separate(): void
    {
        $policy = config('release.capability_authorization');

        $this->assertTrue(
            $policy['application_user_role_is_authorization_input_not_capability_id']
        );
        $this->assertTrue($policy['laravel_gate_is_runtime_adapter_not_contract']);
        $this->assertTrue(
            $policy['production_environment_review_is_external_authority_not_application_role']
        );
        $this->assertFalse($policy['application_admin_role_alone_can_authorize_production']);
        $this->assertTrue($policy['authentication_and_authorization_are_separate']);
        $this->assertFalse($policy['global_admin_bypass_allowed']);
        $this->assertTrue(
            $policy['provider_device_capabilities_are_not_principal_authorization']
        );
        $this->assertSame('not_in_foundation_cut', $policy['user_role_refactor_status']);
        $this->assertSame('not_in_foundation_cut', $policy['laravel_gate_rewiring_status']);
        $this->assertSame('not_in_foundation_cut', $policy['production_workflow_wiring_status']);
        $this->assertSame('not_in_foundation_cut', $policy['deploy_script_wiring_status']);
    }

    /** @return array<string, mixed> */
    private function organizationContractData(): array
    {
        return [
            'schema' => CapabilityAuthorizationContract::SCHEMA,
            'capability' => 'inventory.adjust',
            'principal_type' => 'USER',
            'principal_id' => '42',
            'scope_type' => 'ORGANIZATION',
            'scope_id' => '7',
            'environment_id' => null,
            'organization_id' => 7,
            'release_sha' => null,
            'decision' => 'DENY',
            'authorization_source' => null,
            'evidence_ref' => null,
        ];
    }
}
