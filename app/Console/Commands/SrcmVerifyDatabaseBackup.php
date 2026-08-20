<?php

namespace App\Console\Commands;

use App\Domain\Resilience\SqliteBackupManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SrcmVerifyDatabaseBackup extends Command
{
    protected $signature = 'srcm:verify-database-backup {backup? : SRCM backup filename; latest when omitted}';

    protected $description = 'Verify an SRCM backup through an isolated temporary restore copy';

    public function handle(SqliteBackupManager $backups): int
    {
        try {
            $name = $this->argument('backup');
            $result = $backups->verifyRestore(is_string($name) ? $name : null);
            Log::info('resilience.restore_verification_succeeded', [
                'backup' => $result['filename'],
                'verified' => $result['verified'],
            ]);
            $this->info(
                'SRCM restore verification GREEN: '.$result['filename']
            );
            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('resilience.restore_verification_failed', [
                'exception_class' => $exception::class,
            ]);
            $this->error('SRCM restore verification failed.');
            return self::FAILURE;
        }
    }
}
