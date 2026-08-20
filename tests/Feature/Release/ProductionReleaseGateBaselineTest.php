<?php

namespace Tests\Feature\Release;

use App\Domain\Release\ReleasePreflightInspector;
use Tests\TestCase;

final class ProductionReleaseGateBaselineTest extends TestCase
{
    public function test_release_config_is_explicitly_fail_closed(): void
    {
        $this->assertFalse(config('release.production_release_enabled'));
        $this->assertFalse(config('release.external_gates.off_host_encrypted_backup'));
        $this->assertFalse(config('release.external_gates.operational_restore_drill'));
        $this->assertFalse(
            config('release.external_gates.production_environment_secrets_and_approvals')
        );
    }

    public function test_ci_workflow_contains_locked_quality_gates_and_pinned_checkout(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));
        $this->assertIsString($workflow);
        $this->assertStringContainsString(
            'actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683',
            $workflow
        );
        $this->assertStringContainsString('permissions:', $workflow);
        $this->assertStringContainsString('contents: read', $workflow);
        $this->assertStringContainsString('composer install', $workflow);
        $this->assertStringContainsString('npm ci --ignore-scripts', $workflow);
        $this->assertStringContainsString('git diff --check', $workflow);
        $this->assertStringContainsString('composer test', $workflow);
        $this->assertStringContainsString('npm run build', $workflow);
        $this->assertStringContainsString('srcm:release-preflight --ci', $workflow);
        $this->assertStringNotContainsString('artisan migrate --force', $workflow);
        $this->assertStringNotContainsString('artisan migrate:rollback', $workflow);
        $this->assertStringNotContainsString('deploy', strtolower($workflow));
    }

    public function test_migration_precheck_is_read_only_and_all_current_migrations_are_reversible(): void
    {
        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertGreaterThanOrEqual(122, $result['migration_files_count']);
        $this->assertSame([], $result['irreversible_migrations']);
        $this->assertTrue($result['static']['all_migrations_have_non_empty_down']);
    }

    public function test_post_deploy_readiness_contract_points_to_existing_route(): void
    {
        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertSame('api.health.ready', config('release.post_deploy_readiness.route_name'));
        $this->assertSame('api/health/ready', config('release.post_deploy_readiness.uri'));
        $this->assertSame('GET', config('release.post_deploy_readiness.method'));
        $this->assertTrue($result['static']['post_deploy_readiness_contract']);
    }

    public function test_ci_preflight_is_green_but_does_not_authorize_production(): void
    {
        $this->artisan('srcm:release-preflight --ci')
            ->expectsOutputToContain('SRCM_RELEASE_PREFLIGHT_CI=GREEN')
            ->expectsOutputToContain('PRODUCTION_RELEASE_AUTHORIZED=NO')
            ->expectsOutputToContain('PRODUCTION_REMAINS_BLOCKED=YES')
            ->assertSuccessful();
    }

    public function test_default_production_preflight_remains_blocked(): void
    {
        $this->artisan('srcm:release-preflight')
            ->expectsOutputToContain('PRODUCTION_RELEASE_AUTHORIZED=NO')
            ->expectsOutputToContain('SRCM_PRODUCTION_RELEASE_PREFLIGHT=BLOCKED')
            ->assertFailed();
    }
}
