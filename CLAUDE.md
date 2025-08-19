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

1. Users create SendRequests or DeliveryRequests
2. Matching system creates Responses between compatible requests
3. Users can accept/reject responses
4. Accepted responses create Chat instances
5. Real-time messaging via WebSocket channels

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