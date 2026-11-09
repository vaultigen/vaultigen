<?php

namespace App\Services\Operations;

use Cron\CronExpression;
use DateTimeZone;

final class BackupPolicy
{
    public function enabled(string $key): bool
    {
        $value = filter_var(config('vaultigen.'.$key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new OperationFailedException('backup_configuration_invalid');
        }

        return $value;
    }

    public function validate(): void
    {
        $this->enabled('backup_schedule_enabled');
        $this->enabled('backup_retention_enabled');
        if (! is_string(config('vaultigen.backup_schedule')) || count(preg_split('/\s+/', trim(config('vaultigen.backup_schedule'))) ?: []) !== 5 || ! CronExpression::isValidExpression(config('vaultigen.backup_schedule'))
            || ! in_array(config('vaultigen.backup_timezone'), DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new OperationFailedException('backup_configuration_invalid');
        }
        foreach (['backup_retention_days', 'backup_max_age_hours'] as $key) {
            if (filter_var(config('vaultigen.'.$key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new OperationFailedException('backup_configuration_invalid');
            }
        }
    }

    public function assertQueue(): void
    {
        $connection = (string) config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        $timeout = max(300, (int) config('vaultigen.backup_job_timeout'));
        if (! in_array($driver, ['database', 'redis', 'beanstalkd', 'sqs'], true)
            || ($driver !== 'sqs' && (int) config('queue.connections.'.$connection.'.retry_after') <= $timeout)) {
            throw new OperationFailedException('backup_queue_invalid');
        }
    }
}
