<?php

namespace App\Console\Commands;

use App\Models\Response;
use App\Models\User;
use App\Services\UserRequest\UserRequestService;
use Illuminate\Console\Command;

class DebugChatIssue extends Command
{
    protected $signature = 'debug:chat-issue {user_id : User ID to debug}';
    protected $description = 'Debug chat_id issue for specific user';

    public function handle(): int
    {
        $userId = $this->argument('user_id');
        $user = User::find($userId);
        
        if (!$user) {
            $this->error("User {$userId} not found");
            return 1;
        }
        
        $this->info("Debugging chat issue for user {$userId} ({$user->name})");
        
        // Get user's responses
        $sendResponses = Response::where('responder_id', $userId)
            ->orWhere('user_id', $userId)
            ->where('overall_status', 'accepted')
            ->get();
            
        $this->info("Found " . $sendResponses->count() . " accepted responses for this user:");
        
        foreach ($sendResponses as $response) {
            $this->line("Response {$response->id}:");
            $this->line("  - user_id: {$response->user_id}");
            $this->line("  - responder_id: {$response->responder_id}");
            $this->line("  - chat_id: " . ($response->chat_id ?: 'NULL'));
            $this->line("  - offer_type: {$response->offer_type}");
            $this->line("  - offer_id: {$response->offer_id}");
            $this->line("  - request_id: {$response->request_id}");
            $this->line("  - overall_status: {$response->overall_status}");
            $this->line("  - User role for {$userId}: " . $response->getUserRole($userId));
            $this->line("");
        }
        
        // Test UserRequestService
        $userRequestService = app(UserRequestService::class);
        $requests = $userRequestService->getUserRequestsRaw($user, ['filter' => 'send', 'status' => 'active', 'search' => '']);
        
        $this->info("UserRequestService returned " . $requests->count() . " send requests:");
        
        foreach ($requests as $request) {
            $this->line("Request {$request->id}:");
            $this->line("  - type: {$request->type}");
            $this->line("  - status: {$request->status}");
            $this->line("  - chat_id: " . ($request->chat_id ?? 'NULL'));
            $this->line("  - response_id: " . ($request->response_id ?? 'NULL'));
            $this->line("  - user_role: " . ($request->user_role ?? 'NULL'));
            $this->line("");
        }
        
        return 0;
    }
}