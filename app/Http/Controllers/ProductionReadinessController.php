<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ProductionReadinessController extends Controller
{
    public function __invoke(QueueFactory $queues): JsonResponse
    {
        $checks = [
            'database' => $this->databaseReady(),
            'queue' => $this->queueReady($queues),
            'failed_jobs' => $this->failedJobsReady(),
            'structured_logging' => $this->structuredLoggingReady(),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => array_map(
                static fn (bool $ok): string => $ok ? 'ok' : 'fail',
                $checks
            ),
        ], $ready ? 200 : 503);
    }

    private function databaseReady(): bool
    {
        try {
            DB::select('select 1 as srcm_ready');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function queueReady(QueueFactory $queues): bool
    {
        $name = config('queue.default');
        if (! is_string($name) || $name === '') {
            return false;
        }

        $driver = config('queue.connections.'.$name.'.driver');
        if (! is_string($driver) || in_array($driver, ['sync', 'null'], true)) {
            return false;
        }

        try {
            $queues->connection($name)->size();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function failedJobsReady(): bool
    {
        if (config('queue.failed.driver') !== 'database-uuids') {
            return false;
        }

        $connection = config('queue.failed.database')
            ?: config('database.default');
        $table = config('queue.failed.table');

        if (! is_string($connection) || ! is_string($table) || $table === '') {
            return false;
        }

        try {
            return Schema::connection($connection)->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function structuredLoggingReady(): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        $default = config('logging.default');
        if ($default === 'stderr_json') {
            return true;
        }

        if ($default !== 'stack') {
            return false;
        }

        $channels = config('logging.channels.stack.channels', []);

        return is_array($channels)
            && in_array('stderr_json', $channels, true);
    }
}
