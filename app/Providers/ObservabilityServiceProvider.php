<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

final class ObservabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Queue::before(function (): void {
            Log::withoutContext();
            Log::flushSharedContext();
        });

        Queue::after(function (): void {
            Log::withoutContext();
            Log::flushSharedContext();
        });

        Queue::exceptionOccurred(function (JobExceptionOccurred $event): void {
            Log::warning('queue.job_exception', [
                'queue_connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveName(),
                'attempt' => $event->job->attempts(),
                'exception_class' => $event->exception::class,
            ]);
        });

        Queue::failing(function (JobFailed $event): void {
            Log::error('queue.job_failed', [
                'queue_connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveName(),
                'attempt' => $event->job->attempts(),
                'exception_class' => $event->exception::class,
            ]);

            Log::withoutContext();
            Log::flushSharedContext();
        });
    }
}
