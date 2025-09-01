<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\User;
use App\Services\Matcher;
use App\Services\Matching\CapacityAwareMatchingService;
use App\Services\Matching\ResponseRebalancingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapacityTestController extends Controller
{
    public function __construct(
        private Matcher $matcher,
        private CapacityAwareMatchingService $capacityService,
        private ResponseRebalancingService $rebalancingService
    ) {}

    /**
     * Get system capacity overview
     */
    public function capacityOverview(): JsonResponse
    {
        $stats = $this->rebalancingService->getSystemCapacityStats();
        
        return response()->json([
            'system_stats' => $stats,
            'config' => [
                'max_capacity' => config('capacity.max_deliverer_capacity', 3),
                'rebalancing_enabled' => config('capacity.rebalancing.enabled', true)
            ]
        ]);
    }

    /**
     * Get detailed capacity info for a specific deliverer
     */
    public function delivererCapacity(int $delivererId): JsonResponse
    {
        $capacityInfo = $this->capacityService->getDelivererCapacityInfo($delivererId);
        
        // Get actual responses for detailed view
        $responses = Response::where('user_id', $delivererId)
            ->whereIn('overall_status', ['pending', 'partial'])
            ->with(['sendRequest', 'deliveryRequest'])
            ->get();

        return response()->json([
            'capacity_info' => $capacityInfo,
            'active_responses' => $responses->map(function($response) {
                return [
                    'id' => $response->id,
                    'overall_status' => $response->overall_status,
                    'deliverer_status' => $response->deliverer_status,
                    'sender_status' => $response->sender_status,
                    'offer_type' => $response->offer_type,
                    'response_type' => $response->response_type,
                    'created_at' => $response->created_at,
                    'send_request' => $response->sendRequest?->only(['id', 'from_location', 'to_location']),
                    'delivery_request' => $response->deliveryRequest?->only(['id', 'from_location', 'to_location'])
                ];
            })
        ]);
    }

    /**
     * Create test scenario with multiple deliverers and send requests
     */
    public function createTestScenario(Request $request): JsonResponse
    {
        $delivererCount = $request->get('deliverers', 3);
        $sendRequestCount = $request->get('send_requests', 9);
        $location = $request->get('location', 'Test Location');

        // Clean up existing test data
        Response::where('message', 'LIKE', '%TEST%')->delete();
        SendRequest::where('from_location', 'LIKE', '%Test%')->delete();
        DeliveryRequest::where('from_location', 'LIKE', '%Test%')->delete();

        // Create deliverers
        $deliverers = [];
        for ($i = 1; $i <= $delivererCount; $i++) {
            $user = User::factory()->create([
                'first_name' => "Test Deliverer {$i}",
                'last_name' => 'User'
            ]);

            $delivery = DeliveryRequest::factory()->create([
                'user_id' => $user->id,
                'from_location' => "{$location} A",
                'to_location' => "{$location} B",
                'status' => 'open'
            ]);

            $deliverers[] = [
                'user' => $user,
                'delivery' => $delivery
            ];
        }

        // Create send requests
        $sendRequests = [];
        for ($i = 1; $i <= $sendRequestCount; $i++) {
            $user = User::factory()->create([
                'first_name' => "Test Sender {$i}",
                'last_name' => 'User'
            ]);

            $send = SendRequest::factory()->create([
                'user_id' => $user->id,
                'from_location' => "{$location} A",
                'to_location' => "{$location} B",
                'status' => 'open'
            ]);

            $sendRequests[] = [
                'user' => $user,
                'send' => $send
            ];
        }

        // Process matching
        foreach ($sendRequests as $sendData) {
            $this->matcher->matchSendRequest($sendData['send']);
        }

        return response()->json([
            'message' => 'Test scenario created successfully',
            'deliverers_created' => $delivererCount,
            'send_requests_created' => $sendRequestCount,
            'deliverers' => collect($deliverers)->map(function($d) {
                return [
                    'deliverer_id' => $d['user']->id,
                    'name' => $d['user']->first_name . ' ' . $d['user']->last_name,
                    'delivery_request_id' => $d['delivery']->id
                ];
            }),
            'capacity_stats' => $this->rebalancingService->getSystemCapacityStats()
        ]);
    }

    /**
     * Simulate deliverer accepting a response
     */
    public function acceptResponse(Request $request): JsonResponse
    {
        $responseId = $request->get('response_id');
        $userId = $request->get('user_id');

        if (!$responseId || !$userId) {
            return response()->json(['error' => 'response_id and user_id are required'], 400);
        }

        $response = Response::find($responseId);
        if (!$response) {
            return response()->json(['error' => 'Response not found'], 404);
        }

        // Get capacity info before acceptance
        $capacityBefore = $this->capacityService->getDelivererCapacityInfo($userId);

        // Accept the response
        $result = $this->matcher->handleUserResponse($responseId, $userId, 'accept');

        // Get capacity info after acceptance
        $capacityAfter = $this->capacityService->getDelivererCapacityInfo($userId);

        return response()->json([
            'success' => $result,
            'response_status' => $response->fresh()->only(['id', 'overall_status', 'deliverer_status', 'sender_status']),
            'capacity_before' => $capacityBefore,
            'capacity_after' => $capacityAfter,
            'rebalancing_occurred' => $capacityBefore['current_load'] !== $capacityAfter['current_load']
        ]);
    }

    /**
     * Get all responses for testing/debugging
     */
    public function listResponses(): JsonResponse
    {
        $responses = Response::with(['user', 'responder'])
            ->whereIn('overall_status', ['pending', 'partial', 'accepted'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'responses' => $responses->map(function($response) {
                return [
                    'id' => $response->id,
                    'deliverer' => $response->user ? $response->user->first_name . ' ' . $response->user->last_name : 'Unknown',
                    'sender' => $response->responder ? $response->responder->first_name . ' ' . $response->responder->last_name : 'Unknown',
                    'overall_status' => $response->overall_status,
                    'deliverer_status' => $response->deliverer_status,
                    'sender_status' => $response->sender_status,
                    'offer_type' => $response->offer_type,
                    'response_type' => $response->response_type,
                    'can_deliverer_act' => $response->canUserTakeAction($response->user_id),
                    'can_sender_act' => $response->canUserTakeAction($response->responder_id),
                    'created_at' => $response->created_at
                ];
            })
        ]);
    }

    /**
     * Reset all test data
     */
    public function resetTestData(): JsonResponse
    {
        // Delete responses with test message or created recently
        $deletedResponses = Response::where('message', 'LIKE', '%TEST%')
            ->orWhere('created_at', '>', now()->subHours(2))
            ->delete();

        // Delete test send requests
        $deletedSends = SendRequest::where('from_location', 'LIKE', '%Test%')
            ->orWhere('created_at', '>', now()->subHours(2))
            ->delete();

        // Delete test delivery requests
        $deletedDeliveries = DeliveryRequest::where('from_location', 'LIKE', '%Test%')
            ->orWhere('created_at', '>', now()->subHours(2))
            ->delete();

        // Delete test users (be careful with this in production!)
        $deletedUsers = User::where('first_name', 'LIKE', '%Test%')
            ->orWhere('created_at', '>', now()->subHours(2))
            ->delete();

        return response()->json([
            'message' => 'Test data reset successfully',
            'deleted' => [
                'responses' => $deletedResponses,
                'send_requests' => $deletedSends,
                'delivery_requests' => $deletedDeliveries,
                'users' => $deletedUsers
            ]
        ]);
    }
}