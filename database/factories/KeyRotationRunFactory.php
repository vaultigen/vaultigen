<?php

namespace Database\Factories;

use App\Models\KeyRotationRun;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KeyRotationRun>
 */
class KeyRotationRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'operation' => KeyRotationRun::OPERATION_VAULT_ROTATE,
            'vault_id' => Vault::factory(),
            'source_key_id' => fake()->uuid(),
            'target_key_id' => fake()->uuid(),
            'status' => KeyRotationRun::STATUS_PENDING,
            'started_by_user_id' => User::factory(),
        ];
    }
}
