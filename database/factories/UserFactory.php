<?php

namespace Database\Factories;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
            'has_email_authentication' => false,
        ];
    }

    public function superAdmin(): static
    {
        return $this->afterCreating(function (User $user): void {
            DB::table('user_platform_roles')->insert([
                'user_id' => $user->id,
                'platform_role_id' => DB::table('platform_roles')->where('name', 'super_admin')->value('id'),
            ]);
        });
    }

    public function auditor(): static
    {
        return $this->afterCreating(function (User $user): void {
            DB::table('user_platform_roles')->insert([
                'user_id' => $user->id,
                'platform_role_id' => DB::table('platform_roles')->where('name', 'auditor')->value('id'),
            ]);
        });
    }

    public function vaultCreator(): static
    {
        return $this->afterCreating(function (User $user): void {
            DB::table('user_platform_roles')->insert([
                'user_id' => $user->id,
                'platform_role_id' => DB::table('platform_roles')->where('name', 'vault_creator')->value('id'),
            ]);
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'app_authentication_secret' => AppAuthentication::make()->generateSecret(),
            'app_authentication_recovery_codes' => [Hash::make('recovery-code-1')],
        ]);
    }

    /**
     * Indicate that the model uses the email-code second factor.
     */
    public function withEmailTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_email_authentication' => true,
        ]);
    }
}
