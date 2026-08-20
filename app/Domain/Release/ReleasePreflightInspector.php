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
        $workflow = base_path('.github/workflows/ci.yml');
        $workflowBody = is_file($workflow) ? file_get_contents($workflow) : false;
        $workflowBody = is_string($workflowBody) ? $workflowBody : '';

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
            'versioned_ci_workflow' => $workflowBody !== '',
            'ci_pinned_checkout' => str_contains(
                $workflowBody,
                'actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683'
            ),
            'ci_locked_composer_install' => str_contains($workflowBody, 'composer install'),
            'ci_locked_node_install' => str_contains($workflowBody, 'npm ci --ignore-scripts'),
            'ci_diff_check' => str_contains($workflowBody, 'git diff --check'),
            'ci_full_suite' => str_contains($workflowBody, 'composer test'),
            'ci_asset_build' => str_contains($workflowBody, 'npm run build'),
            'ci_release_preflight' => str_contains(
                $workflowBody,
                'php artisan srcm:release-preflight --ci'
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

            // Skip an optional ampersand for functions returning by reference.
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
