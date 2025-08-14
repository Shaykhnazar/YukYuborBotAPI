<?php

namespace App\Console\Commands;

use App\Services\GoogleSheetsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestSimplifiedGoogleSheets extends Command
{
    protected $signature = 'test:gsheets-simplified';
    protected $description = 'Test the simplified Google Sheets service';

    public function __construct(
        private GoogleSheetsService $googleSheetsService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Testing Simplified Google Sheets Service...');

        try {
            // Test getting worksheet data
            $this->info('Testing worksheet data retrieval...');
            $deliveryData = $this->googleSheetsService->getWorksheetData('Deliver requests');
            $this->info('Deliver requests data count: ' . count($deliveryData));

            $sendData = $this->googleSheetsService->getWorksheetData('Send requests');
            $this->info('Send requests data count: ' . count($sendData));

            if (!empty($deliveryData)) {
                $this->info('Sample delivery request data:');
                $this->table(['Column A', 'Column B', 'Column C'], [array_slice($deliveryData[0] ?? [], 0, 3)]);
            }

            // Test updating a response (if data exists)
            if (!empty($deliveryData) && count($deliveryData) > 1) {
                $firstRequestId = $deliveryData[1][0] ?? null; // First data row, column A
                if ($firstRequestId) {
                    $this->info("Testing response tracking update for request ID: {$firstRequestId}");
                    $result = $this->googleSheetsService->updateRequestResponseReceived('delivery', $firstRequestId, false);
                    $this->info('Update result: ' . ($result ? 'SUCCESS' : 'FAILED'));
                }
            }

            $this->info('✅ Test completed successfully!');
            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Test failed: ' . $e->getMessage());
            Log::error('Simplified Google Sheets test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return self::FAILURE;
        }
    }
}
