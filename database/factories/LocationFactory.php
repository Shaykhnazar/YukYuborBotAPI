<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->city(),
            'parent_id' => null,
            'type' => 'country',
            'country_code' => strtoupper($this->faker->countryCode()),
            'is_active' => true,
        ];
    }

    public function city(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'city',
            'parent_id' => Location::factory()->country(),
        ]);
    }

    public function country(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'country',
            'parent_id' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}