<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
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
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->firstName() . ' ' . $this->faker->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'city' => $this->faker->randomElement([
                'Tashkent', 'Samarkand', 'Bukhara', 'Andijan', 'Namangan',
                'Fergana', 'Nukus', 'Karshi', 'Termez', 'Jizzakh'
            ]),
            'links_balance' => $this->faker->numberBetween(0, 10),
            'created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'updated_at' => now(),
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
     * User with high balance
     */
    public function withHighBalance(): static
    {
        return $this->state(fn (array $attributes) => [
            'links_balance' => $this->faker->numberBetween(50, 100),
        ]);
    }

    /**
     * User with no balance
     */
    public function withNoBalance(): static
    {
        return $this->state(fn (array $attributes) => [
            'links_balance' => 0,
        ]);
    }

    /**
     * User from specific city
     */
    public function fromCity(string $city): static
    {
        return $this->state(fn (array $attributes) => [
            'city' => $city,
        ]);
    }
}
