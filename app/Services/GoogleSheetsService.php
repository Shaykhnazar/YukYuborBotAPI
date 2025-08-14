<?php

namespace App\Services;

use Carbon\Carbon;
use Revolution\Google\Sheets\Facades\Sheets;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;

class GoogleSheetsService
{
    protected ?string $spreadsheetId;

    public function __construct()
    {
        $this->spreadsheetId = config('google.sheets.spreadsheet_id');
    }

    /**
     * Add user record to Google Sheets
     */
    public function recordAddUser($user): bool
    {
        if (is_null($this->spreadsheetId)) {
            Log::warning('Google Sheets spreadsheet ID not configured, skipping recordAddUser');
            return true;
        }

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
        if (is_null($this->spreadsheetId)) {
            Log::warning('Google Sheets spreadsheet ID not configured, skipping recordAddDeliveryRequest');
            return true;
        }

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
        if (is_null($this->spreadsheetId)) {
            Log::warning('Google Sheets spreadsheet ID not configured, skipping recordAddSendRequest');
            return true;
        }

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
     * Update response tracking columns when response is received
     */
    public function updateRequestResponseReceived($requestType, $requestId, $isFirstResponse = false): bool
    {
        if (is_null($this->spreadsheetId)) {
            Log::warning('Google Sheets spreadsheet ID not configured, skipping updateRequestResponseReceived');
            return true;
        }

        try {
            $worksheetName = $requestType === 'send' ? 'Send requests' : 'Deliver requests';

            Log::info("GoogleSheetsService: Starting updateRequestResponseReceived", [
                'request_type' => $requestType,
                'request_id' => $requestId,
                'worksheet' => $worksheetName,
                'is_first_response' => $isFirstResponse
            ]);

            // Get all data from the sheet
            $allData = Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->all();

            if (empty($allData)) {
                Log::warning("Worksheet is empty", ['worksheet' => $worksheetName]);
                return true;
            }

            // Find the row with matching request ID
            $rowNumber = null;
            foreach ($allData as $index => $row) {
                if (isset($row[0]) && $row[0] == $requestId) {
                    $rowNumber = $index + 1; // Sheets are 1-indexed
                    break;
                }
            }

            if (!$rowNumber) {
                Log::warning("Request ID {$requestId} not found in {$worksheetName} - skipping response tracking update");
                return true;
            }

            $currentTime = Carbon::now()->toISOString();

            // Update Column L: Response received (Ответ получен)
            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->range("L{$rowNumber}")
                ->update([["получен"]]);

            // Update Column M: Number of responses received (increment)
            $currentCount = isset($allData[$rowNumber - 1][12]) && is_numeric($allData[$rowNumber - 1][12])
                ? (int)$allData[$rowNumber - 1][12] : 0;

            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->range("M{$rowNumber}")
                ->update([[$currentCount + 1]]);

            // Update Column N: Waiting time for first response (if this is the first response)
            if ($isFirstResponse) {
                $createdAt = $allData[$rowNumber - 1][9] ?? ''; // Column J (index 9) - Created_at
                if ($createdAt) {
                    $waitingTime = $this->calculateWaitingTime($createdAt, $currentTime);
                    Sheets::spreadsheet($this->spreadsheetId)
                        ->sheet($worksheetName)
                        ->range("N{$rowNumber}")
                        ->update([[$waitingTime]]);
                }
            }

            Log::info("Request response tracking updated in Google Sheets", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId,
                'is_first_response' => $isFirstResponse,
                'response_count' => $currentCount + 1,
                'row_number' => $rowNumber
            ]);

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
        if (is_null($this->spreadsheetId)) {
            Log::warning('Google Sheets spreadsheet ID not configured, skipping updateRequestResponseAccepted');
            return true;
        }

        try {
            $worksheetName = $requestType === 'send' ? 'Send requests' : 'Deliver requests';

            Log::info("GoogleSheetsService: Starting updateRequestResponseAccepted", [
                'request_type' => $requestType,
                'request_id' => $requestId,
                'worksheet' => $worksheetName
            ]);

            // Get all data from the sheet
            $allData = Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->all();

            if (empty($allData)) {
                Log::warning("Worksheet is empty", ['worksheet' => $worksheetName]);
                return true;
            }

            // Find the row with matching request ID
            $rowNumber = null;
            foreach ($allData as $index => $row) {
                if (isset($row[0]) && $row[0] == $requestId) {
                    $rowNumber = $index + 1; // Sheets are 1-indexed
                    break;
                }
            }

            if (!$rowNumber) {
                Log::warning("Request ID {$requestId} not found in {$worksheetName} - skipping acceptance tracking update");
                return true;
            }

            $currentTime = Carbon::now()->toISOString();

            // Update Column O: Response accepted (принят)
            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->range("O{$rowNumber}")
                ->update([["принят"]]);

            // Update Column P: Time response accepted
            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->range("P{$rowNumber}")
                ->update([[$currentTime]]);

            // Update Column Q: Waiting time for acceptance (calculated from created_at to acceptance time)
            $createdAt = isset($allData[$rowNumber - 1][9]) ? $allData[$rowNumber - 1][9] : ''; // Column J (index 9)
            if ($createdAt) {
                $acceptanceWaitingTime = $this->calculateWaitingTime($createdAt, $currentTime);
                Sheets::spreadsheet($this->spreadsheetId)
                    ->sheet($worksheetName)
                    ->range("Q{$rowNumber}")
                    ->update([[$acceptanceWaitingTime]]);
            }

            // Update status column to "matched" when response is accepted
            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->range("I{$rowNumber}")
                ->update([["matched"]]);

            Log::info("Request acceptance tracking updated in Google Sheets", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId,
                'row_number' => $rowNumber
            ]);

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
        if (is_null($this->spreadsheetId)) {
            Log::warning('Google Sheets spreadsheet ID not configured, skipping updateRequestStatus');
            return true;
        }

        try {
            // Get all data from the sheet
            $allData = Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->all();

            if (empty($allData)) {
                Log::warning("Worksheet is empty", ['worksheet' => $worksheetName]);
                return true;
            }

            // Find the row with matching request ID
            $rowNumber = null;
            foreach ($allData as $index => $row) {
                if (isset($row[0]) && $row[0] == $requestId) {
                    $rowNumber = $index + 1; // Sheets are 1-indexed
                    break;
                }
            }

            if (!$rowNumber) {
                Log::warning("Request ID {$requestId} not found in {$worksheetName}");
                return true;
            }

            // Update status column (index 8) and updated_at column (index 10)
            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->range("I{$rowNumber}")
                ->update([[$status]]);

            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet($worksheetName)
                ->range("K{$rowNumber}")
                ->update([[Carbon::now()->toISOString()]]);

            Log::info("Request status updated in Google Sheets", [
                'worksheet' => $worksheetName,
                'request_id' => $requestId,
                'status' => $status,
                'row_number' => $rowNumber
            ]);

            return true;
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
                return $startTime;
            }

            if (empty($startTime)) {
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
     * Get all data from a specific worksheet
     */
    public function getWorksheetData($worksheetName): array
    {
        if (is_null($this->spreadsheetId)) {
            Log::warning('Google Sheets spreadsheet ID not configured, returning empty array');
            return [];
        }

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
        if (is_null($this->spreadsheetId)) {
            Log::warning('Google Sheets spreadsheet ID not configured, skipping batchExport');
            return true;
        }

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
     * Initialize Google Sheets with proper headers
     */
    public function initializeWorksheets(): array
    {
        if (is_null($this->spreadsheetId)) {
            Log::warning('Google Sheets spreadsheet ID not configured, skipping initializeWorksheets');
            return ['error' => 'Spreadsheet ID not configured'];
        }

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
