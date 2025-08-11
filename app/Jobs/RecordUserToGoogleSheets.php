<?php

namespace App\Jobs;

use App\Services\GoogleSheetsService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RecordUserToGoogleSheets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 120;





    public function __construct(
        private readonly int $userId
    ) {}

    public function handle(GoogleSheetsService $googleSheetsService): void
    {
        try {
            $user = User::with('telegramUser')->find($this->userId);

            if (!$user) {
                Log::warning('User not found for Google Sheets recording', [
                    'user_id' => $this->userId
                ]);
                return;
            }

            $result = $googleSheetsService->recordAddUser($user);

            Log::info('User recorded to Google Sheets via job', [
                'user_id' => $user->id,
                'success' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to record user to Google Sheets via job', [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);

            // Re-throw to mark job as failed and potentially retry
            throw $e;
        }
    }
}
