<?php

namespace Database\Factories;

use App\Models\DeliveryRequest;
use App\Models\User;
use App\Models\SendRequest;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeliveryRequest>
 */
class DeliveryRequestFactory extends Factory
{
    protected $model = DeliveryRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fromDate = $this->faker->dateTimeBetween('now', '+30 days');
        $toDate = $this->faker->dateTimeBetween($fromDate, $fromDate->format('Y-m-d') . ' +15 days');

        // Get random locations from database, or create some if none exist
        $locations = Location::inRandomOrder()->limit(10)->get();
        if ($locations->isEmpty()) {
            // Create some basic locations if none exist
            $locationNames = ['Tashkent', 'Samarkand', 'Bukhara', 'Andijan', 'Namangan'];
            foreach ($locationNames as $name) {
                Location::firstOrCreate(['name' => $name], [
                    'type' => 'city',
                    'is_active' => true
                ]);
            }
            $locations = Location::limit(10)->get();
        }
        
        $fromLocation = $locations->random();
        $availableToLocations = $locations->where('id', '!=', $fromLocation->id);
        $toLocation = $availableToLocations->isNotEmpty() ? $availableToLocations->random() : $fromLocation;

        return [
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'user_id' => User::factory(),
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'size_type' => $this->faker->randomElement([
                'Маленькая', 'Средняя', 'Большая', 'Очень большая', 'Не указана', null
            ]),
            'description' => $this->faker->optional(0.7)->paragraph(1),
            'status' => $this->faker->randomElement(['open', 'has_responses', 'matched', 'completed', 'closed']),
            'price' => $this->faker->optional(0.5)->numberBetween(30000, 300000), // Delivery fee in som
            'currency' => function (array $attributes) {
                return $attributes['price'] ? $this->faker->randomElement(['UZS', 'USD']) : null;
            },
            'matched_send_id' => null,
            'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'updated_at' => now(),
        ];
    }

    /**
     * Delivery request that is open for responses
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'matched_send_id' => null,
        ]);
    }

    /**
     * Delivery request that has responses
     */
    public function hasResponses(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'has_responses',
            'matched_send_id' => null,
        ]);
    }

    /**
     * Delivery request that is matched
     */
    public function matched(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'matched',
            'matched_send_id' => SendRequest::factory(),
        ]);
    }

    /**
     * Delivery request that is completed
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'matched_send_id' => SendRequest::factory(),
        ]);
    }

    /**
     * Delivery request for specific user
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Delivery request with specific route
     */
    public function withRoute(int $fromLocationId, int $toLocationId): static
    {
        return $this->state(fn (array $attributes) => [
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
        ]);
    }

    /**
     * Delivery request with flexible destination (can deliver anywhere)
     */
    public function anyDestination(): static
    {
        // For any destination, we'll need to handle this differently
        // For now, just use a random location
        $location = Location::inRandomOrder()->first();
        return $this->state(fn (array $attributes) => [
            'to_location_id' => $location ? $location->id : 1,
        ]);
    }

    /**
     * Delivery request with price
     */
    public function withPrice(int $price, string $currency = 'UZS'): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => $price,
            'currency' => $currency,
        ]);
    }

    /**
     * Delivery request without price
     */
    public function withoutPrice(): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => null,
            'currency' => null,
        ]);
    }

    /**
     * Delivery request with flexible size handling
     */
    public function anySize(): static
    {
        return $this->state(fn (array $attributes) => [
            'size_type' => 'Не указана',
        ]);
    }

    /**
     * Delivery request for frequent traveler (long date range)
     */
    public function frequentTraveler(): static
    {
        $fromDate = $this->faker->dateTimeBetween('now', '+7 days');
        $toDate = $this->faker->dateTimeBetween($fromDate, $fromDate->format('Y-m-d') . ' +30 days');

        return $this->state(fn (array $attributes) => [
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
    }
}
