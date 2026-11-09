<?php

namespace Database\Factories;

use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRun>
 */
class BackupRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'state' => BackupRun::STATE_PENDING,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'requested_by_user_id' => User::factory(),
            'database_driver' => 'sqlite',
            'object_count' => 0,
            'total_bytes' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'state' => BackupRun::STATE_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'state' => BackupRun::STATE_COMPLETED,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'path' => 'backups/'.fake()->uuid(),
            'manifest_checksum' => hash('sha256', fake()->uuid()),
            'app_version' => '1.0.0-dev',
            'schema_version' => 'test',
        ]);
    }
}
