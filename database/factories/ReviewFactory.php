<?php

namespace Database\Factories;

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rating = $this->faker->numberBetween(1, 5);

        // Generate review text based on rating
        $reviewTexts = [
            1 => [
                'Очень плохой опыт. Не рекомендую.',
                'Посылка была повреждена при доставке.',
                'Долго не отвечал на сообщения.',
                'Не соблюдал договоренности.',
            ],
            2 => [
                'Были некоторые проблемы с доставкой.',
                'Доставка была с опозданием.',
                'Не очень хорошая коммуникация.',
                'Можно было сделать лучше.',
            ],
            3 => [
                'Нормально, но есть к чему стремиться.',
                'Доставка прошла без особых проблем.',
                'Средний уровень сервиса.',
                'Все в порядке, но не выдающееся.',
            ],
            4 => [
                'Хорошая работа! Доволен доставкой.',
                'Быстро и аккуратно доставил посылку.',
                'Хорошая коммуникация, все четко.',
                'Рекомендую этого перевозчика.',
                'Профессиональный подход к делу.',
            ],
            5 => [
                'Отличная работа! Превзошел ожидания.',
                'Быстро, надежно и с заботой о посылке.',
                'Прекрасная коммуникация на всех этапах.',
                'Лучший перевозчик, с которым работал!',
                'Высочайший уровень сервиса. 5 звезд!',
                'Определенно буду обращаться снова.',
                'Профессионал своего дела!',
            ],
        ];

        return [
            'user_id' => User::factory(), // User being reviewed
            'owner_id' => User::factory(), // User writing the review
            'text' => $this->faker->randomElement($reviewTexts[$rating]),
            'rating' => $rating,
            'request_id' => null,
            'request_type' => null,
            'created_at' => $this->faker->dateTimeBetween('-60 days', 'now'),
            'updated_at' => now(),
        ];
    }

    /**
     * Excellent review (5 stars)
     */
    public function excellent(): static
    {
        $excellentTexts = [
            'Отличная работа! Превзошел ожидания.',
            'Быстро, надежно и с заботой о посылке.',
            'Прекрасная коммуникация на всех этапах.',
            'Лучший перевозчик, с которым работал!',
            'Высочайший уровень сервиса. 5 звезд!',
            'Определенно буду обращаться снова.',
            'Профессионал своего дела!',
        ];

        return $this->state(fn (array $attributes) => [
            'rating' => 5,
            'text' => $this->faker->randomElement($excellentTexts),
        ]);
    }

    /**
     * Good review (4 stars)
     */
    public function good(): static
    {
        $goodTexts = [
            'Хорошая работа! Доволен доставкой.',
            'Быстро и аккуратно доставил посылку.',
            'Хорошая коммуникация, все четко.',
            'Рекомендую этого перевозчика.',
            'Профессиональный подход к делу.',
        ];

        return $this->state(fn (array $attributes) => [
            'rating' => 4,
            'text' => $this->faker->randomElement($goodTexts),
        ]);
    }

    /**
     * Average review (3 stars)
     */
    public function average(): static
    {
        $averageTexts = [
            'Нормально, но есть к чему стремиться.',
            'Доставка прошла без особых проблем.',
            'Средний уровень сервиса.',
            'Все в порядке, но не выдающееся.',
        ];

        return $this->state(fn (array $attributes) => [
            'rating' => 3,
            'text' => $this->faker->randomElement($averageTexts),
        ]);
    }

    /**
     * Poor review (2 stars)
     */
    public function poor(): static
    {
        $poorTexts = [
            'Были некоторые проблемы с доставкой.',
            'Доставка была с опозданием.',
            'Не очень хорошая коммуникация.',
            'Можно было сделать лучше.',
        ];

        return $this->state(fn (array $attributes) => [
            'rating' => 2,
            'text' => $this->faker->randomElement($poorTexts),
        ]);
    }

    /**
     * Bad review (1 star)
     */
    public function bad(): static
    {
        $badTexts = [
            'Очень плохой опыт. Не рекомендую.',
            'Посылка была повреждена при доставке.',
            'Долго не отвечал на сообщения.',
            'Не соблюдал договоренности.',
        ];

        return $this->state(fn (array $attributes) => [
            'rating' => 1,
            'text' => $this->faker->randomElement($badTexts),
        ]);
    }

    /**
     * Review for specific user
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Review from specific user
     */
    public function fromUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_id' => $user->id,
        ]);
    }

    /**
     * Review between specific users
     */
    public function betweenUsers(User $reviewee, User $reviewer): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $reviewee->id,
            'owner_id' => $reviewer->id,
        ]);
    }

    /**
     * Custom rating
     */
    public function withRating(int $rating): static
    {
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be between 1 and 5');
        }

        return $this->state(fn (array $attributes) => [
            'rating' => $rating,
        ]);
    }

    /**
     * Custom text
     */
    public function withText(string $text): static
    {
        return $this->state(fn (array $attributes) => [
            'text' => $text,
        ]);
    }

    /**
     * Recent review (created in last week)
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'updated_at' => now(),
        ]);
    }

    /**
     * Old review
     */
    public function old(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $this->faker->dateTimeBetween('-365 days', '-30 days'),
        ]);
    }

    /**
     * Long detailed review
     */
    public function detailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'text' => $this->faker->paragraph(3) . ' ' . $this->faker->sentence(),
        ]);
    }

    /**
     * Short review
     */
    public function short(): static
    {
        $shortTexts = [
            'Отлично!', 'Хорошо', 'Нормально', 'Плохо', 'Ужасно',
            'Спасибо!', 'Рекомендую', 'Не рекомендую', 'Супер!', 'Ок'
        ];

        return $this->state(fn (array $attributes) => [
            'text' => $this->faker->randomElement($shortTexts),
        ]);
    }

    /**
     * Configure the model factory after creating.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Review $review) {
            // Ensure user_id and owner_id are different
            if ($review->user_id === $review->owner_id) {
                $review->owner_id = User::factory()->create()->id;
                $review->save();
            }
        });
    }
}
