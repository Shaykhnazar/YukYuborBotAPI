<?php

namespace Tests\Unit\Models;

use App\Models\SendRequest;
use App\Models\User;
use App\Models\Location;
use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\Chat;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class SendRequestTest extends TestCase
{
    use RefreshDatabase;

    protected SendRequest $sendRequest;
    protected User $user;
    protected Location $fromLocation;
    protected Location $toLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->fromLocation = Location::factory()->create(['name' => 'From City']);
        $this->toLocation = Location::factory()->create(['name' => 'To City']);

        $this->sendRequest = SendRequest::factory()
            ->state(['status' => 'open'])
            ->create([
                'user_id' => $this->user->id,
                'from_location_id' => $this->fromLocation->id,
                'to_location_id' => $this->toLocation->id,
                'price' => 100
            ]);
    }

    public function test_casts_dates_to_datetime()
    {
        $sendRequest = SendRequest::factory()->create([
            'from_date' => '2024-01-01 10:00:00',
            'to_date' => '2024-01-02 10:00:00'
        ]);

        $this->assertInstanceOf(Carbon::class, $sendRequest->from_date);
        $this->assertInstanceOf(Carbon::class, $sendRequest->to_date);
    }

    public function test_casts_price_to_integer()
    {
        $sendRequest = SendRequest::factory()->create(['price' => '150']);

        $this->assertIsInt($sendRequest->price);
        $this->assertEquals(150, $sendRequest->price);
    }

    public function test_belongs_to_user()
    {
        $this->assertInstanceOf(User::class, $this->sendRequest->user);
        $this->assertEquals($this->user->id, $this->sendRequest->user->id);
    }

    public function test_belongs_to_from_location()
    {
        $this->assertInstanceOf(Location::class, $this->sendRequest->fromLocation);
        $this->assertEquals($this->fromLocation->id, $this->sendRequest->fromLocation->id);
    }

    public function test_belongs_to_to_location()
    {
        $this->assertInstanceOf(Location::class, $this->sendRequest->toLocation);
        $this->assertEquals($this->toLocation->id, $this->sendRequest->toLocation->id);
    }

    public function test_has_many_responses()
    {
        Response::factory()->count(3)->create([
            'request_id' => $this->sendRequest->id,
            'offer_type' => 'send'
        ]);

        $this->assertInstanceOf(Collection::class, $this->sendRequest->responses);
        $this->assertCount(3, $this->sendRequest->responses);
        $this->assertInstanceOf(Response::class, $this->sendRequest->responses->first());
    }

    public function test_has_many_manual_responses()
    {
        Response::factory()->count(2)->create([
            'offer_id' => $this->sendRequest->id,
            'offer_type' => 'send',
            'response_type' => 'manual'
        ]);

        $this->assertInstanceOf(Collection::class, $this->sendRequest->manualResponses);
        $this->assertCount(2, $this->sendRequest->manualResponses);
    }

    public function test_has_many_offer_responses()
    {
        Response::factory()->count(2)->create([
            'offer_id' => $this->sendRequest->id,
            'offer_type' => 'delivery'
        ]);

        $this->assertInstanceOf(Collection::class, $this->sendRequest->offerResponses);
        $this->assertCount(2, $this->sendRequest->offerResponses);
    }

    public function test_scope_open()
    {
        SendRequest::factory()->create(['status' => 'open']);
        SendRequest::factory()->create(['status' => 'closed']);

        $openRequests = SendRequest::open()->get();

        $this->assertCount(2, $openRequests); // Including the one from setUp
        $openRequests->each(function ($request) {
            $this->assertEquals('open', $request->status);
        });
    }

    public function test_scope_closed()
    {
        SendRequest::factory()->create(['status' => 'closed']);
        SendRequest::factory()->create(['status' => 'completed']);
        SendRequest::factory()->create(['status' => 'open']);

        $closedRequests = SendRequest::closed()->get();

        $this->assertCount(2, $closedRequests);
        $closedRequests->each(function ($request) {
            $this->assertContains($request->status, ['closed', 'completed']);
        });
    }

    public function test_scope_for_route()
    {
        $location1 = Location::factory()->create();
        $location2 = Location::factory()->create();

        SendRequest::factory()->create([
            'from_location_id' => $location1->id,
            'to_location_id' => $location2->id
        ]);

        $requests = SendRequest::forRoute($location1->id, $location2->id)->get();

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

        SendRequest::factory()->create([
            'from_location_id' => $city1->id,
            'to_location_id' => $city2->id
        ]);

        $requests = SendRequest::forCountryRoute($country1->id, $country2->id)->get();

        $this->assertCount(1, $requests);
    }

    public function test_get_route_display_attribute_with_locations()
    {
        $routeDisplay = $this->sendRequest->getRouteDisplayAttribute();

        $this->assertEquals('From City → To City', $routeDisplay);
    }

    public function test_get_route_display_attribute_fallback()
    {
        // Create a send request with location IDs but without loading the relationships
        // This tests the fallback when fromLocation/toLocation relationships return null
        $sendRequest = new SendRequest();
        $sendRequest->from_location_id = 999;
        $sendRequest->to_location_id = 998;
        $sendRequest->fromLocation = null;
        $sendRequest->toLocation = null;

        $routeDisplay = $sendRequest->getRouteDisplayAttribute();

        $this->assertEquals('Location 999 → Location 998', $routeDisplay);
    }

    public function test_get_from_country_attribute_when_location_is_country()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $sendRequest = SendRequest::factory()->create(['from_location_id' => $country->id]);

        $fromCountry = $sendRequest->getFromCountryAttribute();

        $this->assertEquals($country->id, $fromCountry->id);
    }

    public function test_get_from_country_attribute_when_location_is_city()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $city = Location::factory()->create(['parent_id' => $country->id, 'type' => 'city']);
        $sendRequest = SendRequest::factory()->create(['from_location_id' => $city->id]);

        $fromCountry = $sendRequest->getFromCountryAttribute();

        $this->assertEquals($country->id, $fromCountry->id);
    }

    public function test_get_to_country_attribute_when_location_is_country()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $sendRequest = SendRequest::factory()->create(['to_location_id' => $country->id]);

        $toCountry = $sendRequest->getToCountryAttribute();

        $this->assertEquals($country->id, $toCountry->id);
    }

    public function test_get_to_country_attribute_when_location_is_city()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $city = Location::factory()->create(['parent_id' => $country->id, 'type' => 'city']);
        $sendRequest = SendRequest::factory()->create(['to_location_id' => $city->id]);

        $toCountry = $sendRequest->getToCountryAttribute();

        $this->assertEquals($country->id, $toCountry->id);
    }

    public function test_all_responses_query_includes_matching_and_manual_responses()
    {
        // Create matching response
        Response::factory()->create([
            'request_id' => $this->sendRequest->id,
            'offer_type' => 'send'
        ]);

        // Create manual response
        Response::factory()->create([
            'offer_id' => $this->sendRequest->id,
            'offer_type' => 'send',
            'response_type' => 'manual'
        ]);

        $responses = $this->sendRequest->allResponsesQuery()->get();

        $this->assertCount(2, $responses);
    }

    public function test_has_chat_through_accepted_responses()
    {
        $chat = Chat::factory()->create();
        Response::factory()->create([
            'request_id' => $this->sendRequest->id,
            'offer_type' => 'send',
            'status' => 'accepted',
            'chat_id' => $chat->id
        ]);

        $this->assertInstanceOf(Chat::class, $this->sendRequest->chat);
        $this->assertEquals($chat->id, $this->sendRequest->chat->id);
    }

    public function test_guarded_false_allows_mass_assignment()
    {
        $data = [
            'user_id' => $this->user->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'description' => 'Test description',
            'price' => 200,
            'status' => 'open',
            'from_date' => now()->format('Y-m-d'),
            'to_date' => now()->addDays(7)->format('Y-m-d')
        ];

        $sendRequest = SendRequest::create($data);

        $this->assertInstanceOf(SendRequest::class, $sendRequest);
        $this->assertEquals('Test description', $sendRequest->description);
        $this->assertEquals(200, $sendRequest->price);
    }
}
