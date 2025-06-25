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
        return [
            'telegram' => $this->faker->unique()->numberBetween(100000000, 999999999),
            'username' => $this->faker->unique()->userName(),
            'user_id' => User::factory(),
            'image' => $this->faker->optional(0.7)->imageUrl(150, 150, 'people'),
        ];
    }

    /**
     * Telegram user without username
     */
    public function withoutUsername(): static
    {
        return $this->state(fn (array $attributes) => [
            'username' => null,
        ]);
    }

    /**
     * Telegram user without profile image
     */
    public function withoutImage(): static
    {
        return $this->state(fn (array $attributes) => [
            'image' => null,
        ]);
    }

    /**
     * Telegram user for specific user
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Telegram user with specific telegram ID
     */
    public function withTelegramId(int $telegramId): static
    {
        return $this->state(fn (array $attributes) => [
            'telegram' => $telegramId,
        ]);
    }
}
