<?php

namespace Database\Factories;

use App\Models\SendRequest;
use App\Models\User;
use App\Models\DeliveryRequest;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SendRequest>
 */
class SendRequestFactory extends Factory
{
    protected $model = SendRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fromDate = $this->faker->dateTimeBetween('now', '+30 days');
        $toDate = $this->faker->dateTimeBetween($fromDate, $fromDate->format('Y-m-d') . ' +10 days');

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
            'description' => $this->faker->optional(0.8)->paragraph(2),
            'status' => $this->faker->randomElement(['open', 'has_responses', 'matched', 'completed', 'closed']),
            'price' => $this->faker->optional(0.6)->numberBetween(50000, 500000), // Price in som
            'currency' => function (array $attributes) {
                return $attributes['price'] ? $this->faker->randomElement(['UZS', 'USD']) : null;
            },
            'matched_delivery_id' => null,
            'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'updated_at' => now(),
        ];
    }

    /**
     * Send request that is open for responses
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'matched_delivery_id' => null,
        ]);
    }

    /**
     * Send request that has responses
     */
    public function hasResponses(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'has_responses',
            'matched_delivery_id' => null,
        ]);
    }

    /**
     * Send request that is matched
     */
    public function matched(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'matched',
            'matched_delivery_id' => DeliveryRequest::factory(),
        ]);
    }

    /**
     * Send request that is completed
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'matched_delivery_id' => DeliveryRequest::factory(),
        ]);
    }

    /**
     * Send request for specific user
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Send request with specific route
     */
    public function withRoute(int $fromLocationId, int $toLocationId): static
    {
        return $this->state(fn (array $attributes) => [
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
        ]);
    }

    /**
     * Send request with price
     */
    public function withPrice(int $price, string $currency = 'UZS'): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => $price,
            'currency' => $currency,
        ]);
    }

    /**
     * Send request without price
     */
    public function withoutPrice(): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => null,
            'currency' => null,
        ]);
    }

    /**
     * Urgent send request (near dates)
     */
    public function urgent(): static
    {
        $fromDate = $this->faker->dateTimeBetween('now', '+3 days');
        $toDate = $this->faker->dateTimeBetween($fromDate, $fromDate->format('Y-m-d') . ' +2 days');

        return $this->state(fn (array $attributes) => [
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
    }
}
