<?php

namespace Database\Factories;

use App\Models\DeliveryRequest;
use App\Models\User;
use App\Models\SendRequest;
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

        $cities = [
            'Tashkent', 'Samarkand', 'Bukhara', 'Andijan', 'Namangan',
            'Fergana', 'Nukus', 'Karshi', 'Termez', 'Jizzakh'
        ];

        $fromLocation = $this->faker->randomElement($cities);
        $toLocation = $this->faker->randomElement(array_diff($cities, [$fromLocation]));

        return [
            'from_location' => $fromLocation,
            'to_location' => $toLocation,
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
    public function withRoute(string $from, string $to): static
    {
        return $this->state(fn (array $attributes) => [
            'from_location' => $from,
            'to_location' => $to,
        ]);
    }

    /**
     * Delivery request with flexible destination (can deliver anywhere)
     */
    public function anyDestination(): static
    {
        return $this->state(fn (array $attributes) => [
            'to_location' => '*',
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
