<?php

namespace Database\Factories;

use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TelegramUser>
 */
class TelegramUserFactory extends Factory
{
    protected $model = TelegramUser::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $userId = /*User::factory()*/ 2;

        return [
            'telegram' => $this->faker->unique()->numberBetween(100000000, 999999999),
            'username' => $this->faker->unique()->userName,
            'user_id' => $userId,
            'image' => $this->faker->imageUrl(150, 150, 'people', true, 'Profile'),
        ];
    }

    /**
     * Telegram user for specific user
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'telegram' => $user->id + 1000000000, // Generate based on user ID
            'username' => 'user_' . $user->id,
        ]);
    }

    /**
     * Telegram user with placeholder image
     */
    public function withPlaceholderImage(): static
    {
        return $this->state(fn (array $attributes) => [
            'image' => "https://via.placeholder.com/150/007bff/ffffff?text=" .
                substr($this->faker->firstName, 0, 2),
        ]);
    }
}
