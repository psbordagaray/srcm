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
            'immutable_release_activation_contract' => $this->immutableReleaseContractIsPresent(
                $deployScriptBody
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

    private function deploymentWorkflowIsManualOnly(string $workflow): bool
    {
        if ($workflow === '' || ! str_contains($workflow, 'workflow_dispatch:')) {
            return false;
        }

        return ! preg_match('/^\s{2}(push|pull_request|schedule):/m', $workflow);
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
