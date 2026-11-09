<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vault>
 */
class VaultFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company().' vault';

        return [
            'name' => $name,
            'normalized_name' => Str::lower($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->sentence(),
            'icon' => 'key',
            'kind' => 'shared',
            'state' => 'active',
            'created_by_user_id' => User::factory(),
            'primary_custodian_user_id' => User::factory(),
        ];
    }
}
