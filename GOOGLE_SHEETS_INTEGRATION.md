# Google Sheets Integration Documentation

## Overview

This document provides comprehensive guidance for integrating Google Sheets functionality from the PostLink Telegram bot into a Laravel API + Nuxt.js web application. The integration allows seamless data synchronization between your web app and Google Sheets for users, requests, and analytics.

## Current Implementation Analysis

### Telegram Bot Implementation (Python)

The bot currently uses the following Google Sheets integration:

**Dependencies:**
- `gspread==6.2.0` - Google Sheets API client
- `google-auth==2.38.0` - Authentication
- `google-auth-oauthlib==1.2.1` - OAuth2 flow

**Core Functions:**
- `client_init_json()` - Initialize Google Sheets client using service account credentials
- `get_table()` - Open spreadsheet by key/ID
- `get_worksheet_info()` - Get worksheet metadata
- `record_add_user()` - Add user data to "Users" worksheet
- `record_add_deliver_req()` - Add delivery request to "Deliver requests" worksheet
- `record_add_send_req()` - Add send request to "Send requests" worksheet
- `record_close_deliver_req()` - Update delivery request status to "closed"
- `record_close_send_req()` - Update send request status to "closed"

**Data Structure:**

1. **Users Worksheet:**
   - Columns: [ID, Name, Phone, City, Created_At, Telegram_Username, Telegram_ID]

2. **Deliver Requests Worksheet:**
   - Columns: [ID, User_Info, From_Location, To_Location, From_Date, To_Date, Size_Type, Description, Status, Created_At, Updated_At]

3. **Send Requests Worksheet:**
   - Columns: [ID, User_Info, From_Location, To_Location, From_Date, To_Date, Size_Type, Description, Status, Created_At, Updated_At]

## Laravel API Integration

### 1. Installation & Setup

#### Step 1: Install Google Sheets Package
```bash
composer require revolution/laravel-google-sheets
```

#### Step 2: Publish Configuration
```bash
php artisan vendor:publish --provider="Revolution\Google\Sheets\SheetsServiceProvider" --tag="config"
```

#### Step 3: Environment Configuration
Add to your `.env`:
```env
GOOGLE_SERVICE_ACCOUNT_JSON_LOCATION=/path/to/service-account-credentials.json
GOOGLE_SHEETS_SPREADSHEET_ID=your_spreadsheet_id
GOOGLE_SHEETS_POST_CLEAR_RANGE=A1:Z1000
GOOGLE_SHEETS_POST_RANGE=A1:Z1000
```

### 2. Service Account Setup

#### Create Service Account File
Create `storage/app/google-service-account.json` with your credentials:
```json
{
  "type": "service_account",
  "project_id": "your-project-id",
  "private_key_id": "your-key-id",
  "private_key": "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n",
  "client_email": "your-service-account@your-project.iam.gserviceaccount.com",
  "client_id": "your-client-id",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
  "client_x509_cert_url": "https://www.googleapis.com/robot/v1/metadata/x509/your-service-account%40your-project.iam.gserviceaccount.com"
}
```

### 3. Laravel Service Implementation

#### Create Google Sheets Service
```php
// app/Services/GoogleSheetsService.php
<?php

namespace App\Services;

use Revolution\Google\Sheets\Facades\Sheets;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

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
            $data = [
                $user->id,
                $user->name,
                $user->phone,
                $user->city ?? '',
                $user->created_at->toISOString(),
                '@' . ($user->telegram_username ?? ''),
                $user->telegram_id ?? ''
            ];

            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet('Users')
                ->append([$data]);

            return true;
        } catch (Exception $e) {
            Log::error('Failed to add user record: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Add delivery request record to Google Sheets
     */
    public function recordAddDeliveryRequest($request): bool
    {
        try {
            $data = [
                $request->id,
                $request->user_id . '-' . $request->user->name,
                $request->from_location,
                $request->to_location,
                $request->from_date->toISOString(),
                $request->to_date->toISOString(),
                $request->size_type,
                $request->description,
                $request->status,
                $request->created_at->toISOString(),
                $request->updated_at->toISOString()
            ];

            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet('Deliver requests')
                ->append([$data]);

            return true;
        } catch (Exception $e) {
            Log::error('Failed to add delivery request record: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Add send request record to Google Sheets
     */
    public function recordAddSendRequest($request): bool
    {
        try {
            $data = [
                $request->id,
                $request->user_id . '-' . $request->user->name,
                $request->from_location,
                $request->to_location,
                $request->from_date->toISOString(),
                $request->to_date->toISOString(),
                $request->size_type,
                $request->description,
                $request->status,
                $request->created_at->toISOString(),
                $request->updated_at->toISOString()
            ];

            Sheets::spreadsheet($this->spreadsheetId)
                ->sheet('Send requests')
                ->append([$data]);

            return true;
        } catch (Exception $e) {
            Log::error('Failed to add send request record: ' . $e->getMessage());
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
                    // Update status column (index 8)
                    $sheet->update("I" . ($rowIndex + 1), [[$status]]);
                    return true;
                }
            }
            
            Log::warning("Request ID {$requestId} not found in {$worksheetName}");
            return false;
        } catch (Exception $e) {
            Log::error("Failed to update request status: " . $e->getMessage());
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
            Log::error("Failed to get worksheet data: " . $e->getMessage());
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

            return true;
        } catch (Exception $e) {
            Log::error("Failed to batch export data: " . $e->getMessage());
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
            $users = \App\Models\User::with('telegramUser')->get();
            $userData = [['ID', 'Name', 'Phone', 'City', 'Created_At', 'Telegram_Username', 'Telegram_ID']];
            
            foreach ($users as $user) {
                $userData[] = [
                    $user->id,
                    $user->name,
                    $user->phone,
                    $user->city ?? '',
                    $user->created_at->toISOString(),
                    '@' . ($user->telegram_user->username ?? ''),
                    $user->telegram_user->telegram_id ?? ''
                ];
            }
            
            $results['users'] = $this->batchExport('Users', $userData);
        } catch (Exception $e) {
            Log::error('Failed to sync users: ' . $e->getMessage());
        }

        // Sync Delivery Requests
        try {
            $requests = \App\Models\DeliveryRequest::with('user')->get();
            $requestData = [['ID', 'User_Info', 'From_Location', 'To_Location', 'From_Date', 'To_Date', 'Size_Type', 'Description', 'Status', 'Created_At', 'Updated_At']];
            
            foreach ($requests as $request) {
                $requestData[] = [
                    $request->id,
                    $request->user_id . '-' . $request->user->name,
                    $request->from_location,
                    $request->to_location,
                    $request->from_date->toISOString(),
                    $request->to_date->toISOString(),
                    $request->size_type,
                    $request->description,
                    $request->status,
                    $request->created_at->toISOString(),
                    $request->updated_at->toISOString()
                ];
            }
            
            $results['delivery_requests'] = $this->batchExport('Deliver requests', $requestData);
        } catch (Exception $e) {
            Log::error('Failed to sync delivery requests: ' . $e->getMessage());
        }

        // Sync Send Requests
        try {
            $requests = \App\Models\SendRequest::with('user')->get();
            $requestData = [['ID', 'User_Info', 'From_Location', 'To_Location', 'From_Date', 'To_Date', 'Size_Type', 'Description', 'Status', 'Created_At', 'Updated_At']];
            
            foreach ($requests as $request) {
                $requestData[] = [
                    $request->id,
                    $request->user_id . '-' . $request->user->name,
                    $request->from_location,
                    $request->to_location,
                    $request->from_date->toISOString(),
                    $request->to_date->toISOString(),
                    $request->size_type,
                    $request->description,
                    $request->status,
                    $request->created_at->toISOString(),
                    $request->updated_at->toISOString()
                ];
            }
            
            $results['send_requests'] = $this->batchExport('Send requests', $requestData);
        } catch (Exception $e) {
            Log::error('Failed to sync send requests: ' . $e->getMessage());
        }

        return $results;
    }
}
```

### 4. Laravel Controllers

#### Create Google Sheets Controller
```php
// app/Http/Controllers/Api/GoogleSheetsController.php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleSheetsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GoogleSheetsController extends Controller
{
    protected $sheetsService;

    public function __construct(GoogleSheetsService $sheetsService)
    {
        $this->sheetsService = $sheetsService;
    }

    /**
     * Get worksheet information
     */
    public function getWorksheetInfo(): JsonResponse
    {
        $info = $this->sheetsService->getWorksheetInfo();
        
        return response()->json([
            'success' => true,
            'data' => $info
        ]);
    }

    /**
     * Get data from specific worksheet
     */
    public function getWorksheetData(Request $request): JsonResponse
    {
        $worksheetName = $request->input('worksheet', 'Users');
        $data = $this->sheetsService->getWorksheetData($worksheetName);
        
        return response()->json([
            'success' => true,
            'worksheet' => $worksheetName,
            'data' => $data
        ]);
    }

    /**
     * Sync all database data to Google Sheets
     */
    public function syncAllData(): JsonResponse
    {
        $results = $this->sheetsService->syncAllData();
        
        return response()->json([
            'success' => true,
            'message' => 'Data synchronization completed',
            'results' => $results
        ]);
    }

    /**
     * Export specific data to Google Sheets
     */
    public function exportData(Request $request): JsonResponse
    {
        $request->validate([
            'worksheet' => 'required|string',
            'data' => 'required|array'
        ]);

        $success = $this->sheetsService->batchExport(
            $request->input('worksheet'),
            $request->input('data')
        );

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Data exported successfully' : 'Failed to export data'
        ]);
    }
}
```

### 5. Model Integration

#### Update User Model
```php
// app/Models/User.php
use App\Services\GoogleSheetsService;

class User extends Authenticatable
{
    // ... existing code ...

    /**
     * Boot method to handle Google Sheets integration
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($user) {
            // Automatically add user to Google Sheets when created
            app(GoogleSheetsService::class)->recordAddUser($user);
        });
    }
}
```

#### Update Request Models
```php
// app/Models/DeliveryRequest.php
use App\Services\GoogleSheetsService;

class DeliveryRequest extends Model
{
    // ... existing code ...

    protected static function boot()
    {
        parent::boot();

        static::created(function ($request) {
            app(GoogleSheetsService::class)->recordAddDeliveryRequest($request);
        });

        static::updated(function ($request) {
            if ($request->isDirty('status') && $request->status === 'closed') {
                app(GoogleSheetsService::class)->recordCloseDeliveryRequest($request->id);
            }
        });
    }
}

// Similar implementation for SendRequest model
```

### 6. API Routes

```php
// routes/api.php
use App\Http\Controllers\Api\GoogleSheetsController;

Route::prefix('google-sheets')->group(function () {
    Route::get('/worksheet-info', [GoogleSheetsController::class, 'getWorksheetInfo']);
    Route::get('/worksheet-data', [GoogleSheetsController::class, 'getWorksheetData']);
    Route::post('/sync-all', [GoogleSheetsController::class, 'syncAllData']);
    Route::post('/export', [GoogleSheetsController::class, 'exportData']);
});
```

## Nuxt.js Frontend Integration

### 1. Install Dependencies

```bash
npm install axios
```

### 2. Create Google Sheets Composable

```javascript
// composables/useGoogleSheets.js
export const useGoogleSheets = () => {
  const config = useRuntimeConfig()
  const baseURL = config.public.apiBase

  /**
   * Get worksheet information
   */
  const getWorksheetInfo = async () => {
    try {
      const { data } = await $fetch(`${baseURL}/api/google-sheets/worksheet-info`)
      return data
    } catch (error) {
      console.error('Failed to get worksheet info:', error)
      throw error
    }
  }

  /**
   * Get data from specific worksheet
   */
  const getWorksheetData = async (worksheetName = 'Users') => {
    try {
      const { data } = await $fetch(`${baseURL}/api/google-sheets/worksheet-data`, {
        method: 'GET',
        query: { worksheet: worksheetName }
      })
      return data
    } catch (error) {
      console.error('Failed to get worksheet data:', error)
      throw error
    }
  }

  /**
   * Sync all data to Google Sheets
   */
  const syncAllData = async () => {
    try {
      const response = await $fetch(`${baseURL}/api/google-sheets/sync-all`, {
        method: 'POST'
      })
      return response
    } catch (error) {
      console.error('Failed to sync data:', error)
      throw error
    }
  }

  /**
   * Export custom data to Google Sheets
   */
  const exportData = async (worksheetName, data) => {
    try {
      const response = await $fetch(`${baseURL}/api/google-sheets/export`, {
        method: 'POST',
        body: {
          worksheet: worksheetName,
          data: data
        }
      })
      return response
    } catch (error) {
      console.error('Failed to export data:', error)
      throw error
    }
  }

  return {
    getWorksheetInfo,
    getWorksheetData,
    syncAllData,
    exportData
  }
}
```

### 3. Create Google Sheets Management Page

```vue
<!-- pages/admin/google-sheets.vue -->
<template>
  <div class="google-sheets-management">
    <div class="header">
      <h1>Google Sheets Integration</h1>
      <button @click="refreshWorksheetInfo" :disabled="loading" class="btn-primary">
        {{ loading ? 'Loading...' : 'Refresh' }}
      </button>
    </div>

    <!-- Worksheet Information -->
    <div class="worksheet-info" v-if="worksheetInfo">
      <h2>Worksheet Information</h2>
      <p><strong>Total Sheets:</strong> {{ worksheetInfo.count }}</p>
      <ul>
        <li v-for="name in worksheetInfo.names" :key="name">{{ name }}</li>
      </ul>
    </div>

    <!-- Data Sync Section -->
    <div class="sync-section">
      <h2>Data Synchronization</h2>
      <div class="sync-buttons">
        <button @click="syncAllData" :disabled="syncing" class="btn-success">
          {{ syncing ? 'Syncing...' : 'Sync All Data' }}
        </button>
      </div>
      
      <div v-if="syncResults" class="sync-results">
        <h3>Sync Results:</h3>
        <ul>
          <li>Users: {{ syncResults.users ? '✓ Success' : '✗ Failed' }}</li>
          <li>Delivery Requests: {{ syncResults.delivery_requests ? '✓ Success' : '✗ Failed' }}</li>
          <li>Send Requests: {{ syncResults.send_requests ? '✓ Success' : '✗ Failed' }}</li>
        </ul>
      </div>
    </div>

    <!-- Worksheet Data Viewer -->
    <div class="data-viewer">
      <h2>Worksheet Data</h2>
      <select v-model="selectedWorksheet" @change="loadWorksheetData">
        <option v-for="name in worksheetInfo?.names" :key="name" :value="name">
          {{ name }}
        </option>
      </select>

      <div v-if="worksheetData.length" class="data-table">
        <table>
          <thead>
            <tr>
              <th v-for="(header, index) in worksheetData[0]" :key="index">
                {{ header }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in worksheetData.slice(1)" :key="index">
              <td v-for="(cell, cellIndex) in row" :key="cellIndex">
                {{ cell }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Export Section -->
    <div class="export-section">
      <h2>Custom Data Export</h2>
      <form @submit.prevent="handleCustomExport">
        <div class="form-group">
          <label>Worksheet Name:</label>
          <input v-model="exportForm.worksheet" type="text" required>
        </div>
        
        <div class="form-group">
          <label>Data (JSON format):</label>
          <textarea v-model="exportForm.dataJson" rows="10" required></textarea>
        </div>
        
        <button type="submit" :disabled="exporting" class="btn-primary">
          {{ exporting ? 'Exporting...' : 'Export Data' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const { getWorksheetInfo, getWorksheetData, syncAllData: syncData, exportData } = useGoogleSheets()

// Reactive data
const loading = ref(false)
const syncing = ref(false)
const exporting = ref(false)
const worksheetInfo = ref(null)
const worksheetData = ref([])
const selectedWorksheet = ref('Users')
const syncResults = ref(null)

const exportForm = ref({
  worksheet: '',
  dataJson: '[]'
})

// Methods
const refreshWorksheetInfo = async () => {
  loading.value = true
  try {
    worksheetInfo.value = await getWorksheetInfo()
    if (worksheetInfo.value.names.length > 0) {
      selectedWorksheet.value = worksheetInfo.value.names[0]
      await loadWorksheetData()
    }
  } catch (error) {
    console.error('Failed to refresh worksheet info:', error)
  } finally {
    loading.value = false
  }
}

const loadWorksheetData = async () => {
  if (!selectedWorksheet.value) return
  
  try {
    worksheetData.value = await getWorksheetData(selectedWorksheet.value)
  } catch (error) {
    console.error('Failed to load worksheet data:', error)
    worksheetData.value = []
  }
}

const syncAllData = async () => {
  syncing.value = true
  try {
    const result = await syncData()
    syncResults.value = result.results
    await refreshWorksheetInfo()
  } catch (error) {
    console.error('Failed to sync data:', error)
  } finally {
    syncing.value = false
  }
}

const handleCustomExport = async () => {
  exporting.value = true
  try {
    const data = JSON.parse(exportForm.value.dataJson)
    await exportData(exportForm.value.worksheet, data)
    
    // Reset form
    exportForm.value = { worksheet: '', dataJson: '[]' }
    
    // Refresh data
    await refreshWorksheetInfo()
  } catch (error) {
    console.error('Failed to export data:', error)
  } finally {
    exporting.value = false
  }
}

// Initialize
onMounted(() => {
  refreshWorksheetInfo()
})
</script>

<style scoped>
.google-sheets-management {
  max-width: 1200px;
  margin: 0 auto;
  padding: 20px;
}

.header {
  display: flex;
  justify-content: between;
  align-items: center;
  margin-bottom: 30px;
}

.worksheet-info, .sync-section, .data-viewer, .export-section {
  background: #f8f9fa;
  padding: 20px;
  margin-bottom: 20px;
  border-radius: 8px;
}

.data-table {
  overflow-x: auto;
  margin-top: 20px;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th, td {
  padding: 12px;
  text-align: left;
  border-bottom: 1px solid #ddd;
}

th {
  background-color: #6c757d;
  color: white;
}

.form-group {
  margin-bottom: 15px;
}

label {
  display: block;
  margin-bottom: 5px;
  font-weight: bold;
}

input, textarea, select {
  width: 100%;
  padding: 8px;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.btn-primary, .btn-success {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 16px;
}

.btn-primary {
  background-color: #007bff;
  color: white;
}

.btn-success {
  background-color: #28a745;
  color: white;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.sync-results ul {
  list-style: none;
  padding: 0;
}

.sync-results li {
  padding: 5px 0;
}
</style>
```

## Implementation Prompts

### Prompt 1: Laravel Google Sheets Service Implementation

```
I need to implement Google Sheets integration in my Laravel API application. Based on the PostLink Telegram bot implementation, create a comprehensive GoogleSheetsService class that includes:

1. **Service Class Requirements:**
   - Initialize Google Sheets client using service account credentials
   - Handle user record creation with columns: [ID, Name, Phone, City, Created_At, Telegram_Username, Telegram_ID]
   - Handle delivery request records with columns: [ID, User_Info, From_Location, To_Location, From_Date, To_Date, Size_Type, Description, Status, Created_At, Updated_At]
   - Handle send request records with same structure as delivery requests
   - Update request status functionality (close requests)
   - Batch export functionality for syncing database data
   - Error handling and logging

2. **Integration Points:**
   - Use revolution/laravel-google-sheets package
   - Service account authentication via JSON credentials file
   - Automatic record creation via model events (created, updated)
   - API controller for manual operations and data viewing

3. **Configuration:**
   - Environment variables for spreadsheet ID and credentials path
   - Service provider registration if needed
   - Proper error handling and logging

Please implement the complete service class, controller, and model integration following Laravel best practices. Include proper validation, error handling, and make it production-ready.
```

### Prompt 2: Nuxt.js Frontend Integration

```
Create a comprehensive Nuxt.js frontend integration for Google Sheets management based on the Laravel API I've implemented. I need:

1. **Composable Functions:**
   - useGoogleSheets() composable with methods for:
     - Getting worksheet information (sheet names, counts)
     - Retrieving worksheet data
     - Syncing all database data to sheets
     - Custom data export functionality
   - Proper error handling and loading states

2. **Admin Management Page:**
   - Dashboard showing worksheet information
   - Data synchronization controls with progress indicators
   - Worksheet data viewer with table display
   - Custom data export form with JSON input
   - Real-time sync status and results display

3. **Features:**
   - Responsive design for desktop and mobile
   - Loading states and progress indicators
   - Success/error message handling
   - Data table with sorting and filtering
   - Export/import functionality

4. **Technical Requirements:**
   - Use Composition API
   - Implement proper TypeScript types
   - Add proper validation for forms
   - Include comprehensive error handling
   - Make it production-ready with good UX

Create the complete implementation including the composable, admin page component, and any necessary utilities. Follow Nuxt.js 3 best practices and modern Vue.js patterns.
```

### Prompt 3: Complete Integration Setup

```
I need complete setup instructions for integrating Google Sheets functionality from my PostLink Telegram bot into a Laravel + Nuxt.js web application. Provide:

1. **Google Cloud Setup:**
   - Step-by-step Google Cloud Console configuration
   - Service account creation and key generation
   - Google Sheets API enabling
   - Proper permissions setup for sheets access

2. **Laravel Configuration:**
   - Package installation and configuration
   - Environment variables setup
   - Service account credentials file placement
   - Google Sheets service registration
   - Database migration adjustments if needed

3. **Nuxt.js Configuration:**
   - Runtime configuration setup
   - API base URL configuration
   - Authentication integration if needed
   - Environment variable management

4. **Deployment Considerations:**
   - Production environment setup
   - Security considerations for service account keys
   - Rate limiting and quota management
   - Error monitoring and logging setup

5. **Testing Strategy:**
   - Unit tests for service methods
   - Integration tests for API endpoints
   - Frontend component testing
   - End-to-end testing scenarios

Provide a complete implementation guide that allows seamless integration of the existing Python bot's Google Sheets functionality into the Laravel + Nuxt.js stack without losing any features.
```

## Configuration Summary

### Required Environment Variables

**Laravel (.env):**
```env
GOOGLE_SERVICE_ACCOUNT_JSON_LOCATION=storage/app/google-service-account.json
GOOGLE_SHEETS_SPREADSHEET_ID=your_spreadsheet_id_here
GOOGLE_SHEETS_POST_CLEAR_RANGE=A1:Z1000
GOOGLE_SHEETS_POST_RANGE=A1:Z1000
```

**Nuxt.js (.env):**
```env
NUXT_PUBLIC_API_BASE=http://localhost:8000
```

### Required Dependencies

**Laravel:**
```bash
composer require revolution/laravel-google-sheets
```

**Nuxt.js:**
```bash
npm install axios
```

This integration maintains all the functionality from the Python Telegram bot while providing a web-based interface for managing and viewing Google Sheets data through your Laravel API and Nuxt.js frontend.
