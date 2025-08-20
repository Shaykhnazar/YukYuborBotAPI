# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PostLink API is a Laravel-based delivery and courier service platform that connects users who need to send items with those willing to deliver them. The application integrates with Telegram Web Apps for authentication and uses real-time WebSocket communication via Laravel Reverb.

## Development Commands

**Setup & Installation:**
```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

**Development Server:**
```bash
php artisan serve          # Start Laravel development server
npm run dev               # Start Vite development server (frontend assets)
```

**Testing:**
```bash
vendor/bin/phpunit        # Run all tests
vendor/bin/phpunit --testsuite=Unit     # Run unit tests only
vendor/bin/phpunit --testsuite=Feature  # Run feature tests only
```

**Code Quality:**
```bash
./vendor/bin/pint         # Laravel Pint code formatting
```

**Database:**
```bash
php artisan migrate        # Run migrations
php artisan migrate:fresh  # Fresh migration
php artisan tinker         # Laravel REPL
```

**Broadcasting/WebSockets:**
```bash
php artisan reverb:start   # Start Laravel Reverb WebSocket server
```

## Architecture Overview

### Core Domain Models

The application revolves around a request-response matching system:

- **Users**: Authenticated via Telegram Web App integration
- **SendRequests**: Items that users want to send/deliver
- **DeliveryRequests**: Users offering delivery services between locations
- **Responses**: Connections between send and delivery requests
- **Chat/ChatMessage**: Real-time messaging between matched users
- **Reviews**: User feedback system

### Authentication System

Uses a dual-middleware approach for Telegram Web App authentication:
- **Production**: `tg.init` + `auth:tgwebapp` - Full Telegram authentication
- **Development**: `tg.init.dev` - Development-friendly authentication bypass

Authentication is handled by:
- `TelegramInitUser` / `TelegramInitUserDev` middleware
- `smskin/laravel-tgwebapp-auth` package for Telegram Web App validation
- `TelegramUserService` for user management

### Real-time Features

WebSocket implementation using Laravel Reverb:
- **Private Channels**: `private-chat.{chatId}` for chat messages
- **Presence Channels**: `presence-chat.{chatId}` for typing indicators and online status
- **Broadcasting Events**: `MessageSent`, `MessageRead`, `UserTyping`
- **Custom Authorization**: Complex auth logic in `routes/api.php:112-279` for chat access control

### Request Matching System

The core business logic involves matching:
1. **SendRequests** (users wanting to send items)
2. **DeliveryRequests** (users offering delivery services)
3. **Responses** table acts as the relationship bridge
4. **Chat** creation upon successful matches

### Database Structure

Key relationships:
- `User` → `SendRequest` / `DeliveryRequest` (one-to-many)
- `SendRequest` ↔ `DeliveryRequest` via `Response` table
- `Response` → `Chat` (accepted responses create chats)
- Complex response relationships in models handle both directions of matching

## API Architecture

### Route Organization

- **Main API routes**: Protected by Telegram auth middleware
- **Chat routes**: Nested under `/api/chat` prefix
- **Response routes**: Nested under `/api/responses` prefix  
- **Development routes**: `/api/dev/*` for development tools
- **Health check**: `/api/health` for deployment monitoring

### Request/Response Flow

#### Overview
The application supports two distinct response types with different acceptance flows:

1. **Matching Responses** (Automatic) - System-generated matches
2. **Manual Responses** (User-initiated) - Direct user responses to requests

#### Matching Response Flow (Dual Acceptance)

**System Behavior:**
1. Users create SendRequests or DeliveryRequests
2. Background matching system finds compatible requests
3. System automatically creates Responses between matches
4. **Deliverers ALWAYS receive first notification** (regardless of creation order)

**User Flow:**
1. **Step 1 - Deliverer Action**: Deliverer receives match notification and can accept/reject
2. **Step 2 - Sender Action**: If deliverer accepts, sender gets notified and can accept/reject  
3. **Step 3 - Chat Creation**: Only when BOTH users accept, chat is created automatically

**Business Logic:**
- **Deliverers**: Service providers who decide first if they want the job
- **Senders**: Customers who choose from interested deliverers only
- **Dual acceptance ensures**: Both parties agree before partnership begins

```php
// Matching Response Structure
offer_type: 'send'        // Send requests are ALWAYS offered to deliverers
user_id: deliverer        // Deliverer receives notification
responder_id: sender      // Sender owns the send request
response_type: 'matching' // System-generated match
overall_status: 'pending' → 'partial' → 'accepted'
deliverer_status: 'pending' → 'accepted'  
sender_status: 'pending' → 'accepted'
```

#### Manual Response Flow (Single Acceptance)

**User Behavior:**
1. User A manually responds to User B's request
2. **Only User B (request owner) can accept/reject**
3. **User A (response sender) can only wait or cancel**
4. **Single acceptance**: If User B accepts, chat opens immediately

**No Dual Acceptance:**
- Manual responses bypass the dual acceptance system
- Only the request owner has decision power
- Response sender cannot take action (except cancel)

```php
// Manual Response Structure  
offer_type: 'send' | 'delivery'  // Based on request type being responded to
user_id: request_owner           // Request owner can accept/reject
responder_id: response_sender    // Response sender waits
response_type: 'manual'          // User-initiated response
overall_status: 'pending' → 'accepted' (single step)
can_act_on: true (request owner only)
```

#### Key Differences Summary

| Aspect | Matching Response | Manual Response |
|--------|------------------|-----------------|
| **Initiation** | System automatic | User manual |
| **Notification** | Deliverer first | Request owner only |
| **Acceptance** | Dual (both users) | Single (request owner) |
| **Chat Creation** | After both accept | After single acceptance |
| **User Actions** | Both can act | Only request owner acts |
| **Business Model** | B2B marketplace | Direct service request |

#### Response Status Flow

**Matching Response Statuses:**
- `pending` → `partial` (first user accepted) → `accepted` (both accepted)
- `pending` → `rejected` (either user rejected)

**Manual Response Statuses:**  
- `pending` → `accepted` (request owner accepted)
- `pending` → `rejected` (request owner rejected)

### Key Controllers

- `UserController` / `UserRequestController`: User and request management
- `ChatController`: WebSocket-powered real-time messaging
- `ResponseController`: Request matching and response handling
- `SendRequestController` / `DeliveryController`: Request lifecycle management

## Environment-Specific Behavior

The application adapts behavior based on `app()->environment()`:
- **Development/Local**: Uses relaxed authentication, test user endpoints
- **Production**: Full Telegram Web App authentication, stricter security

## External Services

- **Telegram Bot API**: User authentication and notifications via `TelegramNotificationService`
- **Place API**: Location services via `PlaceApi` service
- **PostgreSQL**: Primary database
- **Redis**: Queue management and caching
- **Laravel Reverb**: WebSocket server for real-time features

## Testing Configuration

- Uses SQLite in-memory database for testing
- PHPUnit configured with separate Unit/Feature test suites
- Test environment variables configured in `phpunit.xml`
- Factory classes available for all major models

## Key Business Logic

### Matching System Business Rules

The `Matcher` service handles the core algorithm for matching send requests with delivery requests based on location compatibility and user preferences.

## Technical Implementation Details

### **Response Action Logic**

#### Manual Response Implementation
```php
// app/Models/Response.php - canUserTakeAction()
public function canUserTakeAction($userId): bool
{
    // For manual responses, only the request owner can act
    if ($this->response_type === self::TYPE_MANUAL) {
        return $this->user_id === $userId && $this->overall_status === self::OVERALL_STATUS_PENDING;
    }
    
    // For matching responses, use dual acceptance system
    $role = $this->getUserRole($userId);
    if ($role === 'deliverer') {
        return $this->deliverer_status === self::DUAL_STATUS_PENDING;
    } elseif ($role === 'sender') {
        return $this->sender_status === self::DUAL_STATUS_PENDING;
    }
    return false;
}
```

#### Frontend Action Button Logic
```javascript
// ResponsesList.vue - Template conditions
// Non-manual responses (matching)
v-if="response.status === 'pending' && response.can_act_on && response.response_type !== 'manual'"

// Manual responses only
v-else-if="response.response_type === 'manual' && response.status === 'pending'"
  // Request owner sees accept/reject buttons
  v-if="response.can_act_on"
  // Response sender sees waiting message only  
  v-else
```

### **Google Sheets Integration Flow**

#### Matching Responses (Dual Tracking)
```php
// Google Sheets Flow for Matching Responses:
// 1. Response Creation → updateRequestResponseReceived() (columns L, M, N, O)
// 2. Deliverer Accepts → NO ACTION (already marked as received)  
// 3. Sender Accepts → updateRequestResponseAccepted() (columns P, Q, R)

// UpdateGoogleSheetsAcceptanceTracking.php
if ($userRole === 'deliverer') {
    // Deliverer acceptance → DO NOTHING (received already set at creation)
    Log::info('Deliverer accepted - skipping update');
} elseif ($userRole === 'sender') {
    // Sender acceptance → mark as "accepted"
    $googleSheetsService->updateRequestResponseAccepted();
}
```

#### Manual Responses (Single Tracking)
- Only track final acceptance when request owner accepts
- No intermediate "received" status needed

### **CRITICAL: Matching Notification Flow**

**Core Principle: In matching mode, deliverers ALWAYS receive the first notification, regardless of who created their request first. Only after deliverer acceptance should sender be notified.**

#### **Correct Flow for Both Scenarios:**

**Scenario 1: Sender Creates First → Deliverer Creates Second**
```php
// When deliverer creates request, they find existing send requests
$this->creationService->createMatchingResponse(
    $delivery->user_id,        // ✅ DELIVERER receives notification  
    $sendRequest->user_id,     // sender offered their request
    'send',                    // send request offered to deliverer
    $delivery->id,             // deliverer's request receives
    $sendRequest->id          // sender's request offered
);
// Result: DELIVERER gets notified about send opportunity
```

**Scenario 2: Deliverer Creates First → Sender Creates Second**
```php
// When sender creates request, they find existing delivery requests
$this->creationService->createMatchingResponse(
    $deliveryRequest->user_id, // ✅ DELIVERER receives notification
    $send->user_id,           // sender offered their request  
    'send',                   // send request offered to deliverer
    $deliveryRequest->id,     // deliverer's request receives
    $send->id                 // sender's request offered
);
// Result: DELIVERER gets notified about send opportunity
```

#### **Complete Business Flow:**

1. **Request Creation** → Automatic matching occurs
2. **ALWAYS: Deliverer Gets First Notification** 📱
   - "New send request matches your delivery route!"
   - Deliverer can accept/reject via Telegram bot
3. **If Deliverer Accepts** → `deliverer_status: 'accepted'`, `overall_status: 'partial'`
4. **THEN: Sender Gets Notification** 📱 (triggered by ResponseObserver)
   - "A deliverer accepted your request! Confirm partnership?"
   - Sender can accept/reject
5. **If Both Accept** → `overall_status: 'accepted'`, Chat created automatically
6. **Google Sheets Integration** → Both acceptance events tracked for analytics

#### **Response Structure Logic:**

**ALL matching responses use `offer_type: 'send'` because:**
- ✅ Send requests are ALWAYS being offered TO deliverers
- ✅ Deliverers evaluate send opportunities (service providers)
- ✅ Senders' requests are the "product" being offered
- ✅ Consistent database structure and URL formats
- ✅ Response URLs: `send_{sendId}_delivery_{deliveryId}`

#### **Business Benefits:**

- **Deliverers as Service Providers**: They decide first if they want the job
- **Senders as Customers**: They choose from interested deliverers only
- **Efficient Filtering**: Senders only see serious offers (pre-accepted by deliverers)
- **Consistent UX**: Same flow regardless of creation timing
- **Proper Incentives**: Deliverers commit before senders evaluate

DTOs (Data Transfer Objects) are used for request validation and data structuring:
- `CreateSendRequestDTO`
- `CreateDeliveryRequestDTO`  
- `CreateRequestDTO` (for reviews)

## Frontend Integration

- Vite for asset building
- Tailwind CSS integration
- Axios for HTTP client
- Laravel Vite Plugin for seamless Laravel integration


## Importand notice!

We have not used to laravel migrations in this project instead we use raw PostgreSQL query,
so if you want to know about the structure ask me about it

- Response logic user flow and creating logic for matching and manual types