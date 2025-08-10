<?php

namespace Database\Factories;

use App\Models\Route;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Route>
 */
class RouteFactory extends Factory
{
    protected $model = Route::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $counter = 0;
        $counter++;
        
        // Create unique location pairs by using different offsets
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        return [
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'is_active' => $this->faker->boolean(80), // 80% chance of being active
            'priority' => $this->faker->numberBetween(1, 10),
            'description' => $this->faker->optional(0.6)->sentence(),
        ];
    }

    /**
     * Active route
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Inactive route
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * High priority route
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $this->faker->numberBetween(8, 10),
        ]);
    }

    /**
     * Low priority route
     */
    public function lowPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $this->faker->numberBetween(1, 3),
        ]);
    }

    /**
     * Route between specific locations
     */
    public function between($fromLocation, $toLocation): static
    {
        return $this->state(fn (array $attributes) => [
            'from_location_id' => is_object($fromLocation) ? $fromLocation->id : $fromLocation,
            'to_location_id' => is_object($toLocation) ? $toLocation->id : $toLocation,
        ]);
    }

    /**
     * Route between countries (not cities)
     */
    public function countryToCountry(): static
    {
        return $this->state(fn (array $attributes) => [
            'from_location_id' => Location::factory()->country(),
            'to_location_id' => Location::factory()->country(),
        ]);
    }

    /**
     * Route between cities
     */
    public function cityToCity(): static
    {
        return $this->state(fn (array $attributes) => [
            'from_location_id' => Location::factory()->city(),
            'to_location_id' => Location::factory()->city(),
        ]);
    }

    /**
     * Popular route (high priority, active)
     */
    public function popular(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'priority' => $this->faker->numberBetween(7, 10),
            'description' => 'Popular route',
        ]);
    }

}