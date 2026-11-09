<?php

namespace Database\Factories;

use App\Models\Tag;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'vault_id' => Vault::factory(),
            'scope' => Tag::SCOPE_VAULT,
            'global_key' => null,
            'name' => $name,
            'normalized_name' => Str::lower($name),
            'color' => null,
        ];
    }

    public function global(): static
    {
        return $this->state(function (array $attributes): array {
            $name = (string) ($attributes['name'] ?? fake()->unique()->word());
            $normalized = Str::lower($name);

            return [
                'vault_id' => null,
                'scope' => Tag::SCOPE_GLOBAL,
                'global_key' => $normalized,
                'normalized_name' => $normalized,
            ];
        });
    }
}
