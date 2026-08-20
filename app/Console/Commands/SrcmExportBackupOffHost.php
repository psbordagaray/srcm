<?php

namespace App\Console\Commands;

use App\Domain\Resilience\OffHostEncryptedBackupExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SrcmExportBackupOffHost extends Command
{
    protected $signature = 'srcm:export-backup-off-host {backup? : Verified local SRCM backup filename; latest when omitted}';

    protected $description = 'Encrypt and export a verified SRCM database backup to the configured off-host transport';

    public function handle(OffHostEncryptedBackupExporter $exporter): int
    {
        try {
            $name = $this->argument('backup');
            $result = $exporter->export(is_string($name) ? $name : null);
            Log::info('resilience.off_host_backup_export_succeeded', [
                'remote_key' => $result['remote_key'],
                'key_id' => $result['key_id'],
                'verified' => $result['verified'],
            ]);
            $this->info(
                'SRCM off-host encrypted backup GREEN: '.$result['remote_key']
            );
            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('resilience.off_host_backup_export_failed', [
                'exception_class' => $exception::class,
            ]);
            $this->error('SRCM off-host encrypted backup export failed.');
            return self::FAILURE;
        }
    }
}
