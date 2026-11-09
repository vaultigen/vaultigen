<?php

namespace Database\Factories;

use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRun>
 */
class ImportRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'requested_by_user_id' => User::factory(),
            'source_format' => 'syspass_xml',
            'source_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'original_filename' => 'syspass-export.xml',
            'status' => ImportRun::STATUS_STAGED,
            'layout' => 'single_vault',
            'axis_config' => [
                'layout' => 'single_vault',
                'vault_axis' => 'none',
                'folder_axis' => 'category',
                'client' => 'tag',
                'category' => 'folder',
            ],
            'plan' => null,
            'counters' => null,
            'created_vault_ids' => null,
            'failures' => null,
            'failure_count' => 0,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => ImportRun::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
    }

    public function importing(): static
    {
        return $this->state(fn (): array => [
            'status' => ImportRun::STATUS_IMPORTING,
            'started_at' => now(),
        ]);
    }
}
