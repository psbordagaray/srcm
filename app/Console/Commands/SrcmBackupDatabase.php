<?php

namespace App\Console\Commands;

use App\Domain\Resilience\SqliteBackupManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SrcmBackupDatabase extends Command implements Isolatable
{
    protected $signature = 'srcm:backup-database';

    protected $description = 'Create, verify and retain a consistent SRCM SQLite backup';

    public function handle(SqliteBackupManager $backups): int
    {
        try {
            $result = $backups->create();
            Log::info('resilience.backup_succeeded', [
                'backup' => $result['filename'],
                'verified' => $result['verified'],
                'pruned' => $result['pruned'],
            ]);
            $this->info(
                'SRCM backup GREEN: '.$result['filename'].' sha256='.$result['sha256']
            );
            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('resilience.backup_failed', [
                'exception_class' => $exception::class,
            ]);
            $this->error('SRCM database backup failed.');
            return self::FAILURE;
        }
    }
}
