<?php

namespace Tests\Unit\Services\Matching;

use App\Services\Matching\RoundRobinDistributionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RoundRobinDistributionServiceTest extends TestCase
{
    protected RoundRobinDistributionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoundRobinDistributionService();
        
        // Clear any existing cache state
        $this->service->resetIndex();
    }

    /** @test */
    public function it_distributes_requests_in_round_robin_fashion()
    {
        // Create mock deliverers
        $deliverers = collect([
            (object) ['user_id' => 1, 'id' => 101],
            (object) ['user_id' => 2, 'id' => 102],
            (object) ['user_id' => 3, 'id' => 103],
        ]);

        // First round: should cycle through all deliverers
        $first = $this->service->getNextDeliverer($deliverers);
        $second = $this->service->getNextDeliverer($deliverers);
        $third = $this->service->getNextDeliverer($deliverers);
        
        // Fourth should cycle back to first deliverer
        $fourth = $this->service->getNextDeliverer($deliverers);

        // Assert round-robin behavior
        $this->assertEquals(1, $first->user_id);
        $this->assertEquals(2, $second->user_id);
        $this->assertEquals(3, $third->user_id);
        $this->assertEquals(1, $fourth->user_id); // Back to first
    }

    /** @test */
    public function it_handles_single_deliverer()
    {
        $deliverers = collect([
            (object) ['user_id' => 1, 'id' => 101],
        ]);

        $first = $this->service->getNextDeliverer($deliverers);
        $second = $this->service->getNextDeliverer($deliverers);
        $third = $this->service->getNextDeliverer($deliverers);

        $this->assertEquals(1, $first->user_id);
        $this->assertEquals(1, $second->user_id);
        $this->assertEquals(1, $third->user_id);
    }

    /** @test */
    public function it_returns_null_for_empty_collection()
    {
        $deliverers = collect([]);
        
        $result = $this->service->getNextDeliverer($deliverers);
        
        $this->assertNull($result);
    }

    /** @test */
    public function it_maintains_state_across_calls()
    {
        $deliverers = collect([
            (object) ['user_id' => 1, 'id' => 101],
            (object) ['user_id' => 2, 'id' => 102],
        ]);

        // Get first deliverer
        $first = $this->service->getNextDeliverer($deliverers);
        $this->assertEquals(1, $first->user_id);

        // Create new service instance - should maintain state via cache
        $newService = new RoundRobinDistributionService();
        $second = $newService->getNextDeliverer($deliverers);
        $this->assertEquals(2, $second->user_id);
    }

    /** @test */
    public function it_can_reset_index()
    {
        $deliverers = collect([
            (object) ['user_id' => 1, 'id' => 101],
            (object) ['user_id' => 2, 'id' => 102],
        ]);

        // Get first two deliverers
        $this->service->getNextDeliverer($deliverers);
        $this->service->getNextDeliverer($deliverers);

        // Reset and verify it starts from beginning
        $this->service->resetIndex();
        $first = $this->service->getNextDeliverer($deliverers);
        
        $this->assertEquals(1, $first->user_id);
    }

    /** @test */
    public function it_provides_distribution_state_info()
    {
        $state = $this->service->getDistributionState();

        $this->assertIsArray($state);
        $this->assertArrayHasKey('current_index', $state);
        $this->assertArrayHasKey('cache_key', $state);
        $this->assertArrayHasKey('cache_ttl', $state);
        $this->assertEquals(0, $state['current_index']); // Should start at 0
    }

    /** @test */
    public function it_distributes_batch_requests()
    {
        $deliverers = collect([
            (object) ['user_id' => 1, 'id' => 101],
            (object) ['user_id' => 2, 'id' => 102],
        ]);

        $requestIds = [1001, 1002, 1003, 1004];
        
        $distribution = $this->service->distributeRequests($deliverers, $requestIds);

        // Verify round-robin distribution
        $this->assertArrayHasKey(1, $distribution);
        $this->assertArrayHasKey(2, $distribution);
        
        // User 1 should get requests 1001, 1003 (indices 0, 2)
        $this->assertEquals([1001, 1003], $distribution[1]);
        
        // User 2 should get requests 1002, 1004 (indices 1, 3)  
        $this->assertEquals([1002, 1004], $distribution[2]);
    }
}