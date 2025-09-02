<?php

namespace App\Console\Commands;

use App\Services\Matching\CapacityAwareMatchingService;
use App\Services\Matching\RoundRobinDistributionService;
use App\Services\Matching\RedistributionService;
use App\Models\User;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Response;
use Illuminate\Console\Command;

class TestRoundRobinDistribution extends Command
{
    protected $signature = 'test:round-robin {--reset-index}';
    protected $description = 'Test the round-robin distribution system';

    public function __construct(
        private CapacityAwareMatchingService $capacityService,
        private RoundRobinDistributionService $roundRobinService,
        private RedistributionService $redistributionService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Testing Round-Robin Distribution System');
        $this->info('=====================================');

        if ($this->option('reset-index')) {
            $this->roundRobinService->resetIndex();
            $this->info('✓ Round-robin index reset');
        }

        // Test 1: Check current capacity configuration
        $this->testCapacityConfiguration();
        
        // Test 2: Show current distribution state
        $this->showDistributionState();
        
        // Test 3: Show deliverer capacity overview
        $this->showDelivererCapacityOverview();
        
        // Test 4: Show redistribution statistics
        $this->showRedistributionStats();

        // Test 5: Simulate round-robin assignment
        $this->simulateRoundRobinAssignment();

        // Test 6: Verify one-to-one matching
        $this->verifyOneToOneMatching();

        return 0;
    }

    private function testCapacityConfiguration(): void
    {
        $this->info("\n1. Capacity Configuration:");
        $this->line("   Max capacity per deliverer: " . config('capacity.max_deliverer_capacity'));
        $this->line("   Distribution strategy: " . config('capacity.distribution_strategy'));
        $this->line("   Rebalancing enabled: " . (config('capacity.rebalancing.enabled') ? 'Yes' : 'No'));
    }

    private function showDistributionState(): void
    {
        $this->info("\n2. Distribution State:");
        $state = $this->roundRobinService->getDistributionState();
        foreach ($state as $key => $value) {
            $this->line("   {$key}: {$value}");
        }
    }

    private function showDelivererCapacityOverview(): void
    {
        $this->info("\n3. Deliverer Capacity Overview:");
        
        // Get all deliverers with active responses
        $activeDeliverers = Response::whereIn('overall_status', ['pending', 'partial'])
            ->distinct('user_id')
            ->pluck('user_id');

        if ($activeDeliverers->isEmpty()) {
            $this->line("   No active deliverers found");
            return;
        }

        $headers = ['Deliverer ID', 'Current Load', 'Max Capacity', 'Available', 'Status'];
        $rows = [];

        foreach ($activeDeliverers as $delivererId) {
            $capacity = $this->capacityService->getDelivererCapacityInfo($delivererId);
            $rows[] = [
                $delivererId,
                $capacity['current_load'],
                $capacity['max_capacity'],
                $capacity['available_capacity'],
                $capacity['is_at_capacity'] ? 'At Capacity' : 'Available'
            ];
        }

        $this->table($headers, $rows);
    }

    private function showRedistributionStats(): void
    {
        $this->info("\n4. Redistribution Statistics (Today):");
        $stats = $this->redistributionService->getRedistributionStats();
        
        foreach ($stats as $key => $value) {
            $displayKey = str_replace('_', ' ', ucwords($key));
            $displayValue = is_numeric($value) ? $value : $value . '%';
            $this->line("   {$displayKey}: {$displayValue}");
        }
    }

    private function simulateRoundRobinAssignment(): void
    {
        $this->info("\n5. Simulating Round-Robin Assignment:");
        
        // Get sample delivery requests (available deliverers)
        $deliveryRequests = DeliveryRequest::whereHas('user', function($query) {
            $query->whereNotNull('id');
        })->take(5)->get();

        if ($deliveryRequests->isEmpty()) {
            $this->warn("   No delivery requests found for simulation");
            return;
        }

        $this->line("   Available deliverers for simulation:");
        foreach ($deliveryRequests as $delivery) {
            $capacity = $this->capacityService->getDelivererCapacityInfo($delivery->user_id);
            $status = $capacity['is_at_capacity'] ? 'BUSY' : 'AVAILABLE';
            $this->line("   - Deliverer {$delivery->user_id} (Load: {$capacity['current_load']}/{$capacity['max_capacity']}) [{$status}]");
        }

        // Filter only available deliverers
        $availableDeliverers = $deliveryRequests->filter(function($delivery) {
            $capacity = $this->capacityService->getDelivererCapacityInfo($delivery->user_id);
            return !$capacity['is_at_capacity'];
        });

        if ($availableDeliverers->isEmpty()) {
            $this->warn("   No available deliverers for round-robin simulation");
            return;
        }

        $this->info("\n   Simulating 5 sequential assignments:");
        for ($i = 1; $i <= 5; $i++) {
            $selectedDeliverer = $this->roundRobinService->getNextDeliverer($availableDeliverers);
            if ($selectedDeliverer) {
                $this->line("   Request {$i} → Deliverer {$selectedDeliverer->user_id}");
            } else {
                $this->warn("   Request {$i} → No deliverer selected");
            }
        }
    }

    private function verifyOneToOneMatching(): void
    {
        $this->info("\n6. One-to-One Matching Verification:");
        
        // Get send requests that have active responses
        $sendRequestsWithResponses = Response::where('response_type', 'matching')
            ->whereIn('overall_status', ['pending', 'partial'])
            ->select('offer_id')
            ->distinct()
            ->get()
            ->pluck('offer_id');

        if ($sendRequestsWithResponses->isEmpty()) {
            $this->line("   No active send requests found");
            return;
        }

        $this->line("   Checking active send requests for multiple deliverers:");
        
        $violationsFound = false;
        foreach ($sendRequestsWithResponses as $sendRequestId) {
            $activeResponses = $this->capacityService->getSendRequestActiveResponses($sendRequestId);
            $status = $activeResponses === 1 ? '✓' : '✗';
            
            if ($activeResponses > 1) {
                $violationsFound = true;
                $this->error("   {$status} Send Request {$sendRequestId}: {$activeResponses} active responses (VIOLATION!)");
            } else {
                $this->line("   {$status} Send Request {$sendRequestId}: {$activeResponses} active response");
            }
        }

        if (!$violationsFound) {
            $this->info("   ✓ All send requests have exactly 1 deliverer - One-to-One matching verified!");
        } else {
            $this->error("   ✗ One-to-One matching violations found!");
        }

        // Show deliverer load distribution
        $this->info("\n   Current deliverer loads:");
        $delivererLoads = Response::where('response_type', 'matching')
            ->whereIn('overall_status', ['pending', 'partial'])
            ->selectRaw('user_id, COUNT(*) as load')
            ->groupBy('user_id')
            ->get();

        foreach ($delivererLoads as $load) {
            $status = $load->load === 1 ? '✓' : '✗';
            $this->line("   {$status} Deliverer {$load->user_id}: {$load->load} active responses");
        }
    }
}