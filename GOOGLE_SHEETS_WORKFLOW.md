# Google Sheets Integration Workflow Documentation

## Overview

The PostLink API integrates with Google Sheets to track delivery and send requests, including response tracking and acceptance metrics. This document outlines the complete workflow and identifies current issues with the response acceptance/decline tracking.

## Architecture Components

### 1. Core Service
- **GoogleSheetsService.php**: Main service handling all Google Sheets operations
- **Configuration**: Uses `revolution/laravel-google-sheets` package with service account authentication

### 2. Job Queue System
- **UpdateGoogleSheetsResponseTracking**: Handles response received tracking
- **UpdateGoogleSheetsAcceptanceTracking**: Handles response acceptance tracking
- **RecordSendRequestToGoogleSheets**: Records new send requests
- **RecordDeliveryRequestToGoogleSheets**: Records new delivery requests
- **RecordUserToGoogleSheets**: Records new users

### 3. Observer Pattern
- **ResponseObserver**: Monitors Response model changes and dispatches jobs

## Google Sheets Structure

### Worksheets
1. **"Users"** - User information
2. **"Send requests"** - Send request tracking
3. **"Deliver requests"** - Delivery request tracking

### Column Structure (Requests Sheets)
| Column | Field | Description |
|--------|-------|-------------|
| A | ID | Request ID (Primary Key) |
| B | User_Info | User ID + Name |
| C | From_Location | Origin location |
| D | To_Location | Destination location |
| E | From_Date | Start date |
| F | To_Date | End date |
| G | Size_Type | Package size |
| H | Description | Request description |
| I | Status | Request status (open/matched/closed) |
| J | Created_At | Creation timestamp |
| K | Updated_At | Last update timestamp |
| L | Ответ получен | Response received status |
| M | Количество ответов | Response count |
| N | Время ожидания первого ответа | First response wait time |
| O | Ответ принят | Response accepted status |
| P | Время принятия ответа | Response acceptance time |
| Q | Время ожидания принятия | Acceptance wait time |

## Current Workflow

### 1. Request Creation Flow
```
User creates request → Model Observer → Job dispatched → GoogleSheetsService
                                                      ↓
                                              Append row to sheet
                                                      ↓
                                              Cache row position
                                                      ↓
                                              Set creation lock
```

### 2. Response Creation Flow (CURRENTLY WORKING)
```
Response created → ResponseObserver.created() → UpdateGoogleSheetsResponseTracking job
                                                              ↓
                                                    Find request row in sheet
                                                              ↓
                                                    Update columns L, M, N
                                                    (Response received, count, wait time)
```

### 3. Response Acceptance Flow (ISSUE HERE)
```
Response accepted → ResponseObserver.updated() → UpdateGoogleSheetsAcceptanceTracking job
                                                                ↓
                                                      Find request row in sheet
                                                                ↓
                                                      Update columns O, P, Q
                                                      (Acceptance status, time, wait time)
```

## Current Issues Analysis

### Problem: Row Finding Failures

#### Root Cause
The `findRowPosition()` method fails to locate recently created request rows in Google Sheets, causing acceptance tracking updates to fail.

#### Technical Details
1. **Race Condition**: Response acceptance jobs run before request rows are fully cached
2. **Search Logic Issues**: Complex batch search algorithm sometimes misses rows
3. **Cache Misses**: Row position cache frequently missing for new requests
4. **API Limitations**: Google Sheets API has eventual consistency issues

#### Evidence from Logs
```
Request ID 109 not found in Deliver requests
Could not find row position for request after exhaustive search
```

### Current Mitigation Strategies

#### 1. Job Delays
- **Response tracking**: 10-second delay
- **Acceptance tracking**: 10-second delay
- **Purpose**: Give time for request rows to be added and cached

#### 2. Cache Coordination
- **Creation locks**: Prevent concurrent access during row creation
- **Row position caching**: Cache row positions indefinitely
- **Wait logic**: Jobs wait for creation locks before searching

#### 3. Retry Logic
- **Multiple search attempts**: Up to 3 retries with increasing delays
- **Fallback search**: Full range search if batch search fails
- **Graceful failures**: Jobs return success even if row not found

## Identified Workflow Issues

### Issue 1: Wrong Request Type Mapping
**Problem**: The acceptance tracking logic might be looking in the wrong worksheet.

**Current Logic**:
```php
// In UpdateGoogleSheetsAcceptanceTracking job
$requestType = ($targetRequest instanceof \App\Models\SendRequest) ? 'send' : 'delivery';
$googleSheetsService->updateRequestResponseAccepted($requestType, $targetRequest->id);
```

**Potential Issue**: For matching responses, we might need to update BOTH worksheets, not just one.

### Issue 2: Complex Response Relationship Logic
**Problem**: The job tries to determine which request received the response using complex logic.

**Current Logic**:
```php
// For matching responses, the logic is more complex
return $response->request_type === 'send' ?
    \App\Models\DeliveryRequest::find($response->request_id)
    : \App\Models\SendRequest::find($response->request_id);
```

**Issue**: This might not correctly identify which request should be updated in Google Sheets.

### Issue 3: Misaligned Tracking Expectations
**Problem**: We might be tracking the wrong entity's status.

**Question**: When a response is accepted, which request should be updated?
- The request that received the response?
- The request that made the response?
- Both requests?

## Proposed Solutions

### Solution 1: Simplify Row Finding
Replace complex search with direct sheet scanning:
```php
// Get all data in one call and search in memory
$allData = $sheet->range('A:A')->get();
// Search through array instead of multiple API calls
```

### Solution 2: Dual Worksheet Updates
For matching responses, update both related requests:
```php
// Update both send and delivery request sheets when response is accepted
if ($response->response_type === 'matching') {
    $this->updateSendRequestSheet($sendRequestId);
    $this->updateDeliveryRequestSheet($deliveryRequestId);
}
```

### Solution 3: Event-Driven Architecture
Instead of observers, use Laravel events:
```php
// Dispatch events with clear intent
ResponseAccepted::dispatch($response);
ResponseDeclined::dispatch($response);
```

### Solution 4: Background Synchronization
Implement a periodic sync job that ensures all data is correctly reflected:
```php
// Run every hour to sync any missed updates
SyncGoogleSheetsData::dispatch()->everyHour();
```

## Recommended Next Steps

1. **Audit Current Data**: Check Google Sheets to see what data is actually missing
2. **Simplify Search Logic**: Replace complex search with simple full-range scan
3. **Clarify Business Logic**: Determine exactly which requests should be updated when
4. **Add Monitoring**: Implement better logging and monitoring for failed updates
5. **Consider Alternative Approaches**: Evaluate if Google Sheets is the right tool for this use case

## Testing Checklist

- [ ] Create send request → Verify appears in "Send requests" sheet
- [ ] Create delivery request → Verify appears in "Deliver requests" sheet  
- [ ] Create response → Verify response tracking columns update
- [ ] Accept response → Verify acceptance columns update
- [ ] Decline response → Verify status doesn't change inappropriately
- [ ] Test with multiple rapid requests → Verify no race conditions
- [ ] Check cache behavior → Verify row positions are cached correctly
- [ ] Monitor job queue → Verify no failed jobs accumulating

## Configuration Requirements

### Environment Variables
```env
GOOGLE_SERVICE_ENABLED=true
GOOGLE_SHEETS_SPREADSHEET_ID=your_spreadsheet_id
GOOGLE_SERVICE_FILE=path/to/service-account.json
```

### Queue Configuration
```bash
# Ensure queue worker is running for gsheets queue
php artisan queue:work --queue=gsheets,default
```