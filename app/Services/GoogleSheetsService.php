<?php

namespace App\Services;

use Revolution\Google\Sheets\Facades\Sheets;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;

class GoogleSheetsService
{
    protected $spreadsheetId;

    public function __construct()
    {
        $this->spreadsheetId = config('google.sheets.spreadsheet_id');
    }

    /**
     * Initialize Google Sheets client with service account
     */
    private function initializeClient()
    {
        try {
            $credentialsPath = config('google.service_account_credentials_json');

            if (!file_exists($credentialsPath)) {
                throw new Exception('Google service account credentials file not found at: ' . $credentialsPath);
            }

            if (!$this->spreadsheetId) {
                throw new Exception('Google Sheets spreadsheet ID not configured');
            }

            return Sheets::spreadsheet($this->spreadsheetId);
        } catch (Exception $e) {
            Log::error('Google Sheets client initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get worksheet information
     */
    public function getWorksheetInfo(): array
    {
        try {
            $sheets = $this->initializeClient();
            $worksheets = $sheets->sheetList();

            return [
                'count' => count($worksheets),
                'names' => $worksheets
            ];
        } catch (Exception $e) {
            Log::error('Failed to get worksheet info: ' . $e->getMessage());
            return ['count' => 0, 'names' => []];
        }
    }

    /**
     * Add user record to Google Sheets
     */
    public function recordAddUser($user): bool
    {
        try {
            // Load telegram user relationship if not already loaded
            if (!$user->relationLoaded('telegramUser')) {
                $user->load('telegramUser');
            }

            $data = [
                $user->id,
                $user->name ?? '',
                $user->phone ?? '',
                $user->city ?? '',
                $user->created_at->toISOString(),
                '@' . ($user->telegramUser->username ?? ''),
                $user->telegramUser->telegram_id ?? ''
            ];

            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet('Users')
                ->append([$data]);

            Log::info('User record added to Google Sheets', ['user_id' => $user->id]);
            return true;
        } catch (Exception $e) {
            Log::error('Failed to add user record to Google Sheets', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Add delivery request record to Google Sheets
     */
    public function recordAddDeliveryRequest($request): bool
    {
        try {
            // Load user and location relationships if not already loaded
            if (!$request->relationLoaded('user')) {
                $request->load('user');
            }
            if (!$request->relationLoaded('fromLocation')) {
                $request->load(['fromLocation', 'toLocation']);
            }

            $data = [
                $request->id,
                $request->user_id . '-' . ($request->user->name ?? 'Unknown'),
                $request->fromLocation->name ?? "Location {$request->from_location_id}",
                $request->toLocation->name ?? "Location {$request->to_location_id}",
                $request->from_date ? $request->from_date->toISOString() : '',
                $request->to_date ? $request->to_date->toISOString() : '',
                $request->size_type ?? '',
                $request->description ?? '',
                $request->status ?? 'open',
                $request->created_at->toISOString(),
                $request->updated_at->toISOString()
            ];

            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet('Deliver requests')
                ->append([$data]);

            Log::info('Delivery request record added to Google Sheets', ['request_id' => $request->id]);
            return true;
        } catch (Exception $e) {
            Log::error('Failed to add delivery request record to Google Sheets', [
                'request_id' => $request->id ?? null,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Add send request record to Google Sheets
     */
    public function recordAddSendRequest($request): bool
    {
        try {
            // Load user and location relationships if not already loaded
            if (!$request->relationLoaded('user')) {
                $request->load('user');
            }
            if (!$request->relationLoaded('fromLocation')) {
                $request->load(['fromLocation', 'toLocation']);
            }

            $data = [
                $request->id,
                $request->user_id . '-' . ($request->user->name ?? 'Unknown'),
                $request->fromLocation->name ?? "Location {$request->from_location_id}",
                $request->toLocation->name ?? "Location {$request->to_location_id}",
                $request->from_date ? $request->from_date->toISOString() : '',
                $request->to_date ? $request->to_date->toISOString() : '',
                $request->size_type ?? '',
                $request->description ?? '',
                $request->status ?? 'open',
                $request->created_at->toISOString(),
                $request->updated_at->toISOString()
            ];

            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet('Send requests')
                ->append([$data]);

            Log::info('Send request record added to Google Sheets', ['request_id' => $request->id]);
            return true;
        } catch (Exception $e) {
            Log::error('Failed to add send request record to Google Sheets', [
                'request_id' => $request->id ?? null,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Close delivery request (update status)
     */
    public function recordCloseDeliveryRequest($requestId): bool
    {
        return $this->updateRequestStatus('Deliver requests', $requestId, 'closed');
    }

    /**
     * Close send request (update status)
     */
    public function recordCloseSendRequest($requestId): bool
    {
        return $this->updateRequestStatus('Send requests', $requestId, 'closed');
    }

    /**
     * Update request status in Google Sheets
     */
    private function updateRequestStatus($worksheetName, $requestId, $status): bool
    {
        try {
            $sheet = Sheets::spreadsheet($this->spreadsheetId)->sheet($worksheetName);
            $values = $sheet->all();

            foreach ($values as $rowIndex => $row) {
                if (isset($row[0]) && $row[0] == $requestId) {
                    // Update status column (index 8) and updated_at column (index 10)
                    $sheet->update("I" . ($rowIndex + 1), [[$status]]);
                    $sheet->update("K" . ($rowIndex + 1), [[Carbon::now()->toISOString()]]);

                    Log::info("Request status updated in Google Sheets", [
                        'worksheet' => $worksheetName,
                        'request_id' => $requestId,
                        'status' => $status
                    ]);
                    return true;
                }
            }

            Log::warning("Request ID {$requestId} not found in {$worksheetName}");
            return false;
        } catch (Exception $e) {
            Log::error("Failed to update request status in Google Sheets", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get all data from a specific worksheet
     */
    public function getWorksheetData($worksheetName): array
    {
        try {
            return Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->all();
        } catch (Exception $e) {
            Log::error("Failed to get worksheet data from Google Sheets", [
                'worksheet' => $worksheetName,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Export data to Google Sheets in batch
     */
    public function batchExport($worksheetName, $data): bool
    {
        try {
            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->clear()
                ->append($data);

            Log::info("Data batch exported to Google Sheets", [
                'worksheet' => $worksheetName,
                'rows_count' => count($data)
            ]);
            return true;
        } catch (Exception $e) {
            Log::error("Failed to batch export data to Google Sheets", [
                'worksheet' => $worksheetName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Sync database data to Google Sheets
     */
    public function syncAllData(): array
    {
        $results = [
            'users' => false,
            'delivery_requests' => false,
            'send_requests' => false
        ];

        // Sync Users
        try {
            $users = User::with('telegramUser')->get();
            $userData = [['ID', 'Name', 'Phone', 'City', 'Created_At', 'Telegram_Username', 'Telegram_ID']];

            foreach ($users as $user) {
                $userData[] = [
                    $user->id,
                    $user->name ?? '',
                    $user->phone ?? '',
                    $user->city ?? '',
                    $user->created_at->toISOString(),
                    '@' . ($user->telegramUser->username ?? ''),
                    $user->telegramUser->telegram_id ?? ''
                ];
            }

            $results['users'] = $this->batchExport('Users', $userData);
            Log::info('Users data synced to Google Sheets', ['count' => count($users)]);
        } catch (Exception $e) {
            Log::error('Failed to sync users to Google Sheets: ' . $e->getMessage());
        }

        // Sync Delivery Requests
        try {
            $requests = DeliveryRequest::with(['user', 'fromLocation', 'toLocation'])->get();
            $requestData = [['ID', 'User_Info', 'From_Location', 'To_Location', 'From_Date', 'To_Date', 'Size_Type', 'Description', 'Status', 'Created_At', 'Updated_At']];

            foreach ($requests as $request) {
                $requestData[] = [
                    $request->id,
                    $request->user_id . '-' . ($request->user->name ?? 'Unknown'),
                    $request->fromLocation->name ?? "Location {$request->from_location_id}",
                    $request->toLocation->name ?? "Location {$request->to_location_id}",
                    $request->from_date ? $request->from_date->toISOString() : '',
                    $request->to_date ? $request->to_date->toISOString() : '',
                    $request->size_type ?? '',
                    $request->description ?? '',
                    $request->status ?? 'open',
                    $request->created_at->toISOString(),
                    $request->updated_at->toISOString()
                ];
            }

            $results['delivery_requests'] = $this->batchExport('Deliver requests', $requestData);
            Log::info('Delivery requests data synced to Google Sheets', ['count' => count($requests)]);
        } catch (Exception $e) {
            Log::error('Failed to sync delivery requests to Google Sheets: ' . $e->getMessage());
        }

        // Sync Send Requests
        try {
            $requests = SendRequest::with(['user', 'fromLocation', 'toLocation'])->get();
            $requestData = [['ID', 'User_Info', 'From_Location', 'To_Location', 'From_Date', 'To_Date', 'Size_Type', 'Description', 'Status', 'Created_At', 'Updated_At']];

            foreach ($requests as $request) {
                $requestData[] = [
                    $request->id,
                    $request->user_id . '-' . ($request->user->name ?? 'Unknown'),
                    $request->fromLocation->name ?? "Location {$request->from_location_id}",
                    $request->toLocation->name ?? "Location {$request->to_location_id}",
                    $request->from_date ? $request->from_date->toISOString() : '',
                    $request->to_date ? $request->to_date->toISOString() : '',
                    $request->size_type ?? '',
                    $request->description ?? '',
                    $request->status ?? 'open',
                    $request->created_at->toISOString(),
                    $request->updated_at->toISOString()
                ];
            }

            $results['send_requests'] = $this->batchExport('Send requests', $requestData);
            Log::info('Send requests data synced to Google Sheets', ['count' => count($requests)]);
        } catch (Exception $e) {
            Log::error('Failed to sync send requests to Google Sheets: ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Initialize Google Sheets with proper headers
     */
    public function initializeWorksheets(): array
    {
        $results = [];

        try {
            // Initialize Users worksheet
            $usersHeaders = ['ID', 'Name', 'Phone', 'City', 'Created_At', 'Telegram_Username', 'Telegram_ID'];
            $results['users'] = $this->batchExport('Users', [$usersHeaders]);

            // Initialize Deliver requests worksheet
            $deliveryHeaders = ['ID', 'User_Info', 'From_Location', 'To_Location', 'From_Date', 'To_Date', 'Size_Type', 'Description', 'Status', 'Created_At', 'Updated_At'];
            $results['delivery_requests'] = $this->batchExport('Deliver requests', [$deliveryHeaders]);

            // Initialize Send requests worksheet
            $sendHeaders = ['ID', 'User_Info', 'From_Location', 'To_Location', 'From_Date', 'To_Date', 'Size_Type', 'Description', 'Status', 'Created_At', 'Updated_At'];
            $results['send_requests'] = $this->batchExport('Send requests', [$sendHeaders]);

            Log::info('Google Sheets worksheets initialized successfully');
        } catch (Exception $e) {
            Log::error('Failed to initialize Google Sheets worksheets: ' . $e->getMessage());
        }

        return $results;
    }
}
