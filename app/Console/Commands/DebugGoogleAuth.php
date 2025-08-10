<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Revolution\Google\Sheets\Facades\Sheets;
use Revolution\Google\Client\Facades\Google;
use Exception;

class DebugGoogleAuth extends Command
{
    protected $signature = 'google:debug-auth';
    
    protected $description = 'Debug Google authentication';

    public function handle()
    {
        $this->info('Debugging Google authentication...');

        try {
            // Test 1: Check service account file content
            $this->info('1. Checking service account file...');
            $credentialsPath = config('google.service.file');
            
            if (!file_exists($credentialsPath)) {
                $this->error("Credentials file not found: {$credentialsPath}");
                return 1;
            }
            
            $credentialsContent = json_decode(file_get_contents($credentialsPath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Invalid JSON in credentials file');
                return 1;
            }
            
            $this->line("   Project ID: " . ($credentialsContent['project_id'] ?? 'NOT_FOUND'));
            $this->line("   Client Email: " . ($credentialsContent['client_email'] ?? 'NOT_FOUND'));
            $this->line("   Private Key ID: " . (isset($credentialsContent['private_key_id']) ? 'PRESENT' : 'MISSING'));
            
            // Test 2: Check configuration values
            $this->info('2. Checking configuration...');
            $serviceEnabled = config('google.service.enable');
            $scopes = config('google.scopes', []);
            $this->line("   Service enabled: " . ($serviceEnabled ? 'YES' : 'NO'));
            $this->line("   Configured scopes: " . (empty($scopes) ? 'NONE' : implode(', ', $scopes)));
            
            // Test 3: Try to use Sheets facade directly
            $this->info('3. Testing Sheets facade...');
            $spreadsheetId = config('google.sheets.spreadsheet_id');
            
            try {
                $sheetsClient = Sheets::spreadsheet($spreadsheetId);
                $this->line("   Sheets client created successfully");
                
                // Test 4: Try to get sheet list
                $this->info('4. Testing sheet list...');
                $sheetNames = $sheetsClient->sheetList();
                $this->line("   Found " . count($sheetNames) . " sheets: " . implode(', ', $sheetNames));
                
                $this->info('✅ Google Sheets facade is working!');
                return 0;
                
            } catch (\Google\Service\Exception $googleException) {
                $this->error('Google Service Exception: ' . $googleException->getMessage());
                
                // Parse the error response
                $errorData = json_decode($googleException->getMessage(), true);
                if (isset($errorData['error'])) {
                    $error = $errorData['error'];
                    $this->line("   Error Code: " . $error['code']);
                    $this->line("   Error Message: " . $error['message']);
                    
                    if (isset($error['errors'])) {
                        foreach ($error['errors'] as $errorDetail) {
                            $this->line("   - Reason: " . ($errorDetail['reason'] ?? 'unknown'));
                            $this->line("   - Domain: " . ($errorDetail['domain'] ?? 'unknown'));
                            $this->line("   - Location: " . ($errorDetail['location'] ?? 'unknown'));
                        }
                    }
                    
                    // Provide specific suggestions based on error
                    if ($error['code'] == 401) {
                        $this->warn('AUTHENTICATION ISSUE:');
                        $this->line('1. Make sure the service account has access to the spreadsheet');
                        $this->line('2. Share the Google Sheet with the service account email: ' . ($credentialsContent['client_email'] ?? 'unknown'));
                        $this->line('3. Check that Google Sheets API is enabled in your Google Cloud Console');
                    } elseif ($error['code'] == 403) {
                        $this->warn('PERMISSION ISSUE:');
                        $this->line('1. Enable Google Sheets API in Google Cloud Console');
                        $this->line('2. Make sure the service account has proper roles');
                    } elseif ($error['code'] == 404) {
                        $this->warn('SPREADSHEET NOT FOUND:');
                        $this->line('1. Check that the spreadsheet ID is correct');
                        $this->line('2. Make sure the spreadsheet exists and is accessible');
                    }
                }
                return 1;
            }
            

        } catch (Exception $e) {
            $this->error('❌ Debug failed: ' . $e->getMessage());
            
            if ($this->option('verbose')) {
                $this->error('Stack trace: ' . $e->getTraceAsString());
            }
            
            return 1;
        }
    }
}