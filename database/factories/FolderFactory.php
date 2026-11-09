<?php

namespace Database\Factories;

use App\Models\Folder;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Folder>
 */
class FolderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->word().' folder';

        return [
            'vault_id' => Vault::factory(),
            'name' => $name,
            'normalized_name' => Str::lower($name),
            'depth' => 0,
            'lock_version' => 1,
        ];
    }
}
