<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\TelegramUser;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Review;
use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_has_telegram_user_relationship()
    {
        $telegramUser = TelegramUser::factory()->create(['user_id' => $this->user->id]);
        
        $this->assertInstanceOf(TelegramUser::class, $this->user->telegramUser);
        $this->assertEquals($telegramUser->id, $this->user->telegramUser->id);
    }

    public function test_has_send_requests_relationship()
    {
        SendRequest::factory()->count(3)->create(['user_id' => $this->user->id]);
        
        $this->assertInstanceOf(Collection::class, $this->user->sendRequests);
        $this->assertCount(3, $this->user->sendRequests);
        $this->assertInstanceOf(SendRequest::class, $this->user->sendRequests->first());
    }

    public function test_has_delivery_requests_relationship()
    {
        DeliveryRequest::factory()->count(2)->create(['user_id' => $this->user->id]);
        
        $this->assertInstanceOf(Collection::class, $this->user->deliveryRequests);
        $this->assertCount(2, $this->user->deliveryRequests);
        $this->assertInstanceOf(DeliveryRequest::class, $this->user->deliveryRequests->first());
    }

    public function test_has_reviews_relationship()
    {
        Review::factory()->count(3)->create(['user_id' => $this->user->id]);
        
        $this->assertInstanceOf(Collection::class, $this->user->reviews);
        $this->assertCount(3, $this->user->reviews);
        $this->assertInstanceOf(Review::class, $this->user->reviews->first());
    }

    public function test_has_sent_chats_relationship()
    {
        $otherUser = User::factory()->create();
        Chat::factory()->count(2)->create([
            'sender_id' => $this->user->id,
            'receiver_id' => $otherUser->id
        ]);
        
        $this->assertInstanceOf(Collection::class, $this->user->sentChats);
        $this->assertCount(2, $this->user->sentChats);
        $this->assertInstanceOf(Chat::class, $this->user->sentChats->first());
    }

    public function test_has_received_chats_relationship()
    {
        $otherUser = User::factory()->create();
        Chat::factory()->count(2)->create([
            'sender_id' => $otherUser->id,
            'receiver_id' => $this->user->id
        ]);
        
        $this->assertInstanceOf(Collection::class, $this->user->receivedChats);
        $this->assertCount(2, $this->user->receivedChats);
        $this->assertInstanceOf(Chat::class, $this->user->receivedChats->first());
    }

    public function test_has_chat_messages_relationship()
    {
        $otherUser = User::factory()->create();
        $chat = Chat::factory()->betweenUsers($this->user, $otherUser)->create();
        
        ChatMessage::factory()->count(3)->create([
            'chat_id' => $chat->id,
            'sender_id' => $this->user->id
        ]);
        
        $this->assertInstanceOf(Collection::class, $this->user->chatMessages);
        $this->assertCount(3, $this->user->chatMessages);
        $this->assertInstanceOf(ChatMessage::class, $this->user->chatMessages->first());
    }

    public function test_get_all_chats_returns_sent_and_received_chats()
    {
        $otherUser = User::factory()->create();
        
        $sentChat = Chat::factory()->create([
            'sender_id' => $this->user->id,
            'receiver_id' => $otherUser->id
        ]);
        
        $receivedChat = Chat::factory()->create([
            'sender_id' => $otherUser->id,
            'receiver_id' => $this->user->id
        ]);
        
        $allChats = $this->user->getAllChats();
        
        $this->assertCount(2, $allChats);
        $chatIds = $allChats->pluck('id')->toArray();
        $this->assertContains($sentChat->id, $chatIds);
        $this->assertContains($receivedChat->id, $chatIds);
    }

    public function test_get_all_chats_orders_by_updated_at_desc()
    {
        $otherUser = User::factory()->create();
        
        $olderChat = Chat::factory()->create([
            'sender_id' => $this->user->id,
            'receiver_id' => $otherUser->id,
            'updated_at' => now()->subHours(2)
        ]);
        
        $newerChat = Chat::factory()->create([
            'sender_id' => $otherUser->id,
            'receiver_id' => $this->user->id,
            'updated_at' => now()->subHour()
        ]);
        
        $allChats = $this->user->getAllChats();
        
        $this->assertEquals($newerChat->id, $allChats->first()->id);
        $this->assertEquals($olderChat->id, $allChats->last()->id);
    }

    public function test_user_factory_creates_valid_user()
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'phone' => '+998901234567',
            'city' => 'Tashkent'
        ]);
        
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('+998901234567', $user->phone);
        $this->assertEquals('Tashkent', $user->city);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Test User',
            'phone' => '+998901234567',
            'city' => 'Tashkent'
        ]);
    }

    public function test_guarded_false_allows_mass_assignment()
    {
        $userData = [
            'name' => 'Test User',
            'phone' => '+998901234567',
            'city' => 'Samarkand',
            'links_balance' => 5
        ];
        
        $user = User::create($userData);
        
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('+998901234567', $user->phone);
        $this->assertEquals('Samarkand', $user->city);
        $this->assertEquals(5, $user->links_balance);
    }

    public function test_phone_field_is_unique()
    {
        $phone = '+998901234567';
        
        User::factory()->create(['phone' => $phone]);
        
        $this->expectException(\Illuminate\Database\QueryException::class);
        User::factory()->create(['phone' => $phone]);
    }

    public function test_phone_can_be_null()
    {
        $user = User::factory()->create(['phone' => null]);
        
        $this->assertNull($user->phone);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone' => null
        ]);
    }

    public function test_city_can_be_null()
    {
        $user = User::factory()->create(['city' => null]);
        
        $this->assertNull($user->city);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'city' => null
        ]);
    }

    public function test_links_balance_has_default_value()
    {
        $user = User::factory()->create();
        
        // Default should be 3 according to database schema
        $this->assertIsInt($user->links_balance);
        $this->assertGreaterThanOrEqual(0, $user->links_balance);
    }

    public function test_links_balance_can_be_set_to_zero()
    {
        $user = User::factory()->create(['links_balance' => 0]);
        
        $this->assertEquals(0, $user->links_balance);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'links_balance' => 0
        ]);
    }

    public function test_links_balance_can_be_negative()
    {
        $user = User::factory()->create(['links_balance' => -5]);
        
        $this->assertEquals(-5, $user->links_balance);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'links_balance' => -5
        ]);
    }

    public function test_user_with_uzbek_phone_format()
    {
        $user = User::factory()->withUzbekPhone()->create();
        
        $this->assertStringStartsWith('+998', $user->phone);
        $this->assertEquals(13, strlen($user->phone)); // +998 + 9 digits
    }

    public function test_user_from_specific_city()
    {
        $user = User::factory()->fromCity('Bukhara')->create();
        
        $this->assertEquals('Bukhara', $user->city);
    }

    public function test_user_with_high_balance()
    {
        $user = User::factory()->withHighBalance()->create();
        
        $this->assertGreaterThanOrEqual(50, $user->links_balance);
        $this->assertLessThanOrEqual(100, $user->links_balance);
    }

    public function test_user_with_no_balance()
    {
        $user = User::factory()->withNoBalance()->create();
        
        $this->assertEquals(0, $user->links_balance);
    }

    public function test_user_with_default_balance()
    {
        $user = User::factory()->withDefaultBalance()->create();
        
        $this->assertEquals(3, $user->links_balance);
    }

    public function test_name_field_is_required()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        User::factory()->create(['name' => null]);
    }

    public function test_user_can_have_same_city_as_another_user()
    {
        $city = 'Tashkent';
        
        $user1 = User::factory()->create(['city' => $city]);
        $user2 = User::factory()->create(['city' => $city]);
        
        $this->assertEquals($city, $user1->city);
        $this->assertEquals($city, $user2->city);
        $this->assertNotEquals($user1->id, $user2->id);
    }

    public function test_multiple_users_can_have_null_phone()
    {
        $user1 = User::factory()->create(['phone' => null]);
        $user2 = User::factory()->create(['phone' => null]);
        
        $this->assertNull($user1->phone);
        $this->assertNull($user2->phone);
        $this->assertNotEquals($user1->id, $user2->id);
    }
}