<?php

use App\Console\Commands\SrcmBackupDatabase;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(SrcmBackupDatabase::class)
    ->hourly()
    ->environments('production')
    ->withoutOverlapping(55)
    ->evenInMaintenanceMode();
