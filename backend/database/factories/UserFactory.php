<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
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
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
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
     * A hidden guest row (EnsureGuestOrUser): no email/password, verified so
     * `verified` middleware isn't what stops it, is_guest so `not-guest` is.
     */
    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Guest '.Str::upper(Str::random(5)),
            'username' => null,
            'email' => null,
            'password' => null,
            'email_verified_at' => now(),
            'is_guest' => true,
        ]);
    }
}
