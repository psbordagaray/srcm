<?php

namespace Tests\Feature\Release;

use App\Domain\Release\EnvironmentIdentity;
use App\Domain\Release\ReleaseManifest;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class ReleaseManifestEnvironmentIdentityFoundationTest extends TestCase
{
    public function test_release_manifest_policy_is_versioned_fail_closed_and_not_runtime_wired(): void
    {
        $policy = config('release.release_manifest');

        $this->assertIsArray($policy);
        $this->assertSame(1, $policy['foundation_version']);
        $this->assertSame(ReleaseManifest::SCHEMA, $policy['schema']);
        $this->assertSame('srcm-{release_sha}.manifest.json', $policy['sidecar_filename_pattern']);
        $this->assertSame('refs/heads/main', $policy['source_ref']);
        $this->assertSame('foundation_only_not_yet_wired', $policy['executable_integration_status']);
        $this->assertTrue($policy['manifest_is_immutable']);
        $this->assertTrue($policy['manifest_is_built_before_remote_io']);
        $this->assertTrue($policy['manifest_is_sidecar_to_immutable_artifact']);
        $this->assertTrue($policy['artifact_digest_embedded_in_manifest']);
        $this->assertTrue($policy['manifest_sha256_required']);
        $this->assertTrue($policy['manifest_and_artifact_must_be_transferred_together']);
        $this->assertTrue($policy['environment_identity_required']);
        $this->assertTrue($policy['secrets_forbidden']);
        $this->assertTrue($policy['activation_requires_exact_manifest_match']);
        $this->assertTrue($policy['executable_integration_requires_separate_reviewed_cut']);

        $result = app(ReleasePreflightInspector::class)->inspect();
        $this->assertTrue($result['static']['p13_release_manifest_policy_contract']);
        $this->assertFalse($result['production_authorized']);
        $this->assertFalse(config('release.production_release_enabled'));
    }

    public function test_environment_identity_policy_preserves_current_node_evidence_without_inventing_runtime_ids(): void
    {
        $policy = config('release.environment_identity');

        $this->assertIsArray($policy);
        $this->assertSame(1, $policy['foundation_version']);
        $this->assertSame(EnvironmentIdentity::SCHEMA, $policy['schema']);
        $this->assertSame('production', $policy['environment_id']);
        $this->assertSame(EnvironmentIdentity::SCOPE_INSTALLATION, $policy['organization_scope']);
        $this->assertNull($policy['organization_id']);
        $this->assertSame('straleon-prod-01', $policy['stable_node_name']);
        $this->assertSame('refs/heads/main', $policy['protected_ref']);
        $this->assertSame('/srv/srcm/shared/release/environment-identity.json', $policy['identity_file_path']);
        $this->assertSame('protected_runtime_identity_file', $policy['installation_id_source']);
        $this->assertSame('protected_runtime_identity_file', $policy['deployment_generation_source']);
        $this->assertSame(1, $policy['deployment_generation_minimum']);
        $this->assertSame('not_yet_provisioned', $policy['runtime_binding_status']);
        $this->assertTrue($policy['runtime_binding_requires_separate_reviewed_cut']);

        $result = app(ReleasePreflightInspector::class)->inspect();
        $this->assertTrue($result['static']['p13_environment_identity_policy_contract']);
        $this->assertFalse($result['production_authorized']);
    }

    public function test_environment_identity_is_explicit_canonical_and_deterministic(): void
    {
        $identity = new EnvironmentIdentity(
            environmentId: 'production',
            installationId: 'straleon-installation-001',
            organizationScope: EnvironmentIdentity::SCOPE_INSTALLATION,
            organizationId: null,
            deploymentGeneration: 7,
            stableNodeName: 'straleon-prod-01',
        );

        $this->assertSame([
            'schema' => EnvironmentIdentity::SCHEMA,
            'environment_id' => 'production',
            'installation_id' => 'straleon-installation-001',
            'organization_scope' => 'installation',
            'organization_id' => null,
            'deployment_generation' => 7,
            'stable_node_name' => 'straleon-prod-01',
        ], $identity->toArray());

        $same = new EnvironmentIdentity(
            'production',
            'straleon-installation-001',
            EnvironmentIdentity::SCOPE_INSTALLATION,
            null,
            7,
            'straleon-prod-01',
        );

        $this->assertSame(64, strlen($identity->fingerprint()));
        $this->assertSame($identity->fingerprint(), $same->fingerprint());
    }

    public function test_environment_identity_requires_valid_scope_and_monotonic_generation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnvironmentIdentity(
            'production',
            'straleon-installation-001',
            EnvironmentIdentity::SCOPE_INSTALLATION,
            null,
            0,
            'straleon-prod-01',
        );
    }

    public function test_release_manifest_binds_exact_release_artifact_and_environment_fingerprint(): void
    {
        $identity = new EnvironmentIdentity(
            'production',
            'straleon-installation-001',
            EnvironmentIdentity::SCOPE_INSTALLATION,
            null,
            3,
            'straleon-prod-01',
        );

        $manifest = new ReleaseManifest(
            str_repeat('a', 40),
            str_repeat('b', 64),
            'refs/heads/main',
            $identity,
        );

        $payload = $manifest->toArray();
        $this->assertSame(ReleaseManifest::SCHEMA, $payload['schema']);
        $this->assertSame(str_repeat('a', 40), $payload['release_sha']);
        $this->assertSame(str_repeat('b', 64), $payload['artifact_sha256']);
        $this->assertSame('refs/heads/main', $payload['source_ref']);
        $this->assertSame($identity->toArray(), $payload['environment_identity']);
        $this->assertSame($identity->fingerprint(), $payload['environment_fingerprint']);
        $this->assertSame(64, strlen($manifest->fingerprint()));

        $same = new ReleaseManifest(
            str_repeat('a', 40),
            str_repeat('b', 64),
            'refs/heads/main',
            $identity,
        );
        $this->assertSame($manifest->fingerprint(), $same->fingerprint());
    }

    public function test_release_manifest_rejects_noncanonical_release_identity(): void
    {
        $identity = new EnvironmentIdentity(
            'production',
            'straleon-installation-001',
            EnvironmentIdentity::SCOPE_INSTALLATION,
            null,
            1,
        );

        $this->expectException(InvalidArgumentException::class);

        new ReleaseManifest(
            'ABC123',
            str_repeat('b', 64),
            'refs/heads/main',
            $identity,
        );
    }

    public function test_ci_preflight_exposes_both_p13_foundation_gates_without_authorizing_production(): void
    {
        $this->artisan('srcm:release-preflight --ci')
            ->expectsOutputToContain('STATIC_P13_RELEASE_MANIFEST_POLICY_CONTRACT=GREEN')
            ->expectsOutputToContain('STATIC_P13_ENVIRONMENT_IDENTITY_POLICY_CONTRACT=GREEN')
            ->expectsOutputToContain('PRODUCTION_RELEASE_AUTHORIZED=NO')
            ->expectsOutputToContain('PRODUCTION_REMAINS_BLOCKED=YES')
            ->assertSuccessful();
    }
}
