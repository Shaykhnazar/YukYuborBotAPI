<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleSheetsService;
use Exception;

class TestGoogleSheets extends Command
{
    protected $signature = 'google-sheets:test';
    
    protected $description = 'Test Google Sheets connection and configuration';

    public function handle(GoogleSheetsService $sheetsService)
    {
        $this->info('Testing Google Sheets integration...');

        try {
            // Test 1: Check configuration
            $this->info('1. Checking configuration...');
            $serviceEnabled = config('google.service.enable');
            $credentialsPath = config('google.service.file');
            $spreadsheetId = config('google.sheets.spreadsheet_id');
            
            $this->line("   Service Enabled: " . ($serviceEnabled ? 'YES' : 'NO'));
            $this->line("   Credentials Path: {$credentialsPath}");
            $this->line("   Credentials File Exists: " . (file_exists($credentialsPath) ? 'YES' : 'NO'));
            $this->line("   Spreadsheet ID: {$spreadsheetId}");
            
            if (!$serviceEnabled || !file_exists($credentialsPath) || !$spreadsheetId) {
                $this->error('Configuration is incomplete!');
                return 1;
            }

            // Test 2: Test connection and get worksheet info
            $this->info('2. Testing connection...');
            try {
                $worksheetInfo = $sheetsService->getWorksheetInfo();
                
                if (empty($worksheetInfo['names'])) {
                    $this->error('Failed to connect to Google Sheets or no worksheets found!');
                    $this->line("Worksheet info response: " . json_encode($worksheetInfo));
                    return 1;
                }
            } catch (Exception $connException) {
                $this->error('Connection failed: ' . $connException->getMessage());
                if ($this->option('verbose')) {
                    $this->error('Stack trace: ' . $connException->getTraceAsString());
                }
                return 1;
            }

            $this->line("   Found {$worksheetInfo['count']} worksheets:");
            foreach ($worksheetInfo['names'] as $name) {
                $this->line("   - {$name}");
            }

            // Test 3: Try to read data from a worksheet
            $this->info('3. Testing data retrieval...');
            $testWorksheet = $worksheetInfo['names'][0] ?? 'Users';
            
            try {
                $data = $sheetsService->getWorksheetData($testWorksheet);
                $rowCount = count($data);
                $this->line("   Successfully read {$rowCount} rows from '{$testWorksheet}' worksheet");
                
                if ($rowCount > 0) {
                    $headerCount = count($data[0] ?? []);
                    $this->line("   Header columns: {$headerCount}");
                }
            } catch (Exception $e) {
                $this->warn("   Could not read data from '{$testWorksheet}': " . $e->getMessage());
            }

            $this->info('✅ Google Sheets integration test completed successfully!');
            return 0;

        } catch (Exception $e) {
            $this->error('❌ Google Sheets test failed: ' . $e->getMessage());
            
            if ($this->option('verbose')) {
                $this->error('Stack trace: ' . $e->getTraceAsString());
            }
            
            return 1;
        }
    }
}