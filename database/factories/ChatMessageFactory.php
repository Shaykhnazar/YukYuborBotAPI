<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $messages = [
            'Привет! Я видел вашу заявку на доставку.',
            'Здравствуйте! Могу помочь с доставкой вашей посылки.',
            'Когда планируете отправить?',
            'Какой размер у посылки?',
            'Я буду в этом городе на следующей неделе.',
            'Можете показать фото посылки?',
            'Сколько за доставку?',
            'Договорились! Встречаемся завтра.',
            'Спасибо за быструю доставку!',
            'Все получили, отличная работа!',
            'Можете подтвердить получение?',
            'Посылка в пути, доставлю завтра.',
            'Я на вокзале, где встречаемся?',
            'Готово! Посылка доставлена.',
            'Оставлю положительный отзыв.',
        ];

        return [
            'chat_id' => Chat::factory(),
            'sender_id' => User::factory(),
            'message' => $this->faker->randomElement($messages),
            'message_type' => $this->faker->randomElement(['text', 'system']),
            'is_read' => $this->faker->boolean(70), // 70% messages are read
            'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'updated_at' => now(),
        ];
    }

    /**
     * Text message
     */
    public function text(): static
    {
        return $this->state(fn (array $attributes) => [
            'message_type' => 'text',
        ]);
    }

    /**
     * System message
     */
    public function system(): static
    {
        $systemMessages = [
            'Чат создан',
            'Пользователь присоединился к чату',
            'Заявка завершена',
            'Доставка подтверждена',
            'Сделка завершена успешно',
        ];

        return $this->state(fn (array $attributes) => [
            'message_type' => 'system',
            'message' => $this->faker->randomElement($systemMessages),
            'is_read' => true, // System messages are always read
        ]);
    }

    /**
     * Unread message
     */
    public function unread(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_read' => false,
        ]);
    }

    /**
     * Read message
     */
    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_read' => true,
        ]);
    }

    /**
     * Message for specific chat
     */
    public function forChat(Chat $chat): static
    {
        return $this->state(fn (array $attributes) => [
            'chat_id' => $chat->id,
        ]);
    }

    /**
     * Message from specific sender
     */
    public function fromSender(User $sender): static
    {
        return $this->state(fn (array $attributes) => [
            'sender_id' => $sender->id,
        ]);
    }

    /**
     * Message in specific chat from specific user
     */
    public function inChat(Chat $chat, User $sender): static
    {
        return $this->state(fn (array $attributes) => [
            'chat_id' => $chat->id,
            'sender_id' => $sender->id,
        ]);
    }

    /**
     * Custom message text
     */
    public function withText(string $text): static
    {
        return $this->state(fn (array $attributes) => [
            'message' => $text,
            'message_type' => 'text',
        ]);
    }

    /**
     * Recent message (sent in last hour)
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $this->faker->dateTimeBetween('-1 hour', 'now'),
            'updated_at' => now(),
        ]);
    }

    /**
     * Old message
     */
    public function old(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $this->faker->dateTimeBetween('-30 days', '-7 days'),
        ]);
    }

    /**
     * Long message
     */
    public function long(): static
    {
        return $this->state(fn (array $attributes) => [
            'message' => $this->faker->paragraph(3),
        ]);
    }

    /**
     * Short message
     */
    public function short(): static
    {
        $shortMessages = [
            'Да', 'Нет', 'Хорошо', 'Понятно', 'Спасибо', 'Пожалуйста',
            'Ок', 'Договорились', 'Готово', 'Получил', 'Отлично'
        ];

        return $this->state(fn (array $attributes) => [
            'message' => $this->faker->randomElement($shortMessages),
        ]);
    }

    /**
     * Configure the model factory after creating.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ChatMessage $message) {
            // Ensure sender is a participant in the chat
            $chat = $message->chat;
            if ($chat && !in_array($message->sender_id, [$chat->sender_id, $chat->receiver_id])) {
                $message->sender_id = $this->faker->randomElement([$chat->sender_id, $chat->receiver_id]);
                $message->save();
            }
        });
    }
}
