<?php

namespace Database\Factories;

use App\Models\Chat;
use App\Models\User;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Chat>
 */
class ChatFactory extends Factory
{
    protected $model = Chat::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hasRequests = $this->faker->boolean(80); // 80% chance to have related requests

        return [
            'send_request_id' => $hasRequests ? SendRequest::factory() : null,
            'delivery_request_id' => $hasRequests ? DeliveryRequest::factory() : null,
            'sender_id' => User::factory(),
            'receiver_id' => User::factory(),
            'status' => $this->faker->randomElement(['active', 'completed', 'closed']),
            'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'updated_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /**
     * Active chat
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Completed chat
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Closed chat
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
        ]);
    }

    /**
     * Chat between specific users
     */
    public function betweenUsers(User $sender, User $receiver): static
    {
        return $this->state(fn (array $attributes) => [
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
        ]);
    }

    /**
     * Chat for send request
     */
    public function forSendRequest(SendRequest $sendRequest): static
    {
        return $this->state(fn (array $attributes) => [
            'send_request_id' => $sendRequest->id,
            'sender_id' => $sendRequest->user_id,
            'delivery_request_id' => null,
        ]);
    }

    /**
     * Chat for delivery request
     */
    public function forDeliveryRequest(DeliveryRequest $deliveryRequest): static
    {
        return $this->state(fn (array $attributes) => [
            'delivery_request_id' => $deliveryRequest->id,
            'receiver_id' => $deliveryRequest->user_id,
            'send_request_id' => null,
        ]);
    }

    /**
     * Chat for both send and delivery requests (matched)
     */
    public function forMatchedRequests(SendRequest $sendRequest, DeliveryRequest $deliveryRequest): static
    {
        return $this->state(fn (array $attributes) => [
            'send_request_id' => $sendRequest->id,
            'delivery_request_id' => $deliveryRequest->id,
            'sender_id' => $sendRequest->user_id,
            'receiver_id' => $deliveryRequest->user_id,
            'status' => 'active',
        ]);
    }

    /**
     * Chat without related requests (direct chat)
     */
    public function withoutRequests(): static
    {
        return $this->state(fn (array $attributes) => [
            'send_request_id' => null,
            'delivery_request_id' => null,
        ]);
    }

    /**
     * Recent chat (created recently and updated recently)
     */
    public function recent(): static
    {
        $createdAt = $this->faker->dateTimeBetween('-3 days', 'now');

        return $this->state(fn (array $attributes) => [
            'created_at' => $createdAt,
            'updated_at' => $this->faker->dateTimeBetween($createdAt, 'now'),
        ]);
    }

    /**
     * Old chat (not updated recently)
     */
    public function old(): static
    {
        $createdAt = $this->faker->dateTimeBetween('-30 days', '-15 days');

        return $this->state(fn (array $attributes) => [
            'created_at' => $createdAt,
            'updated_at' => $this->faker->dateTimeBetween($createdAt, '-10 days'),
        ]);
    }

    /**
     * Configure the model factory after creating.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Chat $chat) {
            // Ensure sender and receiver are different users
            if ($chat->sender_id === $chat->receiver_id) {
                $chat->receiver_id = User::factory()->create()->id;
                $chat->save();
            }
        });
    }
}
