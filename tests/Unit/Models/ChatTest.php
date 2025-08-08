<?php

namespace Tests\Unit\Models;

use App\Models\Chat;
use App\Models\User;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\ChatMessage;
use App\Models\Response;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected Chat $chat;
    protected User $sender;
    protected User $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->sender = User::factory()->create();
        $this->receiver = User::factory()->create();
        
        $this->chat = Chat::factory()->create([
            'sender_id' => $this->sender->id,
            'receiver_id' => $this->receiver->id
        ]);
    }

    public function test_table_name_is_set_correctly()
    {
        $this->assertEquals('chats', $this->chat->getTable());
    }

    public function test_belongs_to_send_request()
    {
        $sendRequest = SendRequest::factory()->create();
        $chat = Chat::factory()->create(['send_request_id' => $sendRequest->id]);
        
        $this->assertInstanceOf(SendRequest::class, $chat->sendRequest);
        $this->assertEquals($sendRequest->id, $chat->sendRequest->id);
    }

    public function test_belongs_to_delivery_request()
    {
        $deliveryRequest = DeliveryRequest::factory()->create();
        $chat = Chat::factory()->create(['delivery_request_id' => $deliveryRequest->id]);
        
        $this->assertInstanceOf(DeliveryRequest::class, $chat->deliveryRequest);
        $this->assertEquals($deliveryRequest->id, $chat->deliveryRequest->id);
    }

    public function test_belongs_to_sender()
    {
        $this->assertInstanceOf(User::class, $this->chat->sender);
        $this->assertEquals($this->sender->id, $this->chat->sender->id);
    }

    public function test_belongs_to_receiver()
    {
        $this->assertInstanceOf(User::class, $this->chat->receiver);
        $this->assertEquals($this->receiver->id, $this->chat->receiver->id);
    }

    public function test_has_many_messages()
    {
        ChatMessage::factory()->count(3)->create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->sender->id
        ]);
        
        $this->assertInstanceOf(Collection::class, $this->chat->messages);
        $this->assertCount(3, $this->chat->messages);
        $this->assertInstanceOf(ChatMessage::class, $this->chat->messages->first());
    }

    public function test_has_latest_message_relationship()
    {
        $olderMessage = ChatMessage::factory()->create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->sender->id,
            'created_at' => now()->subHours(2)
        ]);
        
        $latestMessage = ChatMessage::factory()->create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->sender->id,
            'created_at' => now()->subHour()
        ]);
        
        $this->assertInstanceOf(ChatMessage::class, $this->chat->latestMessage);
        $this->assertEquals($latestMessage->id, $this->chat->latestMessage->id);
        $this->assertNotEquals($olderMessage->id, $this->chat->latestMessage->id);
    }

    public function test_unread_messages_count_for_receiver()
    {
        // Create read message from sender
        ChatMessage::factory()->create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->sender->id,
            'is_read' => true
        ]);
        
        // Create unread messages from sender
        ChatMessage::factory()->count(2)->create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->sender->id,
            'is_read' => false
        ]);
        
        // Create message from receiver (should not be counted)
        ChatMessage::factory()->create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->receiver->id,
            'is_read' => false
        ]);
        
        $unreadCount = $this->chat->unreadMessagesCount($this->receiver->id);
        
        $this->assertEquals(2, $unreadCount);
    }

    public function test_unread_messages_count_for_sender()
    {
        // Create unread messages from receiver
        ChatMessage::factory()->count(3)->create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->receiver->id,
            'is_read' => false
        ]);
        
        // Create message from sender (should not be counted)
        ChatMessage::factory()->create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->sender->id,
            'is_read' => false
        ]);
        
        $unreadCount = $this->chat->unreadMessagesCount($this->sender->id);
        
        $this->assertEquals(3, $unreadCount);
    }

    public function test_unread_messages_count_returns_zero_when_all_read()
    {
        ChatMessage::factory()->count(2)->create([
            'chat_id' => $this->chat->id,
            'sender_id' => $this->sender->id,
            'is_read' => true
        ]);
        
        $unreadCount = $this->chat->unreadMessagesCount($this->receiver->id);
        
        $this->assertEquals(0, $unreadCount);
    }

    public function test_has_response_relationship()
    {
        $response = Response::factory()->create([
            'chat_id' => $this->chat->id,
            'status' => 'accepted'
        ]);
        
        $this->assertInstanceOf(Response::class, $this->chat->response);
        $this->assertEquals($response->id, $this->chat->response->id);
    }

    public function test_response_relationship_only_includes_accepted_or_waiting_status()
    {
        // Create rejected response - should not be included
        Response::factory()->create([
            'chat_id' => $this->chat->id,
            'status' => 'rejected'
        ]);
        
        // Create accepted response - should be included
        $acceptedResponse = Response::factory()->create([
            'chat_id' => $this->chat->id,
            'status' => 'accepted'
        ]);
        
        $this->assertInstanceOf(Response::class, $this->chat->response);
        $this->assertEquals($acceptedResponse->id, $this->chat->response->id);
    }

    public function test_guarded_false_allows_mass_assignment()
    {
        $data = [
            'sender_id' => $this->sender->id,
            'receiver_id' => $this->receiver->id,
            'send_request_id' => null,
            'delivery_request_id' => null,
            'status' => 'active'
        ];
        
        $chat = Chat::create($data);
        
        $this->assertInstanceOf(Chat::class, $chat);
        $this->assertEquals($this->sender->id, $chat->sender_id);
        $this->assertEquals($this->receiver->id, $chat->receiver_id);
        $this->assertEquals('active', $chat->status);
    }

    public function test_chat_can_be_associated_with_send_request()
    {
        $sendRequest = SendRequest::factory()->create();
        
        $chat = Chat::factory()->create([
            'send_request_id' => $sendRequest->id,
            'sender_id' => $this->sender->id,
            'receiver_id' => $this->receiver->id
        ]);
        
        $this->assertEquals($sendRequest->id, $chat->send_request_id);
        $this->assertEquals($sendRequest->id, $chat->sendRequest->id);
    }

    public function test_chat_can_be_associated_with_delivery_request()
    {
        $deliveryRequest = DeliveryRequest::factory()->create();
        
        $chat = Chat::factory()->create([
            'delivery_request_id' => $deliveryRequest->id,
            'sender_id' => $this->sender->id,
            'receiver_id' => $this->receiver->id
        ]);
        
        $this->assertEquals($deliveryRequest->id, $chat->delivery_request_id);
        $this->assertEquals($deliveryRequest->id, $chat->deliveryRequest->id);
    }
}