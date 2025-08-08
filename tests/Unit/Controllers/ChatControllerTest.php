<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\ChatController;
use App\Service\TelegramUserService;
use App\Models\User;
use App\Models\TelegramUser;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Events\MessageSent;
use App\Events\MessageRead;
use App\Events\UserTyping;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    protected ChatController $controller;
    protected TelegramUserService $telegramService;
    protected User $user;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        Event::fake();
        Http::fake();
        
        $this->telegramService = Mockery::mock(TelegramUserService::class);
        $this->controller = new ChatController($this->telegramService);
        
        $this->user = User::factory()->create([
            'name' => 'Test User',
            'links_balance' => 5
        ]);
        
        $this->otherUser = User::factory()->create([
            'name' => 'Other User'
        ]);
        
        TelegramUser::factory()->create([
            'user_id' => $this->user->id,
            'telegram' => '123456789'
        ]);
        
        TelegramUser::factory()->create([
            'user_id' => $this->otherUser->id,
            'telegram' => '987654321'
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_index_returns_user_chats()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        // Create chats where user is sender and receiver
        $chat1 = Chat::factory()->betweenUsers($this->user, $this->otherUser)->create();
        $chat2 = Chat::factory()->betweenUsers($this->otherUser, $this->user)->create();
        
        // Create latest messages
        ChatMessage::factory()->create([
            'chat_id' => $chat1->id,
            'sender_id' => $this->otherUser->id,
            'message' => 'Hello from chat 1'
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(1, count($data));
        
        foreach ($data as $chatData) {
            $this->assertArrayHasKey('id', $chatData);
            $this->assertArrayHasKey('other_user', $chatData);
            $this->assertArrayHasKey('latest_message', $chatData);
            $this->assertArrayHasKey('unread_count', $chatData);
            $this->assertArrayHasKey('status', $chatData);
        }
    }

    public function test_show_returns_chat_with_messages()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        $chat = Chat::factory()->betweenUsers($this->user, $this->otherUser)->create();
        
        ChatMessage::factory()->count(3)->create([
            'chat_id' => $chat->id,
            'sender_id' => $this->user->id
        ]);
        
        ChatMessage::factory()->count(2)->create([
            'chat_id' => $chat->id,
            'sender_id' => $this->otherUser->id,
            'is_read' => false
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->show($request, $chat->id);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('other_user', $data);
        $this->assertArrayHasKey('messages', $data);
        $this->assertArrayHasKey('status', $data);
        
        $this->assertEquals($chat->id, $data['id']);
        $this->assertCount(5, $data['messages']);
        
        // Check that unread messages were marked as read
        $this->assertEquals(2, $data['unread_marked']);
        
        Event::assertDispatched(MessageRead::class);
    }

    public function test_show_fails_for_unauthorized_chat()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        $unauthorizedUser = User::factory()->create();
        $chat = Chat::factory()->betweenUsers($this->otherUser, $unauthorizedUser)->create();
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        
        $this->controller->show($request, $chat->id);
    }

    public function test_send_message_creates_message_and_broadcasts_event()
    {
        $chat = Chat::factory()->betweenUsers($this->user, $this->otherUser)->active()->create();
        
        $request = new Request([
            'telegram_id' => '123456789',
            'chat_id' => $chat->id,
            'message' => 'Hello, this is a test message',
            'message_type' => 'text'
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->sendMessage($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertEquals('Hello, this is a test message', $data['message']);
        $this->assertEquals($this->user->id, $data['sender_id']);
        $this->assertTrue($data['is_my_message']);
        
        // Check message was created in database
        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $chat->id,
            'sender_id' => $this->user->id,
            'message' => 'Hello, this is a test message'
        ]);
        
        Event::assertDispatched(MessageSent::class);
    }

    public function test_send_message_fails_for_inactive_chat()
    {
        $chat = Chat::factory()->betweenUsers($this->user, $this->otherUser)->create(['status' => 'completed']);
        
        $request = new Request([
            'telegram_id' => '123456789',
            'chat_id' => $chat->id,
            'message' => 'Hello'
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->sendMessage($request);
        
        $this->assertEquals(403, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Chat is not active', $data['error']);
    }

    public function test_create_chat_creates_new_chat()
    {
        $sendRequest = SendRequest::factory()->create(['user_id' => $this->user->id]);
        
        $request = new Request([
            'telegram_id' => '123456789',
            'other_user_id' => $this->otherUser->id,
            'request_id' => $sendRequest->id,
            'request_type' => 'send'
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->createChat($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('chat_id', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertFalse($data['existing']);
        
        // Check chat was created in database
        $this->assertDatabaseHas('chats', [
            'sender_id' => $this->user->id,
            'receiver_id' => $this->otherUser->id,
            'send_request_id' => $sendRequest->id,
            'status' => 'active'
        ]);
    }

    public function test_create_chat_returns_existing_chat()
    {
        $existingChat = Chat::factory()->betweenUsers($this->user, $this->otherUser)->create();
        $sendRequest = SendRequest::factory()->create(['user_id' => $this->user->id]);
        
        $request = new Request([
            'telegram_id' => '123456789',
            'other_user_id' => $this->otherUser->id,
            'request_id' => $sendRequest->id,
            'request_type' => 'send'
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->createChat($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertEquals($existingChat->id, $data['chat_id']);
        $this->assertTrue($data['existing']);
    }

    public function test_create_chat_fails_with_insufficient_balance()
    {
        $this->user->update(['links_balance' => 0]);
        
        $request = new Request([
            'telegram_id' => '123456789',
            'other_user_id' => $this->otherUser->id,
            'request_id' => 1,
            'request_type' => 'send'
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->createChat($request);
        
        $this->assertEquals(403, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Insufficient links balance', $data['error']);
    }

    public function test_mark_as_read_marks_unread_messages()
    {
        $chat = Chat::factory()->betweenUsers($this->user, $this->otherUser)->create();
        
        ChatMessage::factory()->count(3)->create([
            'chat_id' => $chat->id,
            'sender_id' => $this->otherUser->id,
            'is_read' => false
        ]);
        
        $request = new Request(['telegram_id' => '123456789']);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->markAsRead($request, $chat->id);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertEquals(3, $data['marked_as_read']);
        $this->assertCount(3, $data['message_ids']);
        
        // Verify messages were marked as read in database
        $unreadCount = ChatMessage::where('chat_id', $chat->id)
            ->where('sender_id', $this->otherUser->id)
            ->where('is_read', false)
            ->count();
        
        $this->assertEquals(0, $unreadCount);
        
        Event::assertDispatched(MessageRead::class);
    }

    public function test_set_typing_broadcasts_typing_event()
    {
        $chat = Chat::factory()->betweenUsers($this->user, $this->otherUser)->create();
        
        $request = new Request([
            'telegram_id' => '123456789',
            'chat_id' => $chat->id,
            'is_typing' => true
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->setTyping($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertEquals('success', $data['status']);
        $this->assertTrue($data['is_typing']);
        
        Event::assertDispatched(UserTyping::class);
    }

    public function test_complete_chat_updates_chat_and_requests_status()
    {
        $sendRequest = SendRequest::factory()->create(['status' => 'matched']);
        $deliveryRequest = DeliveryRequest::factory()->create(['status' => 'matched']);
        
        $chat = Chat::factory()->create([
            'sender_id' => $this->user->id,
            'receiver_id' => $this->otherUser->id,
            'send_request_id' => $sendRequest->id,
            'delivery_request_id' => $deliveryRequest->id,
            'status' => 'active'
        ]);
        
        $request = new Request([
            'telegram_id' => '123456789',
            'reason' => 'Delivery completed successfully'
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->completeChat($request, $chat->id);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertEquals('Chat completed successfully', $data['message']);
        $this->assertEquals('completed', $data['status']);
        $this->assertEquals($this->user->name, $data['completed_by']);
        
        // Verify database updates
        $this->assertDatabaseHas('chats', [
            'id' => $chat->id,
            'status' => 'completed'
        ]);
        
        $this->assertDatabaseHas('send_requests', [
            'id' => $sendRequest->id,
            'status' => 'completed'
        ]);
        
        $this->assertDatabaseHas('delivery_requests', [
            'id' => $deliveryRequest->id,
            'status' => 'completed'
        ]);
        
        // Verify system message was created
        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $chat->id,
            'sender_id' => $this->user->id,
            'message_type' => 'system'
        ]);
    }

    public function test_get_online_users_returns_other_user_info()
    {
        $chat = Chat::factory()->betweenUsers($this->user, $this->otherUser)->create();
        
        $request = new Request(['telegram_id' => '123456789']);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->getOnlineUsers($request, $chat->id);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('chat_id', $data);
        $this->assertArrayHasKey('other_user', $data);
        $this->assertArrayHasKey('online_users', $data);
        
        $this->assertEquals($chat->id, $data['chat_id']);
        $this->assertEquals($this->otherUser->id, $data['other_user']['id']);
        $this->assertEquals($this->otherUser->name, $data['other_user']['name']);
    }

    public function test_chat_completion_logic_with_both_requests_completed()
    {
        $sendRequest = SendRequest::factory()->create(['status' => 'completed']);
        $deliveryRequest = DeliveryRequest::factory()->create(['status' => 'completed']);
        
        $chat = Chat::factory()->create([
            'send_request_id' => $sendRequest->id,
            'delivery_request_id' => $deliveryRequest->id,
            'status' => 'active'
        ]);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('isChatCompleted');
        $method->setAccessible(true);
        
        $result = $method->invokeArgs($this->controller, [$chat]);
        
        $this->assertTrue($result);
    }

    public function test_chat_completion_logic_with_only_one_request_completed()
    {
        $sendRequest = SendRequest::factory()->create(['status' => 'completed']);
        $deliveryRequest = DeliveryRequest::factory()->create(['status' => 'active']);
        
        $chat = Chat::factory()->create([
            'send_request_id' => $sendRequest->id,
            'delivery_request_id' => $deliveryRequest->id,
            'status' => 'active'
        ]);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('isChatCompleted');
        $method->setAccessible(true);
        
        $result = $method->invokeArgs($this->controller, [$chat]);
        
        $this->assertFalse($result);
    }
}