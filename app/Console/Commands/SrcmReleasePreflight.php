<?php

namespace App\Console\Commands;

use App\Domain\Release\ReleasePreflightInspector;
use Illuminate\Console\Command;

final class SrcmReleasePreflight extends Command
{
    protected $signature = 'srcm:release-preflight
        {--ci : Validate executable CI/release contracts without authorizing production}';

    protected $description = 'Validate SRCM release gates without deploying or running migrations';

    public function handle(ReleasePreflightInspector $inspector): int
    {
        $result = $inspector->inspect();

        foreach ($result['static'] as $gate => $green) {
            $this->line('STATIC_'.strtoupper($gate).'='.($green ? 'GREEN' : 'RED'));
        }

        $this->line('MIGRATION_FILES_COUNT='.$result['migration_files_count']);
        $this->line('APPLIED_MIGRATIONS_COUNT='.$result['applied_migrations_count']);
        $this->line('PENDING_MIGRATIONS_COUNT='.$result['pending_migrations_count']);

        if ($result['irreversible_migrations'] !== []) {
            $this->error(
                'IRREVERSIBLE_MIGRATIONS='.implode('|', $result['irreversible_migrations'])
            );
        }

        foreach ($result['external'] as $gate => $green) {
            $this->line('EXTERNAL_'.strtoupper($gate).'='.($green ? 'GREEN' : 'BLOCKED'));
        }

        $this->line(
            'PRODUCTION_RELEASE_AUTHORIZED='.
            ($result['production_authorized'] ? 'YES' : 'NO')
        );

        if ((bool) $this->option('ci')) {
            if ($result['static_green'] !== true) {
                $this->error('SRCM_RELEASE_PREFLIGHT_CI=RED');
                return self::FAILURE;
            }

            $this->info('SRCM_RELEASE_PREFLIGHT_CI=GREEN');
            $this->line('PRODUCTION_REMAINS_BLOCKED=YES');
            return self::SUCCESS;
        }

        if ($result['production_authorized'] !== true) {
            $this->error('SRCM_PRODUCTION_RELEASE_PREFLIGHT=BLOCKED');
            return self::FAILURE;
        }

        $this->info('SRCM_PRODUCTION_RELEASE_PREFLIGHT=GREEN');
        return self::SUCCESS;
    }
}
