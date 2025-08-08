<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\PlaceController;
use App\Service\PlaceApi;
use App\Http\Requests\Place\PlaceRequest;
use Tests\TestCase;
use Mockery;

class PlaceControllerTest extends TestCase
{
    protected PlaceController $controller;
    protected PlaceApi $placeApi;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->placeApi = Mockery::mock(PlaceApi::class);
        $this->controller = new PlaceController($this->placeApi);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_index_calls_place_api_with_correct_parameters()
    {
        $mockRequest = Mockery::mock(PlaceRequest::class);
        $mockRequest->shouldReceive('getPlace')
            ->once()
            ->andReturn('Berlin');
        
        $expectedPlaces = [
            [
                'name' => 'Berlin',
                'lat' => 52.5200,
                'lng' => 13.4050
            ],
            [
                'name' => 'Berlin Brandenburg',
                'lat' => 52.3667,
                'lng' => 13.5033
            ]
        ];
        
        $this->placeApi->shouldReceive('search_by_city')
            ->with('Berlin')
            ->once()
            ->andReturn($expectedPlaces);
        
        $response = $this->controller->index($mockRequest);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertEquals($expectedPlaces, $data);
    }

    public function test_index_returns_empty_array_when_no_places_found()
    {
        $mockRequest = Mockery::mock(PlaceRequest::class);
        $mockRequest->shouldReceive('getPlace')
            ->once()
            ->andReturn('NonexistentCity');
        
        $this->placeApi->shouldReceive('search_by_city')
            ->with('NonexistentCity')
            ->once()
            ->andReturn([]);
        
        $response = $this->controller->index($mockRequest);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertEmpty($data);
    }

    public function test_index_handles_place_api_service_exceptions()
    {
        $mockRequest = Mockery::mock(PlaceRequest::class);
        $mockRequest->shouldReceive('getPlace')
            ->once()
            ->andReturn('TestCity');
        
        $this->placeApi->shouldReceive('search_by_city')
            ->with('TestCity')
            ->once()
            ->andThrow(new \Exception('API service unavailable'));
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('API service unavailable');
        
        $this->controller->index($mockRequest);
    }

    public function test_index_returns_json_response()
    {
        $mockRequest = Mockery::mock(PlaceRequest::class);
        $mockRequest->shouldReceive('getPlace')
            ->once()
            ->andReturn('TestCity');
        
        $expectedPlaces = [
            [
                'name' => 'TestCity Center',
                'lat' => 50.0,
                'lng' => 10.0
            ]
        ];
        
        $this->placeApi->shouldReceive('search_by_city')
            ->with('TestCity')
            ->once()
            ->andReturn($expectedPlaces);
        
        $response = $this->controller->index($mockRequest);
        
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));
    }

    public function test_constructor_injects_place_api_dependency()
    {
        $reflection = new \ReflectionClass($this->controller);
        
        $placeApiProperty = $reflection->getProperty('placeApi');
        $placeApiProperty->setAccessible(true);
        
        $this->assertInstanceOf(PlaceApi::class, $placeApiProperty->getValue($this->controller));
    }

    public function test_index_processes_multiple_places_correctly()
    {
        $mockRequest = Mockery::mock(PlaceRequest::class);
        $mockRequest->shouldReceive('getPlace')
            ->once()
            ->andReturn('London');
        
        $expectedPlaces = [
            [
                'name' => 'London, UK',
                'lat' => 51.5074,
                'lng' => -0.1278
            ],
            [
                'name' => 'London, Ontario',
                'lat' => 42.9849,
                'lng' => -81.2453
            ],
            [
                'name' => 'New London',
                'lat' => 41.3556,
                'lng' => -72.0995
            ]
        ];
        
        $this->placeApi->shouldReceive('search_by_city')
            ->with('London')
            ->once()
            ->andReturn($expectedPlaces);
        
        $response = $this->controller->index($mockRequest);
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertCount(3, $data);
        $this->assertEquals('London, UK', $data[0]['name']);
        $this->assertEquals('London, Ontario', $data[1]['name']);
        $this->assertEquals('New London', $data[2]['name']);
    }

    public function test_index_handles_special_characters_in_place_name()
    {
        $mockRequest = Mockery::mock(PlaceRequest::class);
        $mockRequest->shouldReceive('getPlace')
            ->once()
            ->andReturn('São Paulo');
        
        $expectedPlaces = [
            [
                'name' => 'São Paulo, Brazil',
                'lat' => -23.5505,
                'lng' => -46.6333
            ]
        ];
        
        $this->placeApi->shouldReceive('search_by_city')
            ->with('São Paulo')
            ->once()
            ->andReturn($expectedPlaces);
        
        $response = $this->controller->index($mockRequest);
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertEquals('São Paulo, Brazil', $data[0]['name']);
    }

    public function test_index_verifies_place_request_getplace_method_called()
    {
        $mockRequest = Mockery::mock(PlaceRequest::class);
        $mockRequest->shouldReceive('getPlace')
            ->once()
            ->andReturn('TestPlace');
        
        $this->placeApi->shouldReceive('search_by_city')
            ->with('TestPlace')
            ->once()
            ->andReturn([]);
        
        $this->controller->index($mockRequest);
        
        // Verify the getPlace method was called exactly once
        $mockRequest->shouldHaveReceived('getPlace')->once();
    }
}