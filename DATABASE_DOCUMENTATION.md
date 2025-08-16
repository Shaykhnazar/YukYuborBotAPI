# PostLink API Database Documentation

## Overview

This document provides comprehensive documentation for all database tables in the PostLink API system. PostLink is a delivery and courier service platform that connects users who need to send items with those willing to deliver them.

## Table of Contents

- [Core User Tables](#core-user-tables)
- [Request Tables](#request-tables)
- [Communication Tables](#communication-tables)
- [Matching & Response Tables](#matching--response-tables)
- [Location & Route Tables](#location--route-tables)
- [Support Tables](#support-tables)
- [Laravel System Tables](#laravel-system-tables)
- [Relationships Overview](#relationships-overview)

---

## Core User Tables

### users
**Purpose**: Core user accounts in the system

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Unique user identifier |
| `phone` | varchar(255) | User's phone number (unique, nullable) |
| `name` | varchar(255) | User's display name |
| `city` | varchar(255) | User's city (nullable) |
| `links_balance` | integer | Available links for creating requests (default: 3) |
| `created_at` | timestamp | Account creation time |
| `updated_at` | timestamp | Last account update time |

**Business Logic**: Each user starts with 3 links. Links are consumed when creating requests and are used to prevent spam.

### telegram_users
**Purpose**: Links user accounts with Telegram authentication data

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Unique record identifier |
| `telegram` | bigint | Telegram user ID (unique) |
| `username` | varchar(255) | Telegram username (nullable) |
| `user_id` | bigint (FK) | Reference to users table (nullable) |
| `image` | varchar(255) | User's profile image URL (nullable) |

**Business Logic**: Users authenticate via Telegram Web App. One telegram user can be linked to one system user.

---

## Request Tables

### send_requests
**Purpose**: Requests from users who want to send items/packages

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Unique request identifier |
| `user_id` | bigint (FK) | User who created the request |
| `from_date` | timestamp | Earliest acceptable pickup date |
| `to_date` | timestamp | Latest acceptable delivery date |
| `size_type` | varchar(255) | Package size category (nullable) |
| `description` | varchar(255) | Item description (nullable) |
| `status` | varchar(255) | Request status (open/has_responses/matched/closed) |
| `price` | integer | Offered payment amount in cents (nullable) |
| `currency` | varchar(255) | Payment currency (nullable) |
| `matched_delivery_id` | integer | ID of matched delivery request (nullable) |
| `from_location_id` | bigint (FK) | Pickup location |
| `to_location_id` | bigint (FK) | Delivery destination |
| `created_at` | timestamp | Request creation time |
| `updated_at` | timestamp | Last request update time |

**Status Flow**: 
- `open` → `has_responses` → `matched` → `closed`

### delivery_requests
**Purpose**: Requests from users offering delivery services

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Unique request identifier |
| `user_id` | bigint (FK) | User who created the request |
| `from_date` | timestamp | Travel start date |
| `to_date` | timestamp | Travel end date |
| `size_type` | varchar(255) | Maximum package size they can carry (nullable) |
| `description` | varchar(255) | Travel description/notes (nullable) |
| `status` | varchar(255) | Request status (open/has_responses/matched/closed) |
| `price` | integer | Requested payment amount in cents (nullable) |
| `currency` | varchar(255) | Payment currency (nullable) |
| `matched_send_id` | integer | ID of matched send request (nullable) |
| `from_location_id` | bigint (FK) | Travel origin |
| `to_location_id` | bigint (FK) | Travel destination |
| `created_at` | timestamp | Request creation time |
| `updated_at` | timestamp | Last request update time |

**Status Flow**: 
- `open` → `has_responses` → `matched` → `closed`

---

## Communication Tables

### chats
**Purpose**: Chat sessions between matched users

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Unique chat identifier |
| `send_request_id` | bigint (FK) | Associated send request (nullable) |
| `delivery_request_id` | bigint (FK) | Associated delivery request (nullable) |
| `sender_id` | bigint (FK) | User who created the send request |
| `receiver_id` | bigint (FK) | User who will deliver the item |
| `status` | varchar(50) | Chat status (active/inactive/closed) |
| `created_at` | timestamp | Chat creation time |
| `updated_at` | timestamp | Last chat update time |

**Business Logic**: Chats are created when users accept responses. They link the sender and deliverer for communication.

### chat_messages
**Purpose**: Individual messages within chats

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Unique message identifier |
| `chat_id` | bigint (FK) | Parent chat |
| `sender_id` | bigint (FK) | User who sent the message |
| `message` | text | Message content |
| `message_type` | varchar(20) | Message type (text/image/file) |
| `is_read` | boolean | Whether message has been read |
| `created_at` | timestamp | Message creation time |
| `updated_at` | timestamp | Message update time |

**Business Logic**: Real-time messaging between users using Laravel Reverb WebSockets.

---

## Matching & Response Tables

### responses
**Purpose**: Core table linking requests and managing the response lifecycle

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Unique response identifier |
| `user_id` | bigint (FK) | **User who RECEIVES this response** (request owner) |
| `responder_id` | bigint (FK) | **User who CREATES this response** (person responding) |
| `offer_type` | varchar(20) | Type of receiving request: "send" or "delivery" |
| `request_id` | integer | **ID of the receiving user's own request** |
| `offer_id` | integer | **ID of the responding user's request** |
| `status` | varchar(20) | Response status (pending/responded/waiting/accepted/rejected) |
| `chat_id` | bigint (FK) | Created chat when accepted (nullable) |
| `message` | text | Custom message from responder (manual responses only) |
| `response_type` | varchar(20) | "matching" (system match) or "manual" (user response) |
| `currency` | varchar(10) | Proposed payment currency (manual responses) |
| `amount` | integer | Proposed payment amount (manual responses) |
| `created_at` | timestamp | Response creation time |
| `updated_at` | timestamp | Response update time |

**Complex Business Logic**:

#### Field Relationships:
- **Target Request** (receives response): Found using `request_id` + `request_type`
- **Offering Request** (makes response): Found using `offer_id` + opposite type

#### Response Types:

**Matching Responses** (Automatic system matches):
- Created when system finds compatible requests
- `message`, `currency`, `amount` are NULL
- Example: Deliverer's request automatically matched with sender's request

**Manual Responses** (User-initiated):
- User manually responds to a request with custom message/price
- `request_id` = 0 (not used)
- `offer_id` = target request ID
- Contains custom `message`, optional `currency`/`amount`

#### Status Flow:
```
Matching: pending → responded → waiting → accepted/rejected
Manual:   pending → accepted/rejected
```

### reviews
**Purpose**: User feedback and rating system

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Unique review identifier |
| `user_id` | bigint (FK) | User who wrote the review |
| `text` | text | Review content |
| `rating` | smallint | Numeric rating |
| `owner_id` | bigint (FK) | User being reviewed |
| `request_id` | integer | Associated request ID (nullable) |
| `request_type` | varchar(20) | Associated request type (nullable) |
| `created_at` | timestamp | Review creation time |
| `updated_at` | timestamp | Review update time |

**Business Logic**: Users can review each other after completed deliveries. Prevents duplicate reviews per request.

---

## Location & Route Tables

### locations
**Purpose**: Hierarchical location system (countries → cities)

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Unique location identifier |
| `name` | varchar(255) | Location name |
| `parent_id` | bigint (FK) | Parent location (nullable, self-reference) |
| `type` | varchar(50) | Location type (country/city) |
| `country_code` | varchar(3) | ISO country code (nullable) |
| `is_active` | boolean | Whether location is available for selection |
| `created_at` | timestamp | Location creation time |
| `updated_at` | timestamp | Location update time |

**Business Logic**: Hierarchical structure where cities have countries as parents. Used for matching requests by route.

### routes
**Purpose**: Predefined popular routes between locations

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Unique route identifier |
| `from_location_id` | bigint (FK) | Starting location |
| `to_location_id` | bigint (FK) | Destination location |
| `is_active` | boolean | Whether route is available |
| `priority` | integer | Route priority for display ordering |
| `description` | text | Route description (nullable) |
| `created_at` | timestamp | Route creation time |
| `updated_at` | timestamp | Route update time |

**Business Logic**: Popular routes can be highlighted in the UI. Prevents duplicate routes in same direction.

### suggested_routes
**Purpose**: User-suggested routes pending admin approval

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Unique suggestion identifier |
| `from_location` | varchar(255) | Suggested origin location name |
| `to_location` | varchar(255) | Suggested destination location name |
| `user_id` | bigint (FK) | User who made the suggestion |
| `status` | varchar(50) | Suggestion status (pending/approved/rejected) |
| `reviewed_at` | timestamp | When admin reviewed (nullable) |
| `reviewed_by` | bigint (FK) | Admin who reviewed (nullable) |
| `notes` | text | Admin notes (nullable) |
| `created_at` | timestamp | Suggestion creation time |
| `updated_at` | timestamp | Suggestion update time |

**Business Logic**: Users can suggest new routes. Admins review and can convert to official routes.

---

## Support Tables

*Note: Support tables structure is defined in migration but specific schema not provided in migrations reviewed.*

---

## Laravel System Tables

### password_reset_tokens
**Purpose**: Password reset functionality (Laravel default)

### failed_jobs
**Purpose**: Failed queue job tracking (Laravel default)

### personal_access_tokens
**Purpose**: API token authentication (Laravel Sanctum)

---

## Relationships Overview

### User Relationships
- `users` ↔ `telegram_users` (1:1)
- `users` → `send_requests` (1:many)
- `users` → `delivery_requests` (1:many)
- `users` → `responses` (1:many as user_id)
- `users` → `responses` (1:many as responder_id)
- `users` → `chats` (1:many as sender_id)
- `users` → `chats` (1:many as receiver_id)
- `users` → `chat_messages` (1:many)
- `users` → `reviews` (1:many as user_id)
- `users` → `reviews` (1:many as owner_id)

### Request Relationships
- `send_requests` → `locations` (many:1 for from/to)
- `delivery_requests` → `locations` (many:1 for from/to)
- `send_requests` ↔ `delivery_requests` (via matched_delivery_id/matched_send_id)
- `requests` → `responses` (1:many)
- `requests` → `chats` (1:1 when matched)

### Communication Relationships
- `chats` → `chat_messages` (1:many)
- `chats` ↔ `responses` (1:1 when accepted)

### Location Relationships
- `locations` → `locations` (parent-child hierarchy)
- `locations` → `routes` (many:many via from/to)
- `routes` ↔ `suggested_routes` (conceptual)

## Key Business Rules

1. **Link System**: Users have limited links to prevent spam
2. **Matching Algorithm**: System automatically finds compatible requests
3. **Two-Step Acceptance**: Matching responses require both deliverer and sender acceptance
4. **Chat Creation**: Chats created only after full acceptance
5. **Status Tracking**: All entities have comprehensive status tracking
6. **Geographic Matching**: Requests matched by compatible routes and dates
7. **Review System**: Mutual reviews after completed deliveries
8. **Telegram Integration**: Authentication and notifications via Telegram

## Database Indexes

The system includes comprehensive indexing for:
- User lookups and status filtering
- Geographic route matching
- Date range queries  
- Response status and type filtering
- Chat message ordering
- Location hierarchy traversal

This ensures optimal performance for the core matching algorithms and user interactions.
