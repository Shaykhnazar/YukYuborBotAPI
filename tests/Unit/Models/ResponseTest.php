<?php

namespace Tests\Unit\Models;

use App\Models\Response;
use App\Models\User;
use App\Models\Chat;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Collection;

class ResponseTest extends TestCase
{
    use RefreshDatabase;

    protected Response $response;
    protected User $user;
    protected User $responder;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->responder = User::factory()->create();
        
        $this->response = Response::factory()->create([
            'user_id' => $this->user->id,
            'responder_id' => $this->responder->id
        ]);
    }

    public function test_has_status_constants()
    {
        $this->assertEquals('pending', Response::STATUS_PENDING);
        $this->assertEquals('accepted', Response::STATUS_ACCEPTED);
        $this->assertEquals('rejected', Response::STATUS_REJECTED);
        $this->assertEquals('waiting', Response::STATUS_WAITING);
        $this->assertEquals('responded', Response::STATUS_RESPONDED);
    }

    public function test_has_type_constants()
    {
        $this->assertEquals('matching', Response::TYPE_MATCHING);
        $this->assertEquals('manual', Response::TYPE_MANUAL);
    }

    public function test_belongs_to_user()
    {
        $this->assertInstanceOf(User::class, $this->response->user);
        $this->assertEquals($this->user->id, $this->response->user->id);
    }

    public function test_belongs_to_responder()
    {
        $this->assertInstanceOf(User::class, $this->response->responder);
        $this->assertEquals($this->responder->id, $this->response->responder->id);
    }

    public function test_belongs_to_chat()
    {
        $chat = Chat::factory()->create();
        $response = Response::factory()->create(['chat_id' => $chat->id]);
        
        $this->assertInstanceOf(Chat::class, $response->chat);
        $this->assertEquals($chat->id, $response->chat->id);
    }

    public function test_send_request_relationship()
    {
        $sendRequest = SendRequest::factory()->create();
        $response = Response::factory()->forSendRequest($sendRequest)->create();
        
        $this->assertInstanceOf(SendRequest::class, $response->sendRequest);
        $this->assertEquals($sendRequest->id, $response->sendRequest->id);
    }

    public function test_delivery_request_relationship()
    {
        $deliveryRequest = DeliveryRequest::factory()->create();
        $response = Response::factory()->forDeliveryRequest($deliveryRequest)->create();
        
        $this->assertInstanceOf(DeliveryRequest::class, $response->deliveryRequest);
        $this->assertEquals($deliveryRequest->id, $response->deliveryRequest->id);
    }

    public function test_get_request_attribute_for_send_request()
    {
        $sendRequest = SendRequest::factory()->create();
        $response = Response::factory()->forSendRequest($sendRequest)->create();
        
        $this->assertInstanceOf(SendRequest::class, $response->getRequestAttribute());
        $this->assertEquals($sendRequest->id, $response->getRequestAttribute()->id);
    }

    public function test_get_request_attribute_for_delivery_request()
    {
        $deliveryRequest = DeliveryRequest::factory()->create();
        $response = Response::factory()->forDeliveryRequest($deliveryRequest)->create();
        
        $this->assertInstanceOf(DeliveryRequest::class, $response->getRequestAttribute());
        $this->assertEquals($deliveryRequest->id, $response->getRequestAttribute()->id);
    }

    public function test_get_request_attribute_returns_null_for_invalid_type()
    {
        $response = Response::factory()->create(['request_type' => 'invalid']);
        
        $this->assertNull($response->getRequestAttribute());
    }

    public function test_scope_pending()
    {
        // Clear existing data and create specific test data
        Response::query()->delete();
        
        Response::factory()->create(['status' => Response::STATUS_PENDING]);
        Response::factory()->create(['status' => Response::STATUS_PENDING]);
        Response::factory()->create(['status' => Response::STATUS_ACCEPTED]);
        
        $pendingResponses = Response::pending()->get();
        
        $this->assertCount(2, $pendingResponses);
        $pendingResponses->each(function ($response) {
            $this->assertEquals(Response::STATUS_PENDING, $response->status);
        });
    }

    public function test_scope_accepted()
    {
        Response::factory()->create(['status' => Response::STATUS_ACCEPTED]);
        Response::factory()->create(['status' => Response::STATUS_PENDING]);
        
        $acceptedResponses = Response::accepted()->get();
        
        $this->assertCount(1, $acceptedResponses);
        $this->assertEquals(Response::STATUS_ACCEPTED, $acceptedResponses->first()->status);
    }

    public function test_scope_responded()
    {
        Response::factory()->create(['status' => Response::STATUS_RESPONDED]);
        Response::factory()->create(['status' => Response::STATUS_PENDING]);
        
        $respondedResponses = Response::responded()->get();
        
        $this->assertCount(1, $respondedResponses);
        $this->assertEquals(Response::STATUS_RESPONDED, $respondedResponses->first()->status);
    }

    public function test_scope_for_user()
    {
        $otherUser = User::factory()->create();
        Response::factory()->create(['user_id' => $otherUser->id]);
        
        $userResponses = Response::forUser($this->user->id)->get();
        
        $this->assertCount(1, $userResponses);
        $this->assertEquals($this->user->id, $userResponses->first()->user_id);
    }

    public function test_scope_by_type()
    {
        Response::factory()->create(['response_type' => Response::TYPE_MATCHING]);
        Response::factory()->create(['response_type' => Response::TYPE_MANUAL]);
        
        $matchingResponses = Response::byType(Response::TYPE_MATCHING)->get();
        
        $this->assertCount(2, $matchingResponses); // Including the one from setUp
        $matchingResponses->each(function ($response) {
            $this->assertEquals(Response::TYPE_MATCHING, $response->response_type);
        });
    }

    public function test_has_active_chat_returns_true_when_chat_is_active()
    {
        $chat = Chat::factory()->create(['status' => 'active']);
        $response = Response::factory()->create(['chat_id' => $chat->id]);
        
        $this->assertTrue($response->hasActiveChat());
    }

    public function test_has_active_chat_returns_false_when_chat_is_not_active()
    {
        $chat = Chat::factory()->create(['status' => 'inactive']);
        $response = Response::factory()->create(['chat_id' => $chat->id]);
        
        $this->assertFalse($response->hasActiveChat());
    }

    public function test_has_active_chat_returns_false_when_no_chat()
    {
        $response = Response::factory()->create(['chat_id' => null]);
        
        $this->assertFalse($response->hasActiveChat());
    }

    public function test_guarded_false_allows_mass_assignment()
    {
        $sendRequest = SendRequest::factory()->create();
        $deliveryRequest = DeliveryRequest::factory()->create();
        
        $data = [
            'user_id' => $this->user->id,
            'responder_id' => $this->responder->id,
            'status' => Response::STATUS_ACCEPTED,
            'response_type' => Response::TYPE_MANUAL,
            'request_type' => 'send',
            'request_id' => $deliveryRequest->id,
            'offer_id' => $sendRequest->id,
            'message' => 'Test message'
        ];
        
        $response = Response::create($data);
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::STATUS_ACCEPTED, $response->status);
        $this->assertEquals(Response::TYPE_MANUAL, $response->response_type);
        $this->assertEquals('Test message', $response->message);
    }

    public function test_table_name_is_set_correctly()
    {
        $this->assertEquals('responses', $this->response->getTable());
    }
}