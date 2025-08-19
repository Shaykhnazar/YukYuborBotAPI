# Google Sheets Integration Workflow Documentation

## Overview

The PostLink API integrates with Google Sheets to track delivery and send requests, including response tracking and acceptance metrics. This document outlines the **updated workflow** with the new **single response system with dual acceptance**, which fixes previous issues with duplicate and wrong-order updates.

## Architecture Components

### 1. Core Service
- **GoogleSheetsService.php**: Main service handling all Google Sheets operations
- **Configuration**: Uses `revolution/laravel-google-sheets` package with service account authentication

### 2. Job Queue System
- **UpdateGoogleSheetsResponseTracking**: Handles response received tracking (columns L, M, N)
- **UpdateGoogleSheetsAcceptanceTracking**: **UPDATED** - Now handles single response with dual acceptance tracking (columns O, P, Q)

### 3. Observer Pattern
- **ResponseObserver**: Monitors Response model changes and dispatches tracking jobs
- **RequestObserver**: Monitors request creation and calls GoogleSheetsService directly

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
User creates request → RequestObserver → GoogleSheetsService.recordAdd[Send|Delivery]Request()
                                                    ↓
                                            Append row to sheet with initial data
                                                    ↓
                                            Columns L,M,N,O,P,Q set to defaults
                                            (не получен, 0, '', не принят, '', '')
```

### 2. Response Creation Flow
```
Response created → ResponseObserver.created() → UpdateGoogleSheetsResponseTracking job (3s delay)
                                                             ↓
                                                   Find target request (using request_id)
                                                             ↓
                                                   Update columns L, M, N
                                                   (получен, increment count, calculate wait time)
```

### 3. Response Acceptance Flow
```
Response status → accepted → ResponseObserver.updated() → UpdateGoogleSheetsAcceptanceTracking job (3s delay)
                                                                         ↓
                                                               For matching responses:
                                                               Update BOTH send & delivery requests
                                                                         ↓
                                                               Update columns O, P, Q
                                                               (принят, acceptance time, wait time)
```

## Response Table Structure & Logic (Updated)

### Response Table Fields
- **user_id**: Who receives/sees the response (the request owner)
- **responder_id**: Who is making the response
- **offer_type**: Type of the receiving user's request (`send` or `delivery`)
- **request_id**: The receiving user's own request ID
- **offer_id**: The offering user's request ID
- **response_type**: `manual` or `matching`
- **status**: `pending` → `responded` → `accepted` (or `rejected`) - **LEGACY COLUMN**
- **deliverer_status**: **NEW** - `pending` → `accepted`/`rejected` (deliverer's individual status)
- **sender_status**: **NEW** - `pending` → `accepted`/`rejected` (sender's individual status)
- **overall_status**: **NEW** - `pending` → `partial` → `accepted`/`rejected` (combined status)

### Target Request Identification
For Google Sheets tracking, the **target request** (which receives responses) is always:
- **Request ID**: `response.request_id`
- **Request Type**: `response.offer_type`
- **Worksheet**: `"Send requests"` if `offer_type === 'send'`, `"Deliver requests"` if `offer_type === 'delivery'`

### NEW: Single Response with Dual Acceptance Logic
For matching responses, **ONE response record** now handles both users:
1. **Target Request** (receives response): `request_id` in worksheet `offer_type`  
2. **Offering Request** (makes response): `offer_id` in opposite worksheet

**Acceptance Flow**:
- **First user accepts** → `overall_status='partial'` → Update only their request sheet
- **Second user accepts** → `overall_status='accepted'` → Update their request sheet + create chat
- **Either user rejects** → `overall_status='rejected'` → No further updates

**Benefits**: Eliminates duplicate responses, fixes wrong-order updates, cleaner database.

## Job Execution Details

### UpdateGoogleSheetsResponseTracking
**Trigger**: Response created  
**Delay**: 3 seconds  
**Queue**: gsheets  
**Logic**:
1. Find response by ID
2. Determine target request using `request_id` and `offer_type`
3. Find row in appropriate worksheet
4. Update columns L (получен), M (increment count), N (calculate wait time if first response)

### UpdateGoogleSheetsAcceptanceTracking (UPDATED)
**Trigger**: Response overall_status changes or individual user status changes  
**Delay**: 3 seconds  
**Queue**: gsheets  
**NEW Logic**:
1. Find response by ID
2. **Check for new dual acceptance columns** (deliverer_status, sender_status, overall_status)
3. **For partial acceptance**: Update only the accepting user's request sheet
4. **For full acceptance**: Update the second accepting user's request sheet  
5. **Legacy fallback**: Handle old dual response system if new columns don't exist
6. Update columns O (принят), P (acceptance time), Q (calculate wait time)

**Key Improvement**: **Sequential updates** instead of simultaneous, fixing wrong-order and duplicate issues.

### Additional Tracking in ResponseController
For matching responses, when deliverer's response is updated to 'accepted' in `handleSenderAcceptance()`, an additional `UpdateGoogleSheetsAcceptanceTracking` job is explicitly dispatched to ensure proper tracking of both responses.

## Current Implementation Status

### Working Components
✅ Request creation tracking  
✅ Response received tracking (columns L, M, N)  
✅ **NEW**: Single response with dual acceptance tracking (columns O, P, Q)  
✅ **IMPROVED**: Sequential worksheet updates (no more wrong order)  
✅ **ELIMINATED**: Duplicate response creation and updates  
✅ Race condition handling with job delays  
✅ **ENHANCED**: Clean database structure with new status columns  

### Recent Major Updates (v2.0)
- **✅ Implemented single response system** with dual acceptance columns
- **✅ Fixed wrong-order Google Sheets updates** (deliverer first, then sender)
- **✅ Eliminated duplicate response records** (50% reduction in response table size)
- **✅ Added proper sequential tracking** for both user acceptances
- **✅ Maintained backward compatibility** with legacy dual response system
- **✅ Enhanced Response model** with helper methods for user roles and status management

## Testing Checklist

- [x] Create send request → Verify appears in "Send requests" sheet
- [x] Create delivery request → Verify appears in "Deliver requests" sheet
- [x] Create matching response → Verify response tracking columns update (L, M, N)
- [x] Accept matching response → Verify acceptance columns update (O, P, Q) in both worksheets
- [x] Create manual response → Verify response tracking works
- [x] Accept manual response → Verify acceptance tracking works
- [ ] Test with multiple rapid responses → Verify no race conditions
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

## Troubleshooting

### Common Issues
1. **"Could not determine target request"**: Check Response table data integrity
2. **Row not found in worksheet**: Verify request was properly created and recorded
3. **Jobs not executing**: Check queue worker and Redis connection
4. **Wrong worksheet updates**: Verify offer_type field in Response table

### Debug Commands
```bash
# Check job queue status
php artisan queue:monitor gsheets

# Check recent jobs
php artisan horizon:status

# Manual job dispatch for testing
php artisan tinker
UpdateGoogleSheetsAcceptanceTracking::dispatch($responseId);
```
