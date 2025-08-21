<?php

namespace Database\Factories;

use App\Models\Response;
use App\Models\User;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Chat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Response>
 */
class ResponseFactory extends Factory
{
    protected $model = Response::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $offerType = $this->faker->randomElement(['send', 'delivery']);

        return [
            'user_id' => User::factory(),
            'responder_id' => User::factory(),
            'offer_type' => $offerType,
            'request_id' => $offerType === 'send'
                ? DeliveryRequest::factory()
                : SendRequest::factory(),
            'offer_id' => $offerType === 'send'
                ? SendRequest::factory()
                : DeliveryRequest::factory(),
            'deliverer_status' => $this->faker->randomElement(['pending', 'accepted', 'rejected']),
            'sender_status' => $this->faker->randomElement(['pending', 'accepted', 'rejected']),
            'overall_status' => 'pending',
            'response_type' => Response::TYPE_MATCHING,
            'chat_id' => null,
            'message' => $this->faker->optional(0.3)->sentence(),
            'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'updated_at' => now(),
        ];
    }

    /**
     * Response where deliverer responds to send request
     */
    public function delivererToSender(): static
    {
        return $this->state(function (array $attributes) {
            $deliverer = User::factory()->create();
            $sender = User::factory()->create();
            $sendRequest = SendRequest::factory()->forUser($sender)->create();
            $deliveryRequest = DeliveryRequest::factory()->forUser($deliverer)->create();

            return [
                'user_id' => $deliverer->id, // deliverer will see this
                'responder_id' => $sender->id, // sender made the offer
                'offer_type' => 'send',
                'request_id' => $deliveryRequest->id, // deliverer's request
                'offer_id' => $sendRequest->id, // sender's request
            ];
        });
    }

    /**
     * Response where sender responds to deliverer
     */
    public function senderToDeliverer(): static
    {
        return $this->state(function (array $attributes) {
            $sender = User::factory()->create();
            $deliverer = User::factory()->create();
            $sendRequest = SendRequest::factory()->forUser($sender)->create();
            $deliveryRequest = DeliveryRequest::factory()->forUser($deliverer)->create();

            return [
                'user_id' => $sender->id, // sender will see this
                'responder_id' => $deliverer->id, // deliverer is responding
                'offer_type' => 'delivery',
                'request_id' => $sendRequest->id, // sender's request
                'offer_id' => $deliveryRequest->id, // deliverer's request
            ];
        });
    }

    /**
     * Pending response
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'deliverer_status' => Response::DUAL_STATUS_PENDING,
            'sender_status' => Response::DUAL_STATUS_PENDING,
            'overall_status' => Response::OVERALL_STATUS_PENDING,
            'chat_id' => null,
        ]);
    }

    /**
     * Accepted response with chat
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deliverer_status' => Response::DUAL_STATUS_ACCEPTED,
            'sender_status' => Response::DUAL_STATUS_ACCEPTED,
            'overall_status' => Response::OVERALL_STATUS_ACCEPTED,
            'chat_id' => Chat::factory(),
        ]);
    }

    /**
     * Partially accepted response (one user accepted)
     */
    public function partiallyAccepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deliverer_status' => Response::DUAL_STATUS_ACCEPTED,
            'sender_status' => Response::DUAL_STATUS_PENDING,
            'overall_status' => Response::OVERALL_STATUS_PARTIAL,
            'chat_id' => null,
        ]);
    }

    /**
     * Rejected response
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'deliverer_status' => Response::DUAL_STATUS_REJECTED,
            'sender_status' => Response::DUAL_STATUS_PENDING,
            'overall_status' => Response::OVERALL_STATUS_REJECTED,
            'chat_id' => null,
        ]);
    }

    /**
     * Response between specific users
     */
    public function betweenUsers(User $user, User $responder): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'responder_id' => $responder->id,
        ]);
    }

    public function forSendRequest(SendRequest $sendRequest, DeliveryRequest $deliveryRequest): static
    {
        return $this->state(fn () => [
            'user_id'        => $deliveryRequest->user_id,
            'responder_id'   => $sendRequest->user_id,
            'offer_type'   => 'send',
            'request_id'     => $deliveryRequest->id,
            'offer_id'       => $sendRequest->id,
        ]);
    }

    public function forDeliveryRequest(DeliveryRequest $deliveryRequest, SendRequest $sendRequest): static
    {
        return $this->state(fn () => [
            'user_id'        => $sendRequest->user_id,
            'responder_id'   => $deliveryRequest->user_id,
            'offer_type'   => 'delivery',
            'request_id'     => $sendRequest->id,
            'offer_id'       => $deliveryRequest->id,
        ]);
    }

    /**
     * Response with message
     */
    public function withMessage(string $message): static
    {
        return $this->state(fn (array $attributes) => [
            'message' => $message,
        ]);
    }

    /**
     * Manual response type
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'response_type' => Response::TYPE_MANUAL,
        ]);
    }

    /**
     * Matching response type
     */
    public function matching(): static
    {
        return $this->state(fn (array $attributes) => [
            'response_type' => Response::TYPE_MATCHING,
        ]);
    }

    /**
     * Recent response (created today)
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $this->faker->dateTimeBetween('-2 hours', 'now'),
            'updated_at' => now(),
        ]);
    }
}
