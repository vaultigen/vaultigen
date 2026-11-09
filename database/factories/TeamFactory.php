<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'id' => (string) Str::uuid(),
            'name' => $name,
            'normalized_name' => Str::lower($name),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Team $team): void {
            $principalId = (string) Str::uuid();
            DB::table('principals')->insert(['id' => $principalId, 'kind' => 'team', 'created_at' => now()]);
            $team->principal_id = $principalId;
        });
    }
}
