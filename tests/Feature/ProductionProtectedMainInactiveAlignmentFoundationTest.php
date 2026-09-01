<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ProductionProtectedMainInactiveAlignmentFoundationTest extends TestCase
{
    private const TARGET_RELEASE_SHA = '3378ce249fb69e922ea218e1858e4efe8186e17d';
    private const PRIOR_AUTHORIZATION_SHA = '3d5984bee332cc6abb7f8456077db26e7998530a';
    private const FAILED_DISPATCH_RUN_ID = 33388095599;
    private const SUCCESSFUL_EXECUTION_RUN_ID = 33414463206;
    private const SUCCESSFUL_EXECUTION_INSTALL_JOB_ID = 99562174859;
    private const SUCCESSFUL_EXECUTION_AUTHORIZATION_SHA = '725082eaa23572e1e9a03da2f8f059ddabeab700';
    private const SUCCESSFUL_EXECUTION_ARTIFACT_DIGEST = 'sha256:efc113d134d2fa3f170c764a52911de7976e6751f02b210f6ba5d0f0fe6c9f96';

    public function test_alignment_authorization_is_revoked_after_execution_and_target_remains_predecessor(): void
    {
        $config = require base_path('config/release.php');

        $this->assertFalse($config['production_release_enabled']);
        $this->assertFalse($config['initial_application_release_bootstrap_enabled']);
        $this->assertFalse($config['protected_main_inactive_alignment_enabled']);
        $this->assertTrue(
            $config['external_gates']['production_environment_secrets_and_approvals']
        );

        $policy = $config['deployment']['protected_main_inactive_alignment'];

        $this->assertSame(4, $policy['foundation_version']);
        $this->assertSame('protected_main_inactive_alignment', $policy['mode']);
        $this->assertSame(
            'protected_main_inactive_alignment_enabled',
            $policy['authorization_switch']
        );
        $this->assertSame(
            self::TARGET_RELEASE_SHA,
            $policy['authorized_target_release_sha']
        );
        $this->assertSame(
            self::PRIOR_AUTHORIZATION_SHA,
            $policy['prior_authorization_sha']
        );
        $this->assertTrue(
            $policy['authorization_commit_must_directly_descend_from_prior_authorization']
        );
        $this->assertTrue(
            $policy['prior_authorization_must_directly_descend_from_target']
        );
        $this->assertSame(
            self::FAILED_DISPATCH_RUN_ID,
            $policy['failed_dispatch_run_id']
        );
        $this->assertSame(
            'SETUP_ACTION_RESOLUTION_BEFORE_ANY_WORKFLOW_STEP',
            $policy['failed_dispatch_classification']
        );
        $this->assertTrue($policy['failed_dispatch_must_not_be_rerun']);
        $this->assertTrue($policy['authorization_commit_must_not_be_installed']);
        $this->assertTrue($policy['target_release_must_remain_fail_closed']);
        $this->assertTrue($policy['revocation_required_after_execution']);
        $this->assertTrue($policy['authorization_revoked_after_execution']);
        $this->assertSame(
            self::SUCCESSFUL_EXECUTION_RUN_ID,
            $policy['successful_execution_run_id']
        );
        $this->assertSame(
            self::SUCCESSFUL_EXECUTION_INSTALL_JOB_ID,
            $policy['successful_execution_install_job_id']
        );
        $this->assertSame(
            self::SUCCESSFUL_EXECUTION_AUTHORIZATION_SHA,
            $policy['successful_execution_authorization_sha']
        );
        $this->assertSame(
            self::SUCCESSFUL_EXECUTION_ARTIFACT_DIGEST,
            $policy['successful_execution_artifact_digest']
        );
        $this->assertSame(
            'GREEN_TARGET_INSTALLED_INACTIVE_HISTORICAL_PRESERVED_CURRENT_ABSENT',
            $policy['successful_execution_state']
        );
        $this->assertSame(
            'fad6f4ff0ddcffeca5230bf3bcbb604262e55dcc',
            $policy['historical_release_sha']
        );
        $this->assertTrue($policy['artifact_built_in_github_actions']);
        $this->assertTrue($policy['artifact_build_is_pre_authorization']);
        $this->assertTrue($policy['remote_install_is_environment_protected']);
        $this->assertTrue($policy['requires_current_absent']);
        $this->assertTrue($policy['requires_historical_release_present']);
        $this->assertTrue($policy['requires_target_release_absent']);
        $this->assertTrue($policy['preserves_historical_release']);
        $this->assertTrue($policy['preserves_shared_state']);
        $this->assertFalse($policy['migration_allowed']);
        $this->assertFalse($policy['creates_current_symlink']);
        $this->assertFalse($policy['starts_or_reloads_services']);
        $this->assertTrue($policy['activation_is_separate_cut']);
    }

    public function test_alignment_workflow_installs_exact_fail_closed_predecessor_not_authorization_commit(): void
    {
        $workflow = file_get_contents(
            base_path('.github/workflows/align-production-protected-main-inactive.yml')
        );

        $this->assertIsString($workflow);

        foreach ([
            'workflow_dispatch:',
            'test "$CONFIRMATION" = "ALIGN"',
            'test "$GITHUB_REF_NAME" = "main"',
            'test "$GITHUB_REF_PROTECTED" = "true"',
            'test "${RELEASE_SHA_INPUT,,}" = "' . self::TARGET_RELEASE_SHA . '"',
            'test "${GITHUB_SHA,,}" != "${RELEASE_SHA_INPUT,,}"',
            'ref: ${{ github.sha }}',
            'fetch-depth: 3',
            'test "$(git rev-parse HEAD^)" = "' . self::PRIOR_AUTHORIZATION_SHA . '"',
            'test "$(git rev-parse HEAD^^)" = "$RELEASE_SHA"',
            'authorized_target_release_sha',
            'prior_authorization_sha',
            'authorization_commit_must_directly_descend_from_prior_authorization',
            'prior_authorization_must_directly_descend_from_target',
            'failed_dispatch_run_id',
            'failed_dispatch_classification',
            'failed_dispatch_must_not_be_rerun',
            'authorization_commit_must_not_be_installed',
            'target_release_must_remain_fail_closed',
            'revocation_required_after_execution',
            'Checkout exact fail-closed predecessor target',
            'Checkout exact fail-closed predecessor target for install',
            'ref: ${{ inputs.release_sha }}',
            'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240',
            'environment: production',
            '$alignment === true',
            '$bootstrap === false',
            '$normalRelease === false',
            '$approval === true',
            'target release authorization gates are not fail closed',
            'tags: tag:straleon-ci-deploy',
            'test "$DEPLOY_HOST" = "straleon-prod-01"',
            'test "$DEPLOY_USER" = "straleon-deploy"',
            'SHA256:x6L1N7kD+rcrlqD7EB+boZgwDQc4AtO6NMMltEHZhpw',
            'SHA256:iy4hCZtEYlqi3MjSxLFmX7cKPTFXXfecZultd7c2Xj4',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        $this->assertStringNotContainsString(
            'test "${RELEASE_SHA_INPUT,,}" = "${GITHUB_SHA,,}"',
            $workflow
        );
        $this->assertStringNotContainsString(
            'test "$(git rev-parse HEAD^)" = "$RELEASE_SHA"',
            $workflow
        );
        $this->assertStringNotContainsString(
            'shivammathur/setup-php@f3e473d116dccc5834248c87452386958240',
            $workflow
        );
    }

    public function test_remote_alignment_script_preserves_inactive_production_contract(): void
    {
        $script = file_get_contents(
            base_path('ops/production/align-protected-main-inactive-release.sh')
        );

        $this->assertIsString($script);

        foreach ([
            'EXPECTED_HISTORICAL_RELEASE=fad6f4ff0ddcffeca5230bf3bcbb604262e55dcc',
            '[[ ! -e "$CURRENT" && ! -L "$CURRENT" ]] || fail current_must_remain_absent 69',
            'assert_release_baseline',
            'assert_services_inactive',
            'assert_database_exact',
            'historical_release_lost_during_alignment',
            'historical_release_lost_after_alignment',
            'current_created_during_alignment',
            'current_created_after_alignment',
            'post_alignment_release_cardinality_mismatch',
            'SRCM_PROTECTED_MAIN_INACTIVE_ALIGNMENT_MIGRATE=NO',
            'GREEN_TARGET_INSTALLED_INACTIVE_HISTORICAL_PRESERVED',
        ] as $required) {
            $this->assertStringContainsString($required, $script);
        }

        $this->assertStringNotContainsString('php artisan migrate', $script);
        $this->assertStringNotContainsString('systemctl restart', $script);
        $this->assertStringNotContainsString('systemctl reload', $script);
        $this->assertStringNotContainsString('systemctl start', $script);
        $this->assertStringNotContainsString(
            'ln -s "$final_release" "$CURRENT"',
            $script
        );
    }
}
