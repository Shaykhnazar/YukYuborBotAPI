<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Revolution\Google\Sheets\Facades\Sheets;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;

class GoogleSheetsService
{
    protected string $spreadsheetId;

    public function __construct()
    {
        $this->spreadsheetId = config('google.sheets.spreadsheet_id');
    }

    /**
     * Initialize Google Sheets client with service account
     * @throws Exception
     */
    private function initializeClient(): \Revolution\Google\Sheets\SheetsClient|Sheets
    {
        try {
            $credentialsPath = config('google.service.file');
            $serviceEnabled = config('google.service.enable');

            if (!$serviceEnabled) {
                throw new \RuntimeException('Google service account authentication is not enabled. Set GOOGLE_SERVICE_ENABLED=true in your .env file');
            }

            if (!file_exists($credentialsPath)) {
                throw new \RuntimeException('Google service account credentials file not found at: ' . $credentialsPath);
            }

            if (!$this->spreadsheetId) {
                throw new \RuntimeException('Google Sheets spreadsheet ID not configured. Set GOOGLE_SHEETS_SPREADSHEET_ID in your .env file');
            }

            // The revolution/laravel-google-sheets package automatically uses the service account
            // configuration from config/google.php when service.enable is true
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
                $request->user_id.'-'.($request->user->name ?? 'Unknown'),
                $request->fromLocation->name ?? "Location {$request->from_location_id}",
                $request->toLocation->name ?? "Location {$request->to_location_id}",
                $request->from_date ? $request->from_date->toISOString() : '',
                $request->to_date ? $request->to_date->toISOString() : '',
                $request->size_type ?? '',
                $request->description ?? '',
                $request->status ?? 'open',
                $request->created_at->toISOString(),
                $request->updated_at->toISOString(),
                'не получен', // Ответ получен
                0, // Количество ответов
                '', // Время ожидания первого ответа
                'не принят', // Ответ принят
                '', // Время принятия ответа
                '' // Время ожидания принятия
            ];

            $sheet = Sheets::spreadsheet($this->spreadsheetId)->sheet('Deliver requests');

            // Set creation lock to coordinate with other processes
            $creationLockKey = "gsheets:creating:Deliver requests:{$request->id}";
            Cache::put($creationLockKey, true, now()->addMinutes(2));

            // Append the data first
            $sheet->append([$data]);

            Log::info('Delivery request record appended to Google Sheets', [
                'request_id' => $request->id
            ]);

            // Add a small delay to let Google Sheets process the append
            usleep(250000); // 0.25 seconds

            // Try a simple reverse search from the end for the just-appended record
            $this->findAndCacheRecentlyAppendedRow('Deliver requests', $request->id);

            // Clear the creation lock
            Cache::forget($creationLockKey);

            return true;
        } catch (Exception $appendError) {
            Log::error('Failed to append delivery request to Google Sheets', [
                'error' => $appendError->getMessage(),
                'request_id' => $request->id
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
                $request->updated_at->toISOString(),
                'не получен', // Ответ получен
                0, // Количество ответов
                '', // Время ожидания первого ответа
                'не принят', // Ответ принят
                '', // Время принятия ответа
                '' // Время ожидания принятия
            ];

            $sheet = Sheets::spreadsheet($this->spreadsheetId)->sheet('Send requests');

            // Set creation lock to coordinate with other processes
            $creationLockKey = "gsheets:creating:Send requests:{$request->id}";
            Cache::put($creationLockKey, true, now()->addMinutes(2));

            // Append the data first
            $sheet->append([$data]);

            Log::info('Send request record appended to Google Sheets', [
                'request_id' => $request->id
            ]);

            // Add a small delay to let Google Sheets process the append
            usleep(250000); // 0.25 seconds

            // Try a simple reverse search from the end for the just-appended record
            $this->findAndCacheRecentlyAppendedRow('Send requests', $request->id);

            // Clear the creation lock
            Cache::forget($creationLockKey);

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

            // More efficient approach: search in batches to avoid timeout
            $batchSize = 100;
            $startRow = 1;

            while ($startRow <= 500) { // Reasonable limit to prevent infinite loop
                $endRow = $startRow + $batchSize - 1;
                $batchRange = "A{$startRow}:K{$endRow}"; // Only need columns A-K for this operation

                try {
                    $values = $sheet->range($batchRange)->get();

                    if (is_array($values) && !empty($values)) {
                        foreach ($values as $rowIndex => $row) {
                            if (isset($row[0]) && $row[0] == $requestId) {
                                $actualRowNum = $startRow + $rowIndex; // Actual row number in sheet

                                // Update status column (index 8) and updated_at column (index 10)
                                $sheet->range("I" . $actualRowNum)->update([[$status]]);
                                $sheet->range("K" . $actualRowNum)->update([[Carbon::now()->toISOString()]]);

                                Log::info("Request status updated in Google Sheets", [
                                    'worksheet' => $worksheetName,
                                    'request_id' => $requestId,
                                    'status' => $status,
                                    'row_number' => $actualRowNum
                                ]);
                                return true;
                            }
                        }
                    }

                    $startRow += $batchSize;
                } catch (Exception $batchError) {
                    Log::warning("Batch search failed in updateRequestStatus", [
                        'worksheet' => $worksheetName,
                        'request_id' => $requestId,
                        'batch_range' => $batchRange,
                        'error' => $batchError->getMessage()
                    ]);
                    break;
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
            $requestData = [[
                'ID', 'User_Info', 'From_Location', 'To_Location', 'From_Date', 'To_Date', 'Size_Type', 'Description', 'Status', 'Created_At', 'Updated_At',
                'Ответ получен', 'Количество ответов', 'Время ожидания первого ответа', 'Ответ принят', 'Время принятия ответа', 'Время ожидания принятия'
            ]];

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
                    $request->updated_at->toISOString(),
                    'не получен', // Default: Response not received
                    0, // Default: 0 responses
                    '', // Default: Empty waiting time for first response
                    'не принят', // Default: Response not accepted
                    '', // Default: Empty time response accepted
                    '' // Default: Empty waiting time for acceptance
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
            $requestData = [[
                'ID', 'User_Info', 'From_Location', 'To_Location', 'From_Date', 'To_Date', 'Size_Type', 'Description', 'Status', 'Created_At', 'Updated_At',
                'Ответ получен', 'Количество ответов', 'Время ожидания первого ответа', 'Ответ принят', 'Время принятия ответа', 'Время ожидания принятия'
            ]];

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
                    $request->updated_at->toISOString(),
                    'не получен', // Default: Response not received
                    0, // Default: 0 responses
                    '', // Default: Empty waiting time for first response
                    'не принят', // Default: Response not accepted
                    '', // Default: Empty time response accepted
                    '' // Default: Empty waiting time for acceptance
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
     * Update response tracking columns when response is received
     */
    public function updateRequestResponseReceived($requestType, $requestId, $isFirstResponse = false): bool
    {
        try {
            $worksheetName = $requestType === 'send' ? 'Send requests' : 'Deliver requests';
            $sheet = Sheets::spreadsheet($this->spreadsheetId)->sheet($worksheetName);

            Log::info("GoogleSheetsService: Starting updateRequestResponseReceived", [
                'request_type' => $requestType,
                'request_id' => $requestId,
                'worksheet' => $worksheetName,
                'is_first_response' => $isFirstResponse
            ]);

            // Use the reliable row finding method
            $rowPosition = $this->findRowPosition($worksheetName, $requestId);

            if ($rowPosition) {
                // Get the full row data
                $rowData = $sheet->range("A{$rowPosition}:Q{$rowPosition}")->get();

                if (isset($rowData[0])) {
                    $this->performResponseReceivedUpdate($sheet, $rowData[0], $rowPosition, $isFirstResponse, $requestId, $worksheetName);
                    return true;
                }
            }

            Log::warning("Request ID {$requestId} not found in {$worksheetName} - skipping response tracking update", [
                'request_id' => $requestId,
                'worksheet' => $worksheetName,
                'is_first_response' => $isFirstResponse
            ]);

            // Return true to avoid marking the job as failed - this is not a critical error
            return true;
        } catch (Exception $e) {
            Log::error("Failed to update response tracking in Google Sheets", [
                'worksheet' => $worksheetName ?? 'unknown',
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Update response tracking columns when response is accepted
     */
    public function updateRequestResponseAccepted($requestType, $requestId): bool
    {
        try {
            $worksheetName = $requestType === 'send' ? 'Send requests' : 'Deliver requests';
            $sheet = Sheets::spreadsheet($this->spreadsheetId)->sheet($worksheetName);

            // More efficient approach: search in batches to avoid timeout
            $found = false;
            $batchSize = 100;
            $startRow = 1;

            while (!$found && $startRow <= 500) { // Reasonable limit to prevent infinite loop
                $endRow = $startRow + $batchSize - 1;
                $batchRange = "A{$startRow}:Q{$endRow}";

                try {
                    $values = $sheet->range($batchRange)->get();

                    if (is_array($values) && !empty($values)) {
                        foreach ($values as $rowIndex => $row) {
                            if (isset($row[0]) && $row[0] == $requestId) {
                                $currentTime = Carbon::now()->toISOString();
                                $actualRowNum = $startRow + $rowIndex; // Actual row number in sheet

                                // Column O: Response accepted (принят)
                                $sheet->range("O" . $actualRowNum)->update([["принят"]]);

                                // Column P: Time response accepted
                                $sheet->range("P" . $actualRowNum)->update([[$currentTime]]);

                                // Column Q: Waiting time for acceptance (calculated from created_at to acceptance time)
                                $createdAt = isset($row[9]) ? $row[9] : ''; // Column J (index 9) - Created_at timestamp
                                if ($createdAt) {
                                    $acceptanceWaitingTime = $this->calculateWaitingTime($createdAt, $currentTime);
                                    $sheet->range("Q" . $actualRowNum)->update([[$acceptanceWaitingTime]]);
                                }

                                // Update status column to "matched" when response is accepted
                                $sheet->range("I" . $actualRowNum)->update([["matched"]]);

                                Log::info("Request status updated to 'matched' in Google Sheets", [
                                    'worksheet' => $worksheetName,
                                    'request_id' => $requestId,
                                    'row_number' => $actualRowNum
                                ]);

                                Log::info("Request acceptance tracking updated in Google Sheets", [
                                    'worksheet' => $worksheetName,
                                    'request_id' => $requestId,
                                    'row_number' => $actualRowNum
                                ]);
                                return true;
                            }
                        }
                    }

                    $startRow += $batchSize;
                } catch (Exception $batchError) {
                    Log::warning("Batch search failed in updateRequestResponseAccepted", [
                        'worksheet' => $worksheetName,
                        'request_id' => $requestId,
                        'batch_range' => $batchRange,
                        'error' => $batchError->getMessage()
                    ]);
                    break;
                }
            }

            Log::warning("Request ID {$requestId} not found in {$worksheetName} - skipping acceptance tracking update", [
                'request_id' => $requestId,
                'worksheet' => $worksheetName
            ]);

            // Return true to avoid marking the job as failed - this is not a critical error
            return true;
        } catch (Exception $e) {
            Log::error("Failed to update acceptance tracking in Google Sheets", [
                'worksheet' => $worksheetName ?? 'unknown',
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Calculate waiting time between two timestamps
     */
    private function calculateWaitingTime($startTime, $endTime): string
    {
        try {
            // Check if startTime is already a calculated waiting time string (not a timestamp)
            if (!empty($startTime) && (
                strpos($startTime, 'минут') !== false ||
                strpos($startTime, 'секунд') !== false ||
                strpos($startTime, 'час') !== false ||
                strpos($startTime, 'день') !== false ||
                strpos($startTime, 'дней') !== false ||
                strpos($startTime, 'дня') !== false
            )) {
                // This is already a calculated waiting time, return it as-is
                Log::info('Waiting time already calculated, returning existing value', [
                    'existing_value' => $startTime
                ]);
                return $startTime;
            }

            // Check if startTime is empty or invalid
            if (empty($startTime)) {
                Log::warning('Start time is empty, cannot calculate waiting time');
                return 'Нет данных';
            }

            $interval = Carbon::parse($startTime)->diff(Carbon::parse($endTime));

            if ($interval->days > 0) {
                return trim(sprintf(
                    "%d %s %s %s %s %s",
                    $interval->days,
                    $this->pluralRu($interval->days, 'день', 'дня', 'дней'),
                    $interval->h > 0 ? $interval->h . ' ' . $this->pluralRu($interval->h, 'час', 'часа', 'часов') : '',
                    $interval->i > 0 ? $interval->i . ' минут' : '',
                    $interval->s > 0 ? $interval->s . ' секунд' : '',
                    ''
                ));
            }

            if ($interval->h > 0) {
                return trim(sprintf(
                    "%d %s %s %s",
                    $interval->h,
                    $this->pluralRu($interval->h, 'час', 'часа', 'часов'),
                    $interval->i > 0 ? $interval->i . ' минут' : '',
                    $interval->s > 0 ? $interval->s . ' секунд' : ''
                ));
            }

            if ($interval->i > 0) {
                return trim(sprintf(
                    "%d минут %s",
                    $interval->i,
                    $interval->s > 0 ? $interval->s . ' секунд' : ''
                ));
            }

            return $interval->s . ' секунд';
        } catch (Exception $e) {
            Log::error('Failed to calculate waiting time', [
                'start_time' => $startTime,
                'end_time'   => $endTime,
                'error'      => $e->getMessage()
            ]);
            return 'Ошибка расчета';
        }
    }

    private function pluralRu($number, $one, $few, $many)
    {
        $n = abs($number) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return $many;
        if ($n1 > 1 && $n1 < 5) return $few;
        if ($n1 == 1) return $one;
        return $many;
    }

    /**
     * Perform the actual response received update operations
     */
    private function performResponseReceivedUpdate($sheet, $row, $actualRowNum, $isFirstResponse, $requestId, $worksheetName): void
    {
        $currentTime = Carbon::now()->toISOString();

        // Column L: Response received (Ответ получен)
        $sheet->range("L" . $actualRowNum)->update([["получен"]]);

        if ($isFirstResponse) {
            // Column N: Waiting time for first response (calculated)
            $createdAt = $row[9] ?? ''; // Column J (index 9) - Создано
            if ($createdAt) {
                $waitingTime = $this->calculateWaitingTime($createdAt, $currentTime);
                $sheet->range("N" . $actualRowNum)->update([[$waitingTime]]);
            }
        }

        // Column M: Number of responses received (increment)
        $currentCount = isset($row[12]) && is_numeric($row[12]) ? (int)$row[12] : 0;
        $sheet->range("M" . $actualRowNum)->update([[$currentCount + 1]]);

        Log::info("Request response tracking updated in Google Sheets", [
            'worksheet' => $worksheetName,
            'request_id' => $requestId,
            'is_first_response' => $isFirstResponse,
            'response_count' => $currentCount + 1,
            'row_number' => $actualRowNum
        ]);
    }

    /**
     * Find row position for a request (cache-first O(1) approach)
     */
    private function findRowPosition(string $worksheetName, int $requestId): ?int
    {
        // Use the optimized cache key format
        $cacheKey = "gsheets:row:{$worksheetName}:{$requestId}";
        $cachedRow = Cache::get($cacheKey);

        if ($cachedRow) {
            Log::debug("Using cached row position", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId,
                'row' => $cachedRow
            ]);
            return $cachedRow;
        }

        // Check if this request was recently created (might still be getting cached)
        $creationLockKey = "gsheets:creating:{$worksheetName}:{$requestId}";
        if (Cache::get($creationLockKey)) {
            Log::info("Request is still being processed, waiting briefly", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId
            ]);

            // Wait longer for the creation process to complete
            sleep(2); // 2 seconds instead of 0.5

            // Try cache again after waiting
            $cachedRow = Cache::get($cacheKey);
            if ($cachedRow) {
                Log::info("Found cached row after waiting", [
                    'worksheet' => $worksheetName,
                    'request_id' => $requestId,
                    'row' => $cachedRow
                ]);
                return $cachedRow;
            }
        }

        // Fallback: search for the row with retry logic
        Log::warning("Cache miss - falling back to search with retries", [
            'worksheet' => $worksheetName,
            'request_id' => $requestId,
            'cache_key' => $cacheKey
        ]);

        return $this->findRowPositionWithRetry($worksheetName, $requestId);
    }

    /**
     * Find row position with retry logic for race conditions
     */
    private function findRowPositionWithRetry(string $worksheetName, int $requestId, int $maxRetries = 3): ?int
    {
        $attempts = 0;

        while ($attempts < $maxRetries) {
            $attempts++;

            Log::debug("Attempting to find row position", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId,
                'attempt' => $attempts,
                'max_retries' => $maxRetries
            ]);

            // Try the recently appended search first (most likely)
            $rowPosition = $this->findAndCacheRecentlyAppendedRow($worksheetName, $requestId);

            if ($rowPosition) {
                Log::info("Found row position after {$attempts} attempt(s)", [
                    'worksheet' => $worksheetName,
                    'request_id' => $requestId,
                    'row' => $rowPosition,
                    'attempts' => $attempts
                ]);
                return $rowPosition;
            }

            // If not found and we have more retries, wait a bit longer
            if ($attempts < $maxRetries) {
                $waitTime = $attempts * 500000; // Increasing wait time: 0.5s, 1s, 1.5s
                Log::debug("Row not found, waiting before retry", [
                    'worksheet' => $worksheetName,
                    'request_id' => $requestId,
                    'wait_microseconds' => $waitTime,
                    'next_attempt' => $attempts + 1
                ]);
                usleep($waitTime);
            }
        }

        Log::warning("Could not find row position after {$maxRetries} retries", [
            'worksheet' => $worksheetName,
            'request_id' => $requestId
        ]);

        return null;
    }

    /**
     * Find and cache recently appended row (universal dynamic approach)
     */
    private function findAndCacheRecentlyAppendedRow(string $worksheetName, int $requestId): ?int
    {
        try {
            $sheet = Sheets::spreadsheet($this->spreadsheetId)->sheet($worksheetName);

            // Step 1: Try to get a large range and search through it
            // Get more data in a single API call to reduce API overhead
            $maxSearchRange = 500; // Search up to 500 rows

            try {
                Log::info("Searching entire range for request", [
                    'worksheet' => $worksheetName,
                    'request_id' => $requestId,
                    'range' => "A2:A{$maxSearchRange}"
                ]);

                $values = $sheet->range("A2:A{$maxSearchRange}")->get();

                if (is_array($values)) {
                    foreach ($values as $index => $row) {
                        if (isset($row[0]) && $row[0] == $requestId) {
                            $actualRow = 2 + $index; // +2 because we start from row 2

                            // Cache the position
                            $cacheKey = "gsheets:row:{$worksheetName}:{$requestId}";
                            Cache::forever($cacheKey, $actualRow);

                            Log::info("Found and cached row in full range search", [
                                'worksheet' => $worksheetName,
                                'request_id' => $requestId,
                                'row' => $actualRow
                            ]);

                            return $actualRow;
                        }
                    }
                }
            } catch (Exception $rangeError) {
                Log::warning("Full range search failed, falling back to batch search", [
                    'worksheet' => $worksheetName,
                    'request_id' => $requestId,
                    'error' => $rangeError->getMessage()
                ]);
            }

            // Step 2: Fallback to original batch logic if full range fails
            $actualSheetSize = $this->getActualSheetSize($sheet, $worksheetName);

            if (!$actualSheetSize) {
                Log::warning("Could not determine sheet size", [
                    'worksheet' => $worksheetName,
                    'request_id' => $requestId
                ]);
                return null;
            }

            // Search backwards from the actual end in dynamic batches
            $batchSize = 50;
            $searchStart = max(2, $actualSheetSize - ($batchSize * 5)); // Start 5 batches back from end

            for ($currentStart = $actualSheetSize; $currentStart >= $searchStart; $currentStart -= $batchSize) {
                $batchStart = max(2, $currentStart - $batchSize + 1);
                $batchEnd = min($currentStart, $actualSheetSize);

                if ($batchStart > $batchEnd) continue;

                try {
                    $values = $sheet->range("A{$batchStart}:A{$batchEnd}")->get();

                    if (is_array($values)) {
                        foreach ($values as $index => $row) {
                            if (isset($row[0]) && $row[0] == $requestId) {
                                $actualRow = $batchStart + $index;

                                // Cache the position
                                $cacheKey = "gsheets:row:{$worksheetName}:{$requestId}";
                                Cache::forever($cacheKey, $actualRow);

                                Log::info("Found and cached row in batch search", [
                                    'worksheet' => $worksheetName,
                                    'request_id' => $requestId,
                                    'row' => $actualRow
                                ]);

                                return $actualRow;
                            }
                        }
                    }
                } catch (Exception $batchError) {
                    Log::debug("Batch search failed, trying previous batch", [
                        'worksheet' => $worksheetName,
                        'range' => "A{$batchStart}:A{$batchEnd}",
                        'error' => $batchError->getMessage()
                    ]);
                    continue;
                }
            }

            Log::warning("Request not found in sheet after comprehensive search", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId,
                'searched_up_to_row' => $actualSheetSize
            ]);

            return null;

        } catch (Exception $e) {
            Log::error("Error finding recently appended row", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Dynamically determine the actual size of the sheet
     */
    private function getActualSheetSize($sheet, string $worksheetName): ?int
    {
        try {
            // Binary search approach to find the actual last row efficiently
            $low = 1;
            $high = 5000; // Start with a reasonable upper bound
            $lastValidRow = 1;

            // First, find a reasonable upper bound by doubling
            $testRow = 100;
            while ($testRow <= 10000) {
                try {
                    $result = $sheet->range("A{$testRow}:A{$testRow}")->get();
                    if (empty($result) || !isset($result[0][0])) {
                        $high = $testRow;
                        break;
                    }
                    $lastValidRow = $testRow;
                    $testRow *= 2;
                } catch (Exception $e) {
                    // Hit the boundary, this is our upper limit
                    $high = $testRow;
                    break;
                }
            }

            // Now binary search between $lastValidRow and $high
            while ($low <= $high) {
                $mid = intval(($low + $high) / 2);

                try {
                    $result = $sheet->range("A{$mid}:A{$mid}")->get();
                    if (!empty($result) && isset($result[0][0]) && !empty(trim($result[0][0]))) {
                        // Found data at this row
                        $lastValidRow = $mid;
                        $low = $mid + 1;
                    } else {
                        // No data at this row
                        $high = $mid - 1;
                    }
                } catch (Exception $e) {
                    // Row doesn't exist or out of bounds
                    $high = $mid - 1;
                }
            }

            Log::info("Determined actual sheet size via binary search", [
                'worksheet' => $worksheetName,
                'last_row_with_data' => $lastValidRow
            ]);

            return $lastValidRow;

        } catch (Exception $e) {
            Log::error("Error determining sheet size", [
                'worksheet' => $worksheetName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Find and cache the row position for a request
     */
    private function findAndCacheRowPosition(string $worksheetName, int $requestId): ?int
    {
        try {
            $sheet = Sheets::spreadsheet($this->spreadsheetId)->sheet($worksheetName);

            // First try a broader search to get approximate sheet size
            $maxRowsToCheck = 500;

            // Search in reverse order (most recent first) since new records are appended
            for ($batchStart = $maxRowsToCheck; $batchStart >= 2; $batchStart -= 100) {
                $batchEnd = min($batchStart + 99, $maxRowsToCheck);
                $startRow = max($batchStart, 2);

                try {
                    $values = $sheet->range("A{$startRow}:A{$batchEnd}")->get();

                    if (is_array($values)) {
                        // Search through this batch
                        foreach ($values as $index => $row) {
                            if (isset($row[0]) && $row[0] == $requestId) {
                                $actualRow = $startRow + $index;

                                // Cache the position with forever TTL (consistent with append logic)
                                $cacheKey = "gsheets:row:{$worksheetName}:{$requestId}";
                                Cache::forever($cacheKey, $actualRow);

                                Log::info("Found and cached row position", [
                                    'worksheet' => $worksheetName,
                                    'request_id' => $requestId,
                                    'row' => $actualRow,
                                    'search_range' => "A{$startRow}:A{$batchEnd}"
                                ]);

                                return $actualRow;
                            }
                        }
                    }
                } catch (Exception $batchError) {
                    // If this batch fails, continue to next batch
                    Log::debug("Batch search failed, continuing", [
                        'worksheet' => $worksheetName,
                        'range' => "A{$startRow}:A{$batchEnd}",
                        'error' => $batchError->getMessage()
                    ]);
                    continue;
                }
            }

            // If reverse search failed, try a small forward search from row 2
            Log::info("Reverse search failed, trying forward search", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId
            ]);

            for ($startRow = 2; $startRow <= 200; $startRow += 50) {
                $endRow = $startRow + 49;
                try {
                    $values = $sheet->range("A{$startRow}:A{$endRow}")->get();

                    if (is_array($values)) {
                        foreach ($values as $index => $row) {
                            if (isset($row[0]) && $row[0] == $requestId) {
                                $actualRow = $startRow + $index;

                                // Cache the position with forever TTL (consistent with append logic)
                                $cacheKey = "gsheets:row:{$worksheetName}:{$requestId}";
                                Cache::forever($cacheKey, $actualRow);

                                Log::info("Found and cached row position (forward search)", [
                                    'worksheet' => $worksheetName,
                                    'request_id' => $requestId,
                                    'row' => $actualRow
                                ]);

                                return $actualRow;
                            }
                        }
                    }
                } catch (Exception $batchError) {
                    continue;
                }
            }

            Log::warning("Could not find row position for request after exhaustive search", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId
            ]);

            return null;
        } catch (Exception $e) {
            Log::error("Error finding row position", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
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

            // Initialize Deliver requests worksheet with new tracking columns
            $deliveryHeaders = [
                'ID', 'User_Info', 'From_Location', 'To_Location', 'From_Date', 'To_Date', 'Size_Type', 'Description', 'Status', 'Created_At', 'Updated_At',
                'Ответ получен', 'Количество ответов', 'Время ожидания первого ответа', 'Ответ принят', 'Время принятия ответа', 'Время ожидания принятия'
            ];
            $results['delivery_requests'] = $this->batchExport('Deliver requests', [$deliveryHeaders]);

            // Initialize Send requests worksheet with new tracking columns
            $sendHeaders = [
                'ID', 'User_Info', 'From_Location', 'To_Location', 'From_Date', 'To_Date', 'Size_Type', 'Description', 'Status', 'Created_At', 'Updated_At',
                'Ответ получен', 'Количество ответов', 'Время ожидания первого ответа', 'Ответ принят', 'Время принятия ответа', 'Время ожидания принятия'
            ];
            $results['send_requests'] = $this->batchExport('Send requests', [$sendHeaders]);

            Log::info('Google Sheets worksheets initialized successfully');
        } catch (Exception $e) {
            Log::error('Failed to initialize Google Sheets worksheets: ' . $e->getMessage());
        }

        return $results;
    }
}
