<?php

namespace Tests\Feature\Release;

use App\Domain\Release\ReleasePreflightInspector;
use Tests\TestCase;

final class ProductionDeploymentFoundationTest extends TestCase
{
    public function test_deployment_contract_is_versioned_but_authorization_remains_fail_closed(): void
    {
        $deployment = config('release.deployment');

        $this->assertIsArray($deployment);
        $this->assertSame(2, $deployment['foundation_version']);
        $this->assertSame('production', $deployment['environment']);
        $this->assertSame('linux_vps_single_host', $deployment['target_class']);
        $this->assertSame('github_actions_ssh', $deployment['transport']);
        $this->assertTrue($deployment['manual_dispatch_required']);
        $this->assertSame(
            'immutable_release_dirs_current_symlink_shared_state',
            $deployment['release_layout']
        );
        $this->assertSame('/srv/srcm/shared/.env', $deployment['shared_dotenv']);
        $this->assertSame(
            '/srv/srcm/shared/database/database.sqlite',
            $deployment['shared_sqlite']
        );
        $this->assertFalse($deployment['target_node_required']);
        $this->assertFalse($deployment['target_composer_required']);
        $this->assertFalse($deployment['automatic_database_rollback']);
        $this->assertTrue($deployment['automatic_code_symlink_rollback']);

        $bootstrap = $deployment['initial_application_release'];
        $this->assertSame(1, $bootstrap['foundation_version']);
        $this->assertSame('one_time_inactive_bootstrap', $bootstrap['mode']);
        $this->assertSame(
            'initial_application_release_bootstrap_enabled',
            $bootstrap['authorization_switch']
        );
        $this->assertTrue($bootstrap['requires_current_absent']);
        $this->assertTrue($bootstrap['requires_releases_directory_empty']);
        $this->assertTrue($bootstrap['artifact_built_in_github_actions']);
        $this->assertTrue($bootstrap['artifact_build_is_pre_authorization']);
        $this->assertTrue($bootstrap['remote_install_is_environment_protected']);
        $this->assertSame(
            'b07434ffcaaea6c1be8373b2187e725dceb70be40bfbdc3571af5df5ba85595e',
            $bootstrap['expected_database_sha256']
        );
        $this->assertSame(3694592, $bootstrap['expected_database_size_bytes']);
        $this->assertSame(122, $bootstrap['expected_applied_migrations']);
        $this->assertFalse($bootstrap['migration_allowed']);
        $this->assertFalse($bootstrap['creates_current_symlink']);
        $this->assertFalse($bootstrap['starts_services']);
        $this->assertFalse($bootstrap['public_readiness_check']);
        $this->assertTrue($bootstrap['activation_is_separate_cut']);

        $this->assertFalse(config('release.initial_application_release_bootstrap_enabled'));
        $this->assertFalse(config('release.production_release_enabled'));
        $this->assertFalse(
            config('release.external_gates.production_environment_secrets_and_approvals')
        );
    }

    public function test_ci_covers_feature_and_default_branches_before_main_promotion(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));
        $this->assertIsString($workflow);

        $normalized = str_replace("\r\n", "\n", $workflow);
        $this->assertStringContainsString(
            "  push:\n    branches:\n      - feature/core-entity\n      - main\n",
            $normalized
        );
        $this->assertStringContainsString(
            "  pull_request:\n    branches:\n      - feature/core-entity\n      - main\n",
            $normalized
        );

        $result = app(ReleasePreflightInspector::class)->inspect();
        $this->assertTrue($result['static']['ci_default_branch_push_coverage']);
        $this->assertTrue($result['static']['ci_default_branch_pull_request_coverage']);
    }

    public function test_production_workflow_is_manual_protected_and_source_blocked_before_remote_io(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/deploy-production.yml'));
        $this->assertIsString($workflow);

        $this->assertStringContainsString('workflow_dispatch:', $workflow);
        $this->assertDoesNotMatchRegularExpression('/^\s{2}(push|pull_request|schedule):/m', $workflow);
        $this->assertStringContainsString('environment: production', $workflow);
        $this->assertStringContainsString('group: srcm-production-deploy', $workflow);
        $this->assertStringContainsString('cancel-in-progress: false', $workflow);
        $this->assertStringContainsString('confirmation', $workflow);
        $this->assertStringContainsString('test "$CONFIRMATION" = "DEPLOY"', $workflow);
        $this->assertStringContainsString(
            'actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683',
            $workflow
        );
        $this->assertStringContainsString(
            'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240',
            $workflow
        );

        $authorization = strpos($workflow, 'Authorization boundary - fail closed before remote IO');
        $ssh = strpos($workflow, 'Configure SSH transport');
        $this->assertIsInt($authorization);
        $this->assertIsInt($ssh);
        $this->assertLessThan($ssh, $authorization);

        $this->assertStringContainsString(
            'production_environment_secrets_and_approvals',
            $workflow
        );
        $this->assertStringContainsString('production_release_enabled', $workflow);
        $this->assertStringContainsString(
            'sha256sum "srcm-${RELEASE_SHA}.tar.gz"',
            $workflow
        );
        $this->assertStringNotContainsString(
            'sha256sum "$RUNNER_TEMP/srcm-${RELEASE_SHA}.tar.gz"',
            $workflow
        );
        $this->assertStringContainsString('SRCM_DEPLOY_SSH_PRIVATE_KEY', $workflow);
        $this->assertStringContainsString('SRCM_DEPLOY_KNOWN_HOSTS', $workflow);

        foreach ([
            'SRCM_MERCADO_PAGO_CONNECTION_SECRETS_JSON',
            'SRCM_ARCA_WSAA_CREDENTIAL_REFERENCES_JSON',
            'SRCM_BACKUP_ENCRYPTION_KEY_REFERENCE',
            'SRCM_BACKUP_S3_SECRET_ACCESS_KEY',
        ] as $runtimeSecret) {
            $this->assertStringNotContainsString($runtimeSecret, $workflow);
        }
    }

    public function test_manual_production_workflows_require_protected_main_and_exact_dispatch_sha(): void
    {
        $cases = [
            ['.github/workflows/deploy-production.yml', 1],
            ['.github/workflows/bootstrap-production-initial-release.yml', 2],
        ];

        foreach ($cases as [$path, $expectedOccurrences]) {
            $workflow = file_get_contents(base_path($path));
            $this->assertIsString($workflow);

            foreach ([
                'test "$GITHUB_REF_TYPE" = "branch"',
                'test "$GITHUB_REF_NAME" = "main"',
                'test "$GITHUB_REF_PROTECTED" = "true"',
                'test "${RELEASE_SHA_INPUT,,}" = "${GITHUB_SHA,,}"',
            ] as $guard) {
                $this->assertSame(
                    $expectedOccurrences,
                    substr_count($workflow, $guard),
                    $path.' missing protected dispatch identity guard: '.$guard
                );
            }
        }
    }

    public function test_production_remote_workflows_resolve_and_prove_stable_tailscale_node_identity_before_ssh(): void
    {
        $cases = [
            [
                '.github/workflows/deploy-production.yml',
                'Authorization boundary - fail closed before remote IO',
            ],
            [
                '.github/workflows/bootstrap-production-initial-release.yml',
                'Authorization boundary - fail closed before bootstrap remote IO',
            ],
        ];

        foreach ($cases as [$path, $authorizationBoundary]) {
            $workflow = file_get_contents(base_path($path));
            $this->assertIsString($workflow);

            foreach ([
                'id-token: write',
                'tailscale/github-action@780049a30b6ff5c378a9e7b389d15ece7a204888',
                '${{ secrets.TS_OAUTH_CLIENT_ID }}',
                '${{ secrets.TS_AUDIENCE }}',
                'tag:straleon-ci-deploy',
                'test "$DEPLOY_HOST" = "straleon-prod-01"',
                'test "$DEPLOY_USER" = "straleon-deploy"',
                'test "$DEPLOY_PORT" = "22"',
                'tailscale ip --4 "$DEPLOY_HOST"',
                'tailscale whois --json "$resolved_deploy_ip"',
                '.Node.Name // empty',
                'tag:straleon-prod',
                '(.Node.Tags // [])',
                'ip route get "$resolved_deploy_ip"',
                'dev tailscale0',
                'RESOLVED_DEPLOY_IP',
                'known_key_material=',
                'SHA256:x6L1N7kD+rcrlqD7EB+boZgwDQc4AtO6NMMltEHZhpw',
                'SHA256:iy4hCZtEYlqi3MjSxLFmX7cKPTFXXfecZultd7c2Xj4',
            ] as $required) {
                $this->assertStringContainsString($required, $workflow, $path);
            }

            $this->assertMatchesRegularExpression(
                '/(?m)^\h+test "\$DEPLOY_PORT" = "22"\h*\r?$/',
                $workflow,
                $path
            );
            $this->assertMatchesRegularExpression(
                '/(?m)^\h+test -n "\$SSH_PRIVATE_KEY"\h*\r?$/',
                $workflow,
                $path
            );

            if ($path === '.github/workflows/deploy-production.yml') {
                $this->assertMatchesRegularExpression(
                    '/(?m)^\h+\[\[ "\$READINESS_URL" =~ \^https:\/\/ \]\]\h*\r?$/',
                    $workflow,
                    $path
                );
            }

            $this->assertStringNotContainsString('64.176.3.12', $workflow, $path);
            $this->assertStringNotContainsString('100.64.245.55', $workflow, $path);
            $this->assertSame(
                1,
                substr_count(
                    $workflow,
                    'tailscale/github-action@780049a30b6ff5c378a9e7b389d15ece7a204888'
                ),
                $path
            );

            $authorization = strpos($workflow, $authorizationBoundary);
            $tailscale = strpos(
                $workflow,
                'tailscale/github-action@780049a30b6ff5c378a9e7b389d15ece7a204888'
            );
            $resolve = strpos($workflow, 'tailscale ip --4 "$DEPLOY_HOST"');
            $whois = strpos($workflow, 'tailscale whois --json "$resolved_deploy_ip"');
            $route = strpos($workflow, 'ip route get "$resolved_deploy_ip"');
            $ssh = strpos($workflow, 'Configure SSH transport');

            foreach ([$authorization, $tailscale, $resolve, $whois, $route, $ssh] as $position) {
                $this->assertIsInt($position, $path);
            }

            $this->assertTrue($authorization < $tailscale, $path);
            $this->assertTrue($tailscale < $resolve, $path);
            $this->assertTrue($resolve < $whois, $path);
            $this->assertTrue($whois < $route, $path);
            $this->assertTrue($route < $ssh, $path);
        }
    }

    public function test_tailscale_safe_smoke_proves_stable_node_identity_without_private_ip_literal(): void
    {
        $workflow = file_get_contents(
            base_path('.github/workflows/tailscale-private-ssh-auth-smoke.yml')
        );
        $this->assertIsString($workflow);

        foreach ([
            'Resolve and verify stable production node identity',
            '${{ vars.SRCM_DEPLOY_HOST }}',
            'test "$DEPLOY_HOST" = "straleon-prod-01"',
            'tailscale ip --4 "$DEPLOY_HOST"',
            'tailscale whois --json "$resolved_deploy_ip"',
            '.Node.Name // empty',
            'tag:straleon-prod',
            '(.Node.Tags // [])',
            'ip route get "$resolved_deploy_ip"',
            'RESOLVED_DEPLOY_IP',
            'known_key_material=',
            'TARGET_IP_POLICY=GREEN_RUNTIME_RESOLVED_NOT_PINNED',
            'test "$(hostname)" = "straleon-prod-01"',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        $this->assertStringNotContainsString('64.176.3.12', $workflow);
        $this->assertStringNotContainsString('100.64.245.55', $workflow);
    }
    public function test_initial_application_bootstrap_workflow_builds_before_a_separate_fail_closed_remote_boundary(): void
    {
        $workflow = file_get_contents(
            base_path('.github/workflows/bootstrap-production-initial-release.yml')
        );
        $this->assertIsString($workflow);

        $this->assertStringContainsString('workflow_dispatch:', $workflow);
        $this->assertDoesNotMatchRegularExpression('/^\s{2}(push|pull_request|schedule):/m', $workflow);
        $this->assertStringContainsString('environment: production', $workflow);
        $this->assertStringContainsString('group: srcm-production-initial-bootstrap', $workflow);
        $this->assertStringContainsString('cancel-in-progress: false', $workflow);
        $this->assertStringContainsString('test "$CONFIRMATION" = "BOOTSTRAP"', $workflow);
        $this->assertStringContainsString(
            'actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683',
            $workflow
        );
        $this->assertStringContainsString(
            'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240',
            $workflow
        );
        $this->assertStringContainsString('php artisan srcm:release-preflight --ci', $workflow);
        $this->assertStringContainsString('npm run build', $workflow);
        $this->assertStringContainsString('composer test', $workflow);

        $buildJob = strpos($workflow, '  build-initial-release-artifact:');
        $artifact = strpos($workflow, 'Build immutable initial release artifact');
        $upload = strpos(
            $workflow,
            'actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02'
        );
        $installJob = strpos($workflow, '  install-inactive-initial-release:');
        $environment = strpos($workflow, '    environment: production');
        $download = strpos(
            $workflow,
            'actions/download-artifact@d3f86a106a0bac45b974a628896c90dbdf5c8093'
        );
        $authorization = strpos(
            $workflow,
            'Authorization boundary - fail closed before bootstrap remote IO'
        );
        $ssh = strpos($workflow, 'Configure SSH transport');
        foreach ([
            $buildJob,
            $artifact,
            $upload,
            $installJob,
            $environment,
            $download,
            $authorization,
            $ssh,
        ] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertTrue($buildJob < $artifact);
        $this->assertTrue($artifact < $upload);
        $this->assertTrue($upload < $installJob);
        $this->assertTrue($installJob < $environment);
        $this->assertTrue($environment < $download);
        $this->assertTrue($download < $authorization);
        $this->assertTrue($authorization < $ssh);
        $this->assertStringContainsString('needs: build-initial-release-artifact', $workflow);

        $this->assertStringContainsString(
            'initial_application_release_bootstrap_enabled',
            $workflow
        );
        $this->assertStringContainsString(
            'production_environment_secrets_and_approvals',
            $workflow
        );
        $this->assertStringContainsString('$normalRelease === false', $workflow);
        $this->assertStringContainsString(
            '($policy["artifact_build_is_pre_authorization"] ?? null) === true',
            $workflow
        );
        $this->assertStringContainsString(
            '($policy["remote_install_is_environment_protected"] ?? null) === true',
            $workflow
        );
        $this->assertStringContainsString('($policy["migration_allowed"] ?? null) === false', $workflow);
        $this->assertStringContainsString(
            '($policy["creates_current_symlink"] ?? null) === false',
            $workflow
        );
        $this->assertStringContainsString('($policy["starts_services"] ?? null) === false', $workflow);
        $this->assertStringContainsString(
            'sha256sum "$artifact" > "$artifact.sha256"',
            $workflow
        );
        $this->assertStringNotContainsString(
            'sha256sum "$RUNNER_TEMP/$artifact"',
            $workflow
        );
        $this->assertStringContainsString('SRCM_DEPLOY_SSH_PRIVATE_KEY', $workflow);
        $this->assertStringContainsString('SRCM_DEPLOY_KNOWN_HOSTS', $workflow);

        foreach ([
            'SRCM_MERCADO_PAGO_CONNECTION_SECRETS_JSON',
            'SRCM_ARCA_WSAA_CREDENTIAL_REFERENCES_JSON',
            'SRCM_ARCA_WSAA_CREDENTIAL_ROOT',
            'SRCM_BACKUP_ENCRYPTION_KEY_REFERENCE',
            'SRCM_BACKUP_S3_ACCESS_KEY_ID',
            'SRCM_BACKUP_S3_SECRET_ACCESS_KEY',
        ] as $runtimeSecret) {
            $this->assertStringNotContainsString($runtimeSecret, $workflow);
        }
    }

    public function test_initial_application_bootstrap_installs_only_an_inactive_verified_release(): void
    {
        $script = file_get_contents(base_path('ops/production/bootstrap-initial-release.sh'));
        $this->assertIsString($script);

        foreach ([
            'ROOT=/srv/srcm',
            'RELEASES=/srv/srcm/releases',
            'CURRENT=/srv/srcm/current',
            'SHARED=/srv/srcm/shared',
            'initial_current_must_be_absent',
            'initial_releases_directory_must_be_empty',
            'EXPECTED_DB_SHA256=b07434ffcaaea6c1be8373b2187e725dceb70be40bfbdc3571af5df5ba85595e',
            'EXPECTED_DB_SIZE=3694592',
            'EXPECTED_MIGRATIONS=122',
            'PRAGMA query_only=ON',
            'PRAGMA quick_check',
            'PRAGMA integrity_check',
            'PRAGMA foreign_key_check',
            'php artisan srcm:release-preflight --ci',
            'php artisan optimize',
            'mv "$incoming_release" "$final_release"',
            'SRCM_INITIAL_BOOTSTRAP_CURRENT=ABSENT',
            'SRCM_INITIAL_BOOTSTRAP_SERVICES=INACTIVE',
            'SRCM_INITIAL_BOOTSTRAP_MIGRATE=NO',
        ] as $required) {
            $this->assertStringContainsString($required, $script);
        }

        foreach ([
            'php artisan migrate',
            'systemctl start',
            'systemctl restart',
            'systemctl reload',
            'systemctl enable',
            'ln -s "$final_release" "$CURRENT"',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
    }

    public function test_target_activation_contract_keeps_shared_state_outside_immutable_releases(): void
    {
        $script = file_get_contents(base_path('ops/production/deploy-release.sh'));
        $this->assertIsString($script);

        $this->assertStringContainsString('ROOT=/srv/srcm', $script);
        $this->assertStringContainsString('RELEASES=/srv/srcm/releases', $script);
        $this->assertStringContainsString('CURRENT=/srv/srcm/current', $script);
        $this->assertStringContainsString('SHARED=/srv/srcm/shared', $script);
        $this->assertStringContainsString('shared_sqlite_missing', $script);
        $this->assertStringContainsString('initial_production_cutover_must_be_separate', $script);
        $this->assertStringContainsString('api/health/ready', $script);
        $this->assertStringContainsString('php artisan migrate --force', $script);
        $this->assertStringNotContainsString('migrate:rollback', $script);
        $this->assertStringContainsString('srcm-queue.service', $script);
        $this->assertStringContainsString('php8.3-fpm.service', $script);
    }

    public function test_linux_runtime_templates_cover_web_queue_and_scheduler_without_node_or_composer(): void
    {
        $queue = file_get_contents(base_path('ops/production/systemd/srcm-queue.service'));
        $scheduler = file_get_contents(base_path('ops/production/systemd/srcm-schedule.service'));
        $timer = file_get_contents(base_path('ops/production/systemd/srcm-schedule.timer'));
        $nginx = file_get_contents(base_path('ops/production/nginx/srcm.conf'));

        $this->assertIsString($queue);
        $this->assertIsString($scheduler);
        $this->assertIsString($timer);
        $this->assertIsString($nginx);
        $this->assertStringContainsString('artisan queue:work database', $queue);
        $this->assertStringContainsString('artisan schedule:run', $scheduler);
        $this->assertStringContainsString('OnCalendar=*-*-* *:*:00', $timer);
        $this->assertStringContainsString('/srv/srcm/current/public', $nginx);
        $this->assertStringContainsString('/run/php/php8.3-fpm.sock', $nginx);
        $this->assertStringContainsString('__SRCM_HOSTNAME__', $nginx);
        $this->assertStringNotContainsString('node ', $queue.$scheduler.$timer.$nginx);
        $this->assertStringNotContainsString('composer ', $queue.$scheduler.$timer.$nginx);
    }

    public function test_release_preflight_requires_the_new_foundation_but_still_denies_production(): void
    {
        $result = app(ReleasePreflightInspector::class)->inspect();

        foreach ([
            'ci_default_branch_push_coverage',
            'ci_default_branch_pull_request_coverage',
            'production_deploy_workflow',
            'production_deploy_manual_only',
            'production_deploy_protected_main_dispatch_identity',
            'production_deploy_environment_gate',
            'production_deploy_concurrency_gate',
            'production_deploy_dual_source_authorization',
            'production_deploy_relative_checksum_contract',
            'production_deploy_runtime_secrets_excluded',
            'production_deploy_private_tailscale_transport',
            'immutable_release_activation_contract',
            'production_initial_bootstrap_workflow',
            'production_initial_bootstrap_manual_only',
            'production_initial_bootstrap_protected_main_dispatch_identity',
            'production_initial_bootstrap_environment_gate',
            'production_initial_bootstrap_concurrency_gate',
            'production_initial_bootstrap_pre_authorization_artifact_handoff',
            'production_initial_bootstrap_policy_contract',
            'production_initial_bootstrap_source_authorization',
            'production_initial_bootstrap_relative_checksum_contract',
            'production_initial_bootstrap_runtime_secrets_excluded',
            'production_initial_bootstrap_private_tailscale_transport',
            'immutable_initial_bootstrap_contract',
            'production_runtime_units',
        ] as $gate) {
            $this->assertTrue($result['static'][$gate], $gate.' must be green');
        }

        $this->assertTrue($result['static_green']);
        $this->assertFalse($result['external_green']);
        $this->assertFalse($result['production_authorized']);
    }
}
