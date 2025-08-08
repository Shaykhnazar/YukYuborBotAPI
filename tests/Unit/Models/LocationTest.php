<?php

namespace Tests\Unit\Models;

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fillable_attributes()
    {
        $location = new Location();
        
        $this->assertEquals([
            'name',
            'parent_id',
            'type',
            'country_code',
            'is_active'
        ], $location->getFillable());
    }

    public function test_casts_is_active_to_boolean()
    {
        $location = Location::factory()->create(['is_active' => '1']);
        
        $this->assertIsBool($location->is_active);
        $this->assertTrue($location->is_active);
    }

    public function test_belongs_to_parent()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $city = Location::factory()->create([
            'parent_id' => $country->id,
            'type' => 'city'
        ]);
        
        $this->assertInstanceOf(Location::class, $city->parent);
        $this->assertEquals($country->id, $city->parent->id);
    }

    public function test_has_many_children()
    {
        $country = Location::factory()->create(['type' => 'country']);
        Location::factory()->count(3)->create([
            'parent_id' => $country->id,
            'type' => 'city'
        ]);
        
        $this->assertInstanceOf(Collection::class, $country->children);
        $this->assertCount(3, $country->children);
        $this->assertInstanceOf(Location::class, $country->children->first());
    }

    public function test_country_method_returns_self_when_location_is_country()
    {
        $country = Location::factory()->create(['type' => 'country']);
        
        $result = $country->country();
        
        $this->assertEquals($country, $result);
    }

    public function test_country_method_returns_parent_relation_when_location_is_city()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $city = Location::factory()->create([
            'parent_id' => $country->id,
            'type' => 'city'
        ]);
        
        $result = $city->country();
        
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $result);
    }

    public function test_scope_countries()
    {
        Location::factory()->create(['type' => 'country']);
        Location::factory()->create(['type' => 'country']);
        Location::factory()->create(['type' => 'city']);
        
        $countries = Location::countries()->get();
        
        $this->assertCount(2, $countries);
        $countries->each(function ($location) {
            $this->assertEquals('country', $location->type);
        });
    }

    public function test_scope_cities()
    {
        Location::factory()->create(['type' => 'country']);
        Location::factory()->create(['type' => 'city']);
        Location::factory()->create(['type' => 'city']);
        
        $cities = Location::cities()->get();
        
        $this->assertCount(2, $cities);
        $cities->each(function ($location) {
            $this->assertEquals('city', $location->type);
        });
    }

    public function test_scope_active()
    {
        Location::factory()->create(['is_active' => true]);
        Location::factory()->create(['is_active' => true]);
        Location::factory()->create(['is_active' => false]);
        
        $activeLocations = Location::active()->get();
        
        $this->assertCount(2, $activeLocations);
        $activeLocations->each(function ($location) {
            $this->assertTrue($location->is_active);
        });
    }

    public function test_get_full_route_name_attribute_for_country()
    {
        $country = Location::factory()->create([
            'name' => 'United States',
            'type' => 'country'
        ]);
        
        $this->assertEquals('United States', $country->getFullRouteNameAttribute());
    }

    public function test_get_full_route_name_attribute_for_city()
    {
        $country = Location::factory()->create([
            'name' => 'United States',
            'type' => 'country'
        ]);
        
        $city = Location::factory()->create([
            'name' => 'New York',
            'parent_id' => $country->id,
            'type' => 'city'
        ]);
        
        $this->assertEquals('United States, New York', $city->getFullRouteNameAttribute());
    }

    public function test_location_can_be_created_with_all_fillable_attributes()
    {
        $locationData = [
            'name' => 'Test Location',
            'parent_id' => null,
            'type' => 'country',
            'country_code' => 'US',
            'is_active' => true
        ];
        
        $location = Location::create($locationData);
        
        $this->assertInstanceOf(Location::class, $location);
        $this->assertEquals('Test Location', $location->name);
        $this->assertEquals('country', $location->type);
        $this->assertEquals('US', $location->country_code);
        $this->assertTrue($location->is_active);
        $this->assertNull($location->parent_id);
    }

    public function test_location_factory_creates_valid_location()
    {
        $location = Location::factory()->create([
            'name' => 'Factory Test',
            'type' => 'city'
        ]);
        
        $this->assertInstanceOf(Location::class, $location);
        $this->assertEquals('Factory Test', $location->name);
        $this->assertEquals('city', $location->type);
        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'name' => 'Factory Test',
            'type' => 'city'
        ]);
    }

    public function test_country_child_relationship_can_be_established()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $city = Location::factory()->create([
            'parent_id' => $country->id,
            'type' => 'city'
        ]);
        
        // Test bidirectional relationship
        $this->assertTrue($country->children->contains($city));
        $this->assertEquals($country->id, $city->parent->id);
    }

    public function test_multiple_cities_can_belong_to_same_country()
    {
        $country = Location::factory()->create(['type' => 'country', 'name' => 'Test Country']);
        
        $city1 = Location::factory()->create([
            'parent_id' => $country->id,
            'type' => 'city',
            'name' => 'City One'
        ]);
        
        $city2 = Location::factory()->create([
            'parent_id' => $country->id,
            'type' => 'city',
            'name' => 'City Two'
        ]);
        
        $this->assertCount(2, $country->fresh()->children);
        $this->assertTrue($country->children->contains($city1));
        $this->assertTrue($country->children->contains($city2));
    }
}