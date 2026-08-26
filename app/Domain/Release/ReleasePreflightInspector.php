<?php

namespace App\Domain\Release;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ReleasePreflightInspector
{
    public function __construct(private readonly Router $router)
    {
    }

    /** @return array<string, mixed> */
    public function inspect(): array
    {
        $ciWorkflowBody = $this->fileBody(base_path('.github/workflows/ci.yml'));
        $deployWorkflowBody = $this->fileBody(base_path('.github/workflows/deploy-production.yml'));
        $deployScriptBody = $this->fileBody(base_path('ops/production/deploy-release.sh'));
        $bootstrapWorkflowBody = $this->fileBody(
            base_path('.github/workflows/bootstrap-production-initial-release.yml')
        );
        $bootstrapScriptBody = $this->fileBody(
            base_path('ops/production/bootstrap-initial-release.sh')
        );
        $queueUnitBody = $this->fileBody(base_path('ops/production/systemd/srcm-queue.service'));
        $schedulerServiceBody = $this->fileBody(base_path('ops/production/systemd/srcm-schedule.service'));
        $schedulerTimerBody = $this->fileBody(base_path('ops/production/systemd/srcm-schedule.timer'));
        $nginxBody = $this->fileBody(base_path('ops/production/nginx/srcm.conf'));

        $migrationFiles = $this->migrationFiles();
        $irreversible = [];
        foreach ($migrationFiles as $path) {
            if (! $this->hasNonEmptyDownMethod($path)) {
                $irreversible[] = basename($path);
            }
        }

        $ran = $this->ranMigrations();
        $migrationNames = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $migrationFiles
        );
        $pending = array_values(array_diff($migrationNames, $ran));
        sort($pending, SORT_STRING);

        $static = [
            'composer_lock' => is_file(base_path('composer.lock')),
            'package_lock' => is_file(base_path('package-lock.json')),
            'versioned_ci_workflow' => $ciWorkflowBody !== '',
            'ci_default_branch_push_coverage' => $this->workflowEventIncludesBranch(
                $ciWorkflowBody,
                'push',
                'main'
            ),
            'ci_default_branch_pull_request_coverage' => $this->workflowEventIncludesBranch(
                $ciWorkflowBody,
                'pull_request',
                'main'
            ),
            'ci_pinned_checkout' => str_contains(
                $ciWorkflowBody,
                'actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683'
            ),
            'ci_locked_composer_install' => str_contains($ciWorkflowBody, 'composer install'),
            'ci_locked_node_install' => str_contains($ciWorkflowBody, 'npm ci --ignore-scripts'),
            'ci_diff_check' => str_contains($ciWorkflowBody, 'git diff --check'),
            'ci_full_suite' => str_contains($ciWorkflowBody, 'composer test'),
            'ci_asset_build' => str_contains($ciWorkflowBody, 'npm run build'),
            'ci_release_preflight' => str_contains(
                $ciWorkflowBody,
                'php artisan srcm:release-preflight --ci'
            ),
            'production_deploy_workflow' => $deployWorkflowBody !== '',
            'production_deploy_manual_only' => $this->deploymentWorkflowIsManualOnly($deployWorkflowBody),
            'production_deploy_environment_gate' => str_contains(
                $deployWorkflowBody,
                'environment: production'
            ),
            'production_deploy_concurrency_gate' => str_contains(
                $deployWorkflowBody,
                'group: srcm-production-deploy'
            ) && str_contains($deployWorkflowBody, 'cancel-in-progress: false'),
            'production_deploy_dual_source_authorization' => str_contains(
                $deployWorkflowBody,
                "production_environment_secrets_and_approvals"
            ) && str_contains($deployWorkflowBody, "production_release_enabled"),
            'production_deploy_relative_checksum_contract' => str_contains(
                $deployWorkflowBody,
                'sha256sum "srcm-${RELEASE_SHA}.tar.gz"'
            ) && ! str_contains(
                $deployWorkflowBody,
                'sha256sum "$RUNNER_TEMP/srcm-${RELEASE_SHA}.tar.gz"'
            ),
            'production_deploy_runtime_secrets_excluded' => $this->workflowExcludesRuntimeSecrets(
                $deployWorkflowBody
            ),
            'production_deploy_private_tailscale_transport' =>
                $this->workflowUsesPrivateTailscaleTransport(
                    $deployWorkflowBody,
                    'Authorization boundary - fail closed before remote IO'
                ),
            'immutable_release_activation_contract' => $this->immutableReleaseContractIsPresent(
                $deployScriptBody
            ),
            'production_initial_bootstrap_workflow' => $bootstrapWorkflowBody !== '',
            'production_initial_bootstrap_manual_only' => $this->deploymentWorkflowIsManualOnly(
                $bootstrapWorkflowBody
            ),
            'production_initial_bootstrap_environment_gate' => str_contains(
                $bootstrapWorkflowBody,
                'environment: production'
            ),
            'production_initial_bootstrap_concurrency_gate' => str_contains(
                $bootstrapWorkflowBody,
                'group: srcm-production-initial-bootstrap'
            ) && str_contains($bootstrapWorkflowBody, 'cancel-in-progress: false'),
            'production_initial_bootstrap_pre_authorization_artifact_handoff' =>
                $this->initialBootstrapWorkflowSeparatesBuildFromProtectedInstall(
                    $bootstrapWorkflowBody
                ),
            'production_initial_bootstrap_policy_contract' =>
                $this->initialBootstrapPolicyIsPresent(),
            'production_initial_bootstrap_source_authorization' => str_contains(
                $bootstrapWorkflowBody,
                'initial_application_release_bootstrap_enabled'
            ) && str_contains(
                $bootstrapWorkflowBody,
                'production_environment_secrets_and_approvals'
            ) && str_contains($bootstrapWorkflowBody, 'production_release_enabled'),
            'production_initial_bootstrap_relative_checksum_contract' => str_contains(
                $bootstrapWorkflowBody,
                'sha256sum "$artifact" > "$artifact.sha256"'
            ) && ! str_contains(
                $bootstrapWorkflowBody,
                'sha256sum "$RUNNER_TEMP/$artifact"'
            ),
            'production_initial_bootstrap_runtime_secrets_excluded' => $this->workflowExcludesRuntimeSecrets(
                $bootstrapWorkflowBody
            ),
            'production_initial_bootstrap_private_tailscale_transport' =>
                $this->workflowUsesPrivateTailscaleTransport(
                    $bootstrapWorkflowBody,
                    'Authorization boundary - fail closed before bootstrap remote IO'
                ),
            'immutable_initial_bootstrap_contract' => $this->initialBootstrapContractIsPresent(
                $bootstrapScriptBody
            ),
            'production_runtime_units' => $this->runtimeUnitsArePresent(
                $queueUnitBody,
                $schedulerServiceBody,
                $schedulerTimerBody,
                $nginxBody
            ),
            'all_migrations_have_non_empty_down' => $irreversible === [],
            'post_deploy_readiness_contract' => $this->readinessContractIsPresent(),
        ];

        $external = [
            'production_release_switch' => config('release.production_release_enabled') === true,
            'off_host_encrypted_backup' => config(
                'release.external_gates.off_host_encrypted_backup'
            ) === true,
            'operational_restore_drill' => config(
                'release.external_gates.operational_restore_drill'
            ) === true,
            'production_environment_secrets_and_approvals' => config(
                'release.external_gates.production_environment_secrets_and_approvals'
            ) === true,
        ];

        $staticGreen = ! in_array(false, $static, true);
        $externalGreen = ! in_array(false, $external, true);
        $productionAuthorized = app()->environment('production')
            && $staticGreen
            && $externalGreen;

        return [
            'static' => $static,
            'external' => $external,
            'migration_files_count' => count($migrationFiles),
            'applied_migrations_count' => count($ran),
            'pending_migrations_count' => count($pending),
            'pending_migrations' => $pending,
            'irreversible_migrations' => $irreversible,
            'static_green' => $staticGreen,
            'external_green' => $externalGreen,
            'production_authorized' => $productionAuthorized,
        ];
    }

    private function fileBody(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        $body = file_get_contents($path);

        return is_string($body) ? $body : '';
    }

    private function workflowEventIncludesBranch(
        string $workflow,
        string $event,
        string $branch
    ): bool {
        if ($workflow === '') {
            return false;
        }

        $eventPattern = sprintf(
            '/(?ms)^  %s:\s*\R(?<body>.*?)(?=^  [A-Za-z_][A-Za-z0-9_-]*:\s*|^permissions:\s*|^concurrency:\s*|^jobs:\s*|\z)/',
            preg_quote($event, '/')
        );

        if (preg_match($eventPattern, $workflow, $match) !== 1) {
            return false;
        }

        $body = (string) ($match['body'] ?? '');
        if (preg_match('/(?m)^    branches:\s*$/', $body) !== 1) {
            return false;
        }

        $branchPattern = sprintf(
            '/(?m)^      -\s+["\']?%s["\']?\s*$/',
            preg_quote($branch, '/')
        );

        return preg_match($branchPattern, $body) === 1;
    }

    private function deploymentWorkflowIsManualOnly(string $workflow): bool
    {
        if ($workflow === '' || ! str_contains($workflow, 'workflow_dispatch:')) {
            return false;
        }

        return ! preg_match('/^\s{2}(push|pull_request|schedule):/m', $workflow);
    }

    private function initialBootstrapWorkflowSeparatesBuildFromProtectedInstall(
        string $workflow
    ): bool {
        if ($workflow === '') {
            return false;
        }

        $buildJob = strpos($workflow, '  build-initial-release-artifact:');
        $artifactBuild = strpos($workflow, 'Build immutable initial release artifact');
        $artifactUpload = strpos(
            $workflow,
            'actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02'
        );
        $installJob = strpos($workflow, '  install-inactive-initial-release:');
        $environmentGate = strpos($workflow, '    environment: production');
        $artifactDownload = strpos(
            $workflow,
            'actions/download-artifact@d3f86a106a0bac45b974a628896c90dbdf5c8093'
        );
        $authorization = strpos(
            $workflow,
            'Authorization boundary - fail closed before bootstrap remote IO'
        );
        $remoteIo = strpos($workflow, 'Configure SSH transport');

        foreach ([
            $buildJob,
            $artifactBuild,
            $artifactUpload,
            $installJob,
            $environmentGate,
            $artifactDownload,
            $authorization,
            $remoteIo,
        ] as $position) {
            if (! is_int($position)) {
                return false;
            }
        }

        return $buildJob < $artifactBuild
            && $artifactBuild < $artifactUpload
            && $artifactUpload < $installJob
            && $installJob < $environmentGate
            && $environmentGate < $artifactDownload
            && $artifactDownload < $authorization
            && $authorization < $remoteIo
            && str_contains($workflow, 'needs: build-initial-release-artifact');
    }

    private function initialBootstrapPolicyIsPresent(): bool
    {
        $policy = config('release.deployment.initial_application_release');
        if (! is_array($policy)) {
            return false;
        }

        return ($policy['foundation_version'] ?? null) === 1
            && ($policy['mode'] ?? null) === 'one_time_inactive_bootstrap'
            && ($policy['authorization_switch'] ?? null)
                === 'initial_application_release_bootstrap_enabled'
            && ($policy['requires_current_absent'] ?? null) === true
            && ($policy['requires_releases_directory_empty'] ?? null) === true
            && ($policy['artifact_built_in_github_actions'] ?? null) === true
            && ($policy['artifact_build_is_pre_authorization'] ?? null) === true
            && ($policy['remote_install_is_environment_protected'] ?? null) === true
            && ($policy['expected_database_sha256'] ?? null)
                === 'b07434ffcaaea6c1be8373b2187e725dceb70be40bfbdc3571af5df5ba85595e'
            && ($policy['expected_database_size_bytes'] ?? null) === 3694592
            && ($policy['expected_applied_migrations'] ?? null) === 122
            && ($policy['migration_allowed'] ?? null) === false
            && ($policy['creates_current_symlink'] ?? null) === false
            && ($policy['starts_services'] ?? null) === false
            && ($policy['public_readiness_check'] ?? null) === false
            && ($policy['activation_is_separate_cut'] ?? null) === true;
    }

    private function workflowUsesPrivateTailscaleTransport(
        string $workflow,
        string $authorizationBoundary
    ): bool {
        if ($workflow === '') {
            return false;
        }

        foreach ([
            'id-token: write',
            'tailscale/github-action@780049a30b6ff5c378a9e7b389d15ece7a204888',
            '${{ secrets.TS_OAUTH_CLIENT_ID }}',
            '${{ secrets.TS_AUDIENCE }}',
            'tag:straleon-ci-deploy',
            'test "$DEPLOY_HOST" = "100.64.245.55"',
            'test "$DEPLOY_USER" = "straleon-deploy"',
            'test "$DEPLOY_PORT" = "22"',
            'tailscale ip -4',
            'ip route get "$DEPLOY_HOST"',
            'dev tailscale0',
            'SHA256:x6L1N7kD+rcrlqD7EB+boZgwDQc4AtO6NMMltEHZhpw',
            'SHA256:iy4hCZtEYlqi3MjSxLFmX7cKPTFXXfecZultd7c2Xj4',
        ] as $required) {
            if (! str_contains($workflow, $required)) {
                return false;
            }
        }

        if (str_contains($workflow, '64.176.3.12')) {
            return false;
        }

        if (substr_count(
            $workflow,
            'tailscale/github-action@780049a30b6ff5c378a9e7b389d15ece7a204888'
        ) !== 1) {
            return false;
        }

        $authorization = strpos($workflow, $authorizationBoundary);
        $tailscale = strpos(
            $workflow,
            'tailscale/github-action@780049a30b6ff5c378a9e7b389d15ece7a204888'
        );
        $route = strpos($workflow, 'ip route get "$DEPLOY_HOST"');
        $ssh = strpos($workflow, 'Configure SSH transport');

        foreach ([$authorization, $tailscale, $route, $ssh] as $position) {
            if (! is_int($position)) {
                return false;
            }
        }

        return $authorization < $tailscale
            && $tailscale < $route
            && $route < $ssh;
    }

    private function workflowExcludesRuntimeSecrets(string $workflow): bool
    {
        if ($workflow === '') {
            return false;
        }

        foreach ([
            'SRCM_MERCADO_PAGO_CONNECTION_SECRETS_JSON',
            'SRCM_ARCA_WSAA_CREDENTIAL_REFERENCES_JSON',
            'SRCM_ARCA_WSAA_CREDENTIAL_ROOT',
            'SRCM_BACKUP_ENCRYPTION_KEY_REFERENCE',
            'SRCM_BACKUP_S3_ACCESS_KEY_ID',
            'SRCM_BACKUP_S3_SECRET_ACCESS_KEY',
        ] as $forbidden) {
            if (str_contains($workflow, $forbidden)) {
                return false;
            }
        }

        return str_contains($workflow, 'SRCM_DEPLOY_SSH_PRIVATE_KEY')
            && str_contains($workflow, 'SRCM_DEPLOY_KNOWN_HOSTS');
    }

    private function immutableReleaseContractIsPresent(string $script): bool
    {
        if ($script === '') {
            return false;
        }

        foreach ([
            '/srv/srcm/releases',
            '/srv/srcm/current',
            '/srv/srcm/shared',
            'database/database.sqlite',
            'migrate --force',
            'api/health/ready',
            'srcm-queue.service',
        ] as $required) {
            if (! str_contains($script, $required)) {
                return false;
            }
        }

        return ! str_contains($script, 'migrate:rollback');
    }

    private function initialBootstrapContractIsPresent(string $script): bool
    {
        if ($script === '') {
            return false;
        }

        foreach ([
            '/srv/srcm/releases',
            '/srv/srcm/current',
            '/srv/srcm/shared',
            'initial_current_must_be_absent',
            'initial_releases_directory_must_be_empty',
            'EXPECTED_DB_SHA256=b07434ffcaaea6c1be8373b2187e725dceb70be40bfbdc3571af5df5ba85595e',
            'EXPECTED_DB_SIZE=3694592',
            'EXPECTED_MIGRATIONS=122',
            'php artisan srcm:release-preflight --ci',
            'php artisan optimize',
            'mv "$incoming_release" "$final_release"',
            'SRCM_INITIAL_BOOTSTRAP_CURRENT=ABSENT',
            'SRCM_INITIAL_BOOTSTRAP_SERVICES=INACTIVE',
            'SRCM_INITIAL_BOOTSTRAP_MIGRATE=NO',
        ] as $required) {
            if (! str_contains($script, $required)) {
                return false;
            }
        }

        foreach ([
            'php artisan migrate',
            'systemctl start',
            'systemctl restart',
            'systemctl reload',
            'systemctl enable',
            'ln -s "$final_release" "$CURRENT"',
        ] as $forbidden) {
            if (str_contains($script, $forbidden)) {
                return false;
            }
        }

        return true;
    }

    private function runtimeUnitsArePresent(
        string $queueUnit,
        string $schedulerService,
        string $schedulerTimer,
        string $nginx
    ): bool {
        return str_contains($queueUnit, 'artisan queue:work')
            && str_contains($schedulerService, 'artisan schedule:run')
            && str_contains($schedulerTimer, 'OnCalendar=*-*-* *:*:00')
            && str_contains($nginx, '/run/php/php8.3-fpm.sock')
            && str_contains($nginx, '/srv/srcm/current/public');
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files, SORT_STRING);

        return array_values($files);
    }

    /** @return list<string> */
    private function ranMigrations(): array
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return [];
            }

            return DB::table('migrations')
                ->orderBy('migration')
                ->pluck('migration')
                ->map(static fn (mixed $value): string => (string) $value)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function readinessContractIsPresent(): bool
    {
        $name = config('release.post_deploy_readiness.route_name');
        $uri = config('release.post_deploy_readiness.uri');
        $method = config('release.post_deploy_readiness.method');

        if (! is_string($name) || ! is_string($uri) || ! is_string($method)) {
            return false;
        }

        $route = $this->router->getRoutes()->getByName($name);
        if ($route === null) {
            return false;
        }

        return $route->uri() === $uri
            && in_array(strtoupper($method), $route->methods(), true);
    }

    private function hasNonEmptyDownMethod(string $path): bool
    {
        $source = file_get_contents($path);
        if (! is_string($source)) {
            return false;
        }

        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $this->nextSignificantToken($tokens, $i + 1);
            if ($nameIndex === null) {
                continue;
            }

            if ($tokens[$nameIndex] === '&') {
                $nameIndex = $this->nextSignificantToken($tokens, $nameIndex + 1);
            }

            if ($nameIndex === null
                || ! is_array($tokens[$nameIndex])
                || $tokens[$nameIndex][0] !== T_STRING
                || strtolower($tokens[$nameIndex][1]) !== 'down') {
                continue;
            }

            for ($j = $nameIndex + 1; $j < $count; $j++) {
                if ($tokens[$j] !== '{') {
                    continue;
                }

                $depth = 1;
                $hasBody = false;
                for ($k = $j + 1; $k < $count && $depth > 0; $k++) {
                    $token = $tokens[$k];
                    if ($token === '{') {
                        $depth++;
                        $hasBody = true;
                        continue;
                    }
                    if ($token === '}') {
                        $depth--;
                        continue;
                    }
                    if ($depth <= 0) {
                        break;
                    }
                    if (is_array($token)
                        && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $hasBody = true;
                }

                return $hasBody;
            }
        }

        return false;
    }

    /** @param array<int, array{0:int,1:string,2:int}|string> $tokens */
    private function nextSignificantToken(array $tokens, int $start): ?int
    {
        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
