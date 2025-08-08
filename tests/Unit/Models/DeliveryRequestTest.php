<?php

namespace Tests\Unit\Models;

use App\Models\DeliveryRequest;
use App\Models\User;
use App\Models\Location;
use App\Models\SendRequest;
use App\Models\Response;
use App\Models\Chat;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class DeliveryRequestTest extends TestCase
{
    use RefreshDatabase;

    protected DeliveryRequest $deliveryRequest;
    protected User $user;
    protected Location $fromLocation;
    protected Location $toLocation;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->fromLocation = Location::factory()->create(['name' => 'From City']);
        $this->toLocation = Location::factory()->create(['name' => 'To City']);
        
        $this->deliveryRequest = DeliveryRequest::factory()->create([
            'user_id' => $this->user->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'price' => 100
        ]);
    }

    public function test_casts_dates_to_datetime()
    {
        $deliveryRequest = DeliveryRequest::factory()->create([
            'from_date' => '2024-01-01 10:00:00',
            'to_date' => '2024-01-02 10:00:00'
        ]);
        
        $this->assertInstanceOf(Carbon::class, $deliveryRequest->from_date);
        $this->assertInstanceOf(Carbon::class, $deliveryRequest->to_date);
    }

    public function test_casts_price_to_integer()
    {
        $deliveryRequest = DeliveryRequest::factory()->create(['price' => '150']);
        
        $this->assertIsInt($deliveryRequest->price);
        $this->assertEquals(150, $deliveryRequest->price);
    }

    public function test_belongs_to_user()
    {
        $this->assertInstanceOf(User::class, $this->deliveryRequest->user);
        $this->assertEquals($this->user->id, $this->deliveryRequest->user->id);
    }

    public function test_belongs_to_from_location()
    {
        $this->assertInstanceOf(Location::class, $this->deliveryRequest->fromLocation);
        $this->assertEquals($this->fromLocation->id, $this->deliveryRequest->fromLocation->id);
    }

    public function test_belongs_to_to_location()
    {
        $this->assertInstanceOf(Location::class, $this->deliveryRequest->toLocation);
        $this->assertEquals($this->toLocation->id, $this->deliveryRequest->toLocation->id);
    }

    public function test_has_matched_send_relationship()
    {
        $sendRequest = SendRequest::factory()->create();
        $deliveryRequest = DeliveryRequest::factory()->create(['matched_send_id' => $sendRequest->id]);
        
        $this->assertInstanceOf(SendRequest::class, $deliveryRequest->matchedSend);
        $this->assertEquals($sendRequest->id, $deliveryRequest->matchedSend->id);
    }

    public function test_has_many_responses()
    {
        Response::factory()->count(3)->create([
            'request_id' => $this->deliveryRequest->id,
            'request_type' => 'delivery'
        ]);
        
        $this->assertInstanceOf(Collection::class, $this->deliveryRequest->responses);
        $this->assertCount(3, $this->deliveryRequest->responses);
        $this->assertInstanceOf(Response::class, $this->deliveryRequest->responses->first());
    }

    public function test_has_many_manual_responses()
    {
        Response::factory()->count(2)->create([
            'offer_id' => $this->deliveryRequest->id,
            'request_type' => 'delivery',
            'response_type' => 'manual'
        ]);
        
        $this->assertInstanceOf(Collection::class, $this->deliveryRequest->manualResponses);
        $this->assertCount(2, $this->deliveryRequest->manualResponses);
    }

    public function test_has_many_offer_responses()
    {
        Response::factory()->count(2)->create([
            'offer_id' => $this->deliveryRequest->id,
            'request_type' => 'send'
        ]);
        
        $this->assertInstanceOf(Collection::class, $this->deliveryRequest->offerResponses);
        $this->assertCount(2, $this->deliveryRequest->offerResponses);
    }

    public function test_scope_open()
    {
        DeliveryRequest::factory()->create(['status' => 'open']);
        DeliveryRequest::factory()->create(['status' => 'closed']);
        
        $openRequests = DeliveryRequest::open()->get();
        
        $this->assertCount(2, $openRequests); // Including the one from setUp
        $openRequests->each(function ($request) {
            $this->assertEquals('open', $request->status);
        });
    }

    public function test_scope_closed()
    {
        DeliveryRequest::factory()->create(['status' => 'closed']);
        DeliveryRequest::factory()->create(['status' => 'completed']);
        DeliveryRequest::factory()->create(['status' => 'open']);
        
        $closedRequests = DeliveryRequest::closed()->get();
        
        $this->assertCount(2, $closedRequests);
        $closedRequests->each(function ($request) {
            $this->assertContains($request->status, ['closed', 'completed']);
        });
    }

    public function test_scope_for_route()
    {
        $location1 = Location::factory()->create();
        $location2 = Location::factory()->create();
        
        DeliveryRequest::factory()->create([
            'from_location_id' => $location1->id,
            'to_location_id' => $location2->id
        ]);
        
        $requests = DeliveryRequest::forRoute($location1->id, $location2->id)->get();
        
        $this->assertCount(1, $requests);
        $this->assertEquals($location1->id, $requests->first()->from_location_id);
        $this->assertEquals($location2->id, $requests->first()->to_location_id);
    }

    public function test_scope_for_country_route()
    {
        $country1 = Location::factory()->create(['type' => 'country']);
        $country2 = Location::factory()->create(['type' => 'country']);
        $city1 = Location::factory()->create(['parent_id' => $country1->id, 'type' => 'city']);
        $city2 = Location::factory()->create(['parent_id' => $country2->id, 'type' => 'city']);
        
        DeliveryRequest::factory()->create([
            'from_location_id' => $city1->id,
            'to_location_id' => $city2->id
        ]);
        
        $requests = DeliveryRequest::forCountryRoute($country1->id, $country2->id)->get();
        
        $this->assertCount(1, $requests);
    }

    public function test_get_route_display_attribute_with_locations()
    {
        $routeDisplay = $this->deliveryRequest->getRouteDisplayAttribute();
        
        $this->assertEquals('From City → To City', $routeDisplay);
    }

    public function test_get_route_display_attribute_fallback()
    {
        $deliveryRequest = DeliveryRequest::factory()->create([
            'from_location_id' => 999,
            'to_location_id' => 998
        ]);
        
        $routeDisplay = $deliveryRequest->getRouteDisplayAttribute();
        
        $this->assertEquals('Location 999 → Location 998', $routeDisplay);
    }

    public function test_get_from_country_attribute_when_location_is_country()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $deliveryRequest = DeliveryRequest::factory()->create(['from_location_id' => $country->id]);
        
        $fromCountry = $deliveryRequest->getFromCountryAttribute();
        
        $this->assertEquals($country->id, $fromCountry->id);
    }

    public function test_get_from_country_attribute_when_location_is_city()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $city = Location::factory()->create(['parent_id' => $country->id, 'type' => 'city']);
        $deliveryRequest = DeliveryRequest::factory()->create(['from_location_id' => $city->id]);
        
        $fromCountry = $deliveryRequest->getFromCountryAttribute();
        
        $this->assertEquals($country->id, $fromCountry->id);
    }

    public function test_get_to_country_attribute_when_location_is_country()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $deliveryRequest = DeliveryRequest::factory()->create(['to_location_id' => $country->id]);
        
        $toCountry = $deliveryRequest->getToCountryAttribute();
        
        $this->assertEquals($country->id, $toCountry->id);
    }

    public function test_get_to_country_attribute_when_location_is_city()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $city = Location::factory()->create(['parent_id' => $country->id, 'type' => 'city']);
        $deliveryRequest = DeliveryRequest::factory()->create(['to_location_id' => $city->id]);
        
        $toCountry = $deliveryRequest->getToCountryAttribute();
        
        $this->assertEquals($country->id, $toCountry->id);
    }

    public function test_all_responses_query_includes_matching_and_manual_responses()
    {
        // Create matching response
        Response::factory()->create([
            'request_id' => $this->deliveryRequest->id,
            'request_type' => 'delivery'
        ]);
        
        // Create manual response
        Response::factory()->create([
            'offer_id' => $this->deliveryRequest->id,
            'request_type' => 'delivery',
            'response_type' => 'manual'
        ]);
        
        $responses = $this->deliveryRequest->allResponsesQuery()->get();
        
        $this->assertCount(2, $responses);
    }

    public function test_has_chat_through_accepted_responses()
    {
        $chat = Chat::factory()->create();
        Response::factory()->create([
            'request_id' => $this->deliveryRequest->id,
            'request_type' => 'delivery',
            'status' => 'accepted',
            'chat_id' => $chat->id
        ]);
        
        $this->assertInstanceOf(Chat::class, $this->deliveryRequest->chat);
        $this->assertEquals($chat->id, $this->deliveryRequest->chat->id);
    }

    public function test_guarded_false_allows_mass_assignment()
    {
        $data = [
            'user_id' => $this->user->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'description' => 'Test description',
            'price' => 200,
            'status' => 'open'
        ];
        
        $deliveryRequest = DeliveryRequest::create($data);
        
        $this->assertInstanceOf(DeliveryRequest::class, $deliveryRequest);
        $this->assertEquals('Test description', $deliveryRequest->description);
        $this->assertEquals(200, $deliveryRequest->price);
    }

    public function test_table_name_is_set_correctly()
    {
        $this->assertEquals('delivery_requests', $this->deliveryRequest->getTable());
    }
}