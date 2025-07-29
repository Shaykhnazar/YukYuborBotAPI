<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->firstName() . ' ' . $this->faker->lastName(),
            'phone' => $this->faker->unique()->phoneNumber(),
            'city' => $this->faker->randomElement([
                'Tashkent', 'Samarkand', 'Bukhara', 'Andijan', 'Namangan',
                'Fergana', 'Nukus', 'Karshi', 'Termez', 'Jizzakh'
            ]),
            'links_balance' => $this->faker->numberBetween(3, 10), // Default 3, some with more
            'created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'updated_at' => now(),
        ];
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
     * User with default balance (3 links)
     */
    public function withDefaultBalance(): static
    {
        return $this->state(fn (array $attributes) => [
            'links_balance' => 3,
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

    /**
     * User with phone number pattern for Uzbekistan
     */
    public function withUzbekPhone(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone' => '+998' . $this->faker->numerify('#########'), // Uzbek phone format
        ]);
    }
}
