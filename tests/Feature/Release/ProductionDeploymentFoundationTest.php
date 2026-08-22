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
        $this->assertSame(1, $deployment['foundation_version']);
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

        $this->assertFalse(config('release.production_release_enabled'));
        $this->assertFalse(
            config('release.external_gates.production_environment_secrets_and_approvals')
        );
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
            'production_deploy_workflow',
            'production_deploy_manual_only',
            'production_deploy_environment_gate',
            'production_deploy_concurrency_gate',
            'production_deploy_dual_source_authorization',
            'production_deploy_relative_checksum_contract',
            'production_deploy_runtime_secrets_excluded',
            'immutable_release_activation_contract',
            'production_runtime_units',
        ] as $gate) {
            $this->assertTrue($result['static'][$gate], $gate.' must be green');
        }

        $this->assertTrue($result['static_green']);
        $this->assertFalse($result['external_green']);
        $this->assertFalse($result['production_authorized']);
    }
}
