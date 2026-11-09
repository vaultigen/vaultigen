<?php

use App\Services\Operations\BackupPolicy;
use App\Services\Operations\OperationFailedException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('vaultigen:heartbeat')->everyMinute()->withoutOverlapping();
Schedule::command('vaultigen:deliver-security-notifications')->everyMinute()->withoutOverlapping();
Schedule::command('vaultigen:purge-trash')->everyMinute()->withoutOverlapping();
Schedule::command('vaultigen:expire-emergency-access')->everyMinute()->withoutOverlapping();
Schedule::command('vaultigen:prune-audit')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('vaultigen:cleanup-imports')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('vaultigen:refresh-site-icons')->everyFiveMinutes()->withoutOverlapping();

// This file loads for every Artisan command. Invalid backup settings must not
// stop workers, migrations, or `up`; the Operations page reports them instead.
try {
    $backupPolicy = app(BackupPolicy::class);
    $backupPolicy->validate();
    $scheduleBackups = $backupPolicy->enabled('backup_schedule_enabled');
    $pruneBackups = $backupPolicy->enabled('backup_retention_enabled');
} catch (OperationFailedException) {
    $scheduleBackups = $pruneBackups = false;
}
if ($scheduleBackups) {
    Schedule::command('vaultigen:backup --scheduled')->cron((string) config('vaultigen.backup_schedule'))
        ->timezone((string) config('vaultigen.backup_timezone'))->withoutOverlapping()->runInBackground();
}
if ($pruneBackups) {
    Schedule::command('vaultigen:backups:prune')->dailyAt('05:00')
        ->timezone((string) config('vaultigen.backup_timezone'))->withoutOverlapping()->runInBackground();
}
