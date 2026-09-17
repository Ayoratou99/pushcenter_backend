<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= 'password123',
            'phone' => fake()->optional()->phoneNumber(),
            'role' => User::ROLE_MANAGER,
            'scope' => User::SCOPE_RESTRICTED,
            'is_active' => true,
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role' => User::ROLE_ADMIN,
            'scope' => User::SCOPE_GLOBAL,
        ]);
    }

    public function globalScope(): static
    {
        return $this->state(fn () => [
            'role' => User::ROLE_MANAGER,
            'scope' => User::SCOPE_GLOBAL,
        ]);
    }

    /**
     * Google Authenticator already configured and confirmed.
     */
    public function twoFactorEnabled(): static
    {
        return $this->state(fn () => [
            // 32 chars of valid base32, good enough for the guard-level tests.
            'two_factor_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => User::generateRecoveryCodes(),
        ]);
    }

    public function withoutTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
