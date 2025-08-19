# 🏗️ PostLink API - Refactored Architecture Guide

## 📋 Overview

This document explains the new project structure after applying **Repository Pattern**, **Service Layer Architecture**, and **SOLID Principles** to simplify and improve the PostLink API codebase.

## 🎯 Architecture Philosophy

The refactored architecture follows these principles:
- **Repository Pattern** - Abstracted data access layer
- **Service Layer** - Business logic separation
- **SOLID Principles** - Clean, maintainable code
- **Background Processing** - Async operations for better performance
- **Type Safety** - Enums instead of magic strings

---

## 📁 Project Structure

### **Core Architecture Layers**

```
app/
├── Http/Controllers/           # HTTP Request/Response handling
├── Services/                   # Business logic layer
├── Repositories/               # Data access layer
├── Enums/                     # Type-safe constants
├── Jobs/                      # Background processing
└── Models/                    # Domain entities
```

---

## 🔧 Controllers Layer - HTTP Concerns Only

### **ResponseController**
**Purpose:** Handle HTTP requests for response operations
**Simplified:** Uses service layer for all business logic

```php
// OLD: 1043 lines with mixed concerns
// NEW: Clean, focused controller

class ResponseController extends Controller
{
    public function __construct(
        protected TelegramUserService $tgService,
        protected ResponseQueryService $queryService,
        protected ResponseFormatterService $formatterService,
        protected ResponseActionService $actionService,
        protected NotificationService $notificationService,
        // ... repositories
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);
        $responses = $this->queryService->getUserResponses($user);
        $formattedResponses = [];
        
        foreach ($responses as $response) {
            if ($this->queryService->canUserSeeResponse($response, $user->id)) {
                $formatted = $this->formatterService->formatResponse($response, $user);
                if ($formatted) $formattedResponses[] = $formatted;
            }
        }
        return response()->json($formattedResponses);
    }
}
```

**Responsibilities:**
- ✅ Handle HTTP requests/responses
- ✅ User authentication via TelegramUserService
- ✅ Delegate business logic to services
- ✅ Return JSON responses

**Dependencies:**
- `ResponseQueryService` - Data retrieval
- `ResponseFormatterService` - Data formatting
- `ResponseActionService` - Business actions
- `NotificationService` - Notifications
- Repository interfaces for direct data access

---

### **UserRequestController**
**Massive Simplification:** 395 lines → 85 lines (78% reduction!)

```php
// OLD: Complex 395-line controller with mixed concerns
// NEW: Clean, simple controller

class UserRequestController extends Controller
{
    public function __construct(
        protected TelegramUserService $tgService,
        protected UserRequestService $userRequestService
    ) {}

    public function index(ParcelRequest $request)
    {
        $user = $this->tgService->getUserByTelegramId($request);
        $filters = $request->getFilters();
        $requests = $this->userRequestService->getUserRequests($user, $filters);
        return IndexRequestResource::collection($requests);
    }
}
```

**Key Improvements:**
- ✅ **78% less code** - Much easier to understand
- ✅ **Single responsibility** - Only HTTP concerns
- ✅ **Easy testing** - Clear dependencies
- ✅ **Better error handling** - Consistent responses

---

### **SendRequestController & DeliveryController**
**Purpose:** Handle request creation, deletion, and lifecycle

```php
class SendRequestController extends Controller
{
    public function __construct(
        protected TelegramUserService $userService,
        protected RequestService $requestService,
        protected SendRequestRepositoryInterface $sendRequestRepository
    ) {}

    public function create(CreateSendRequest $request)
    {
        $user = $this->userService->getUserByTelegramId($request);
        $this->requestService->checkActiveRequestsLimit($user);
        
        $sendRequest = $this->sendRequestRepository->create([
            // ... request data
            'status' => RequestStatus::OPEN->value,
        ]);

        // Background processing for matching
        MatchRequestsJob::dispatch('send', $sendRequest->id);
        return response()->json($sendRequest);
    }
}
```

**Key Features:**
- ✅ **Repository pattern** for data access
- ✅ **Background job dispatch** for async matching
- ✅ **Type-safe enums** for status management
- ✅ **Service layer** for business logic

---

## 🛠️ Services Layer - Business Logic

### **Response Services**

#### **ResponseQueryService**
**Purpose:** Handle all response data retrieval logic

```php
class ResponseQueryService
{
    public function __construct(
        private ResponseRepositoryInterface $responseRepository
    ) {}

    public function getUserResponses(User $user): Collection
    {
        return $this->responseRepository->findByUserWithRelations($user, [
            'user.telegramUser',
            'responder.telegramUser', 
            'chat'
        ]);
    }

    public function canUserSeeResponse($response, int $userId): bool
    {
        // Business logic for visibility rules
        // ...
    }
}
```

#### **ResponseActionService**
**Purpose:** Handle response business actions (accept, reject, etc.)

```php
class ResponseActionService
{
    public function __construct(
        private Matcher $matcher,
        private NotificationService $notificationService,
        private ResponseRepositoryInterface $responseRepository,
        // ... other repositories
    ) {}

    public function acceptManualResponse(User $user, int $responseId): array
    {
        // Business logic for accepting responses
        // Creates chats, updates statuses, sends notifications
        // ...
    }
}
```

#### **ResponseFormatterService**
**Purpose:** Format raw data for API responses

```php
class ResponseFormatterService
{
    public function formatResponse($response, User $currentUser): ?array
    {
        // Transform database models into API format
        // Handle different response types
        // Format user data, etc.
        // ...
    }
}
```

---

### **User Request Services**

#### **UserRequestService**
**Purpose:** High-level user request operations

```php
class UserRequestService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private UserRequestQueryService $queryService,
        private UserRequestFormatterService $formatterService
    ) {}

    public function getUserRequests(User $user, array $filters = []): Collection
    {
        $requests = $this->queryService->getUserRequestsWithResponses($user, $filters);
        return $this->formatterService->formatRequestCollection($requests);
    }
}
```

---

### **Matching Services**

#### **Matcher (Refactored)**
**Purpose:** Coordinate request matching process

```php
class Matcher
{
    public function __construct(
        protected TelegramNotificationService $telegramService,
        protected RequestMatchingService $matchingService,
        protected ResponseCreationService $creationService,
        protected ResponseStatusService $statusService
    ) {}

    public function matchSendRequest(SendRequest $sendRequest): void
    {
        $matchedDeliveries = $this->matchingService->findMatchingDeliveryRequests($sendRequest);

        foreach ($matchedDeliveries as $delivery) {
            $this->creationService->createMatchingResponse(
                $delivery->user_id,        
                $sendRequest->user_id,     
                'send',                    
                $delivery->id,             
                $sendRequest->id          
            );

            $this->notifyDeliveryUserAboutNewSend($sendRequest, $delivery);
        }
    }
}
```

**Key Improvements:**
- ✅ **Single Responsibility** - Only coordinates matching
- ✅ **Dependency Injection** - All dependencies injected
- ✅ **Focused Services** - Each service has one job
- ✅ **Easy Testing** - Mock individual services

---

## 🗄️ Repository Layer - Data Access

### **Repository Pattern Implementation**

#### **Base Repository Interface**
```php
interface BaseRepositoryInterface
{
    public function find(int $id): ?Model;
    public function create(array $data): Model;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    // ... common operations
}
```

#### **Model-Specific Repositories**

**SendRequestRepository:**
```php
class SendRequestRepository extends BaseRepository implements SendRequestRepositoryInterface
{
    public function findActiveByUser(User $user): Collection
    {
        return $this->model->where('user_id', $user->id)
            ->whereNotIn('status', [RequestStatus::CLOSED->value])
            ->get();
    }

    public function findMatchingForDelivery(DeliveryRequest $deliveryRequest): Collection
    {
        return $this->model
            ->where('from_location_id', $deliveryRequest->from_location_id)
            ->where('to_location_id', $deliveryRequest->to_location_id)
            // ... complex matching logic
            ->get();
    }
}
```

**Benefits:**
- ✅ **Abstracted Data Access** - No direct model queries in controllers
- ✅ **Testable** - Easy to mock for testing
- ✅ **Reusable** - Common queries centralized
- ✅ **Consistent** - Same patterns across all models

---

## 🎨 Enums - Type Safety

### **RequestStatus Enum**
```php
enum RequestStatus: string
{
    case OPEN = 'open';
    case HAS_RESPONSES = 'has_responses';
    case MATCHED = 'matched';
    case MATCHED_MANUALLY = 'matched_manually';
    case COMPLETED = 'completed';
    case CLOSED = 'closed';

    public function getLabel(): string
    {
        return match($this) {
            self::OPEN => 'Открыта',
            self::HAS_RESPONSES => 'Есть отклики',
            // ...
        };
    }

    public function isActive(): bool
    {
        return !in_array($this, [self::COMPLETED, self::CLOSED]);
    }
}
```

**Benefits:**
- ✅ **Type Safety** - No more magic strings
- ✅ **IDE Support** - Autocompletion and validation
- ✅ **Maintainable** - Central place for status logic
- ✅ **Internationalization** - Built-in labels

---

## ⚡ Background Jobs - Async Processing

### **MatchRequestsJob**
```php
class MatchRequestsJob implements ShouldQueue
{
    public function __construct(
        private readonly string $requestType,
        private readonly int $requestId
    ) {}

    public function handle(Matcher $matcher): void
    {
        if ($this->requestType === 'send') {
            $sendRequest = SendRequest::find($this->requestId);
            if ($sendRequest) {
                $matcher->matchSendRequest($sendRequest);
            }
        }
        // ...
    }
}
```

**Usage:**
```php
// In controllers
$sendRequest = $this->sendRequestRepository->create($data);
MatchRequestsJob::dispatch('send', $sendRequest->id);
```

**Benefits:**
- ✅ **Non-blocking** - Request creation returns immediately
- ✅ **Scalable** - Matching runs in background
- ✅ **Reliable** - Queue handles failures and retries
- ✅ **Better UX** - Faster response times

---

## 📦 Service Container Registration

### **RepositoryServiceProvider**
```php
class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SendRequestRepositoryInterface::class, function ($app) {
            return new SendRequestRepository(new SendRequest());
        });

        $this->app->bind(DeliveryRequestRepositoryInterface::class, function ($app) {
            return new DeliveryRequestRepository(new DeliveryRequest());
        });

        // ... other repositories
    }
}
```

**Registration in config/app.php:**
```php
'providers' => [
    // ...
    App\Providers\RepositoryServiceProvider::class,
],
```

---

## 🔄 Data Flow Architecture

### **Request Processing Flow**

```
┌─────────────┐    ┌──────────────┐    ┌─────────────┐    ┌──────────────┐
│   Routes    │ -> │ Controllers  │ -> │  Services   │ -> │ Repositories │
│ (HTTP Layer)│    │(HTTP Handler)│    │(Business    │    │(Data Access) │
└─────────────┘    └──────────────┘    │ Logic)      │    └──────────────┘
                                       └─────────────┘           |
                                                                 v
                                                          ┌──────────────┐
                                                          │   Models     │
                                                          │ (Database)   │
                                                          └──────────────┘
```

### **Background Processing Flow**

```
┌─────────────┐    ┌──────────────┐    ┌─────────────┐
│ Controller  │ -> │ Queue Job    │ -> │  Matcher    │
│ (Dispatch)  │    │(Background)  │    │ Service     │
└─────────────┘    └──────────────┘    └─────────────┘
                                              |
                                              v
                                       ┌─────────────┐
                                       │Notification │
                                       │  Service    │
                                       └─────────────┘
```

---

## 🧪 Testing Strategy

### **Controller Testing**
```php
// Controllers are now easy to test with mocked services
public function test_user_can_get_requests()
{
    $mockService = Mockery::mock(UserRequestService::class);
    $mockService->shouldReceive('getUserRequests')
               ->once()
               ->andReturn(collect([]));
               
    $this->app->instance(UserRequestService::class, $mockService);
    
    $response = $this->getJson('/api/user/requests');
    $response->assertStatus(200);
}
```

### **Service Testing**
```php
// Services can be tested in isolation
public function test_can_check_active_requests_limit()
{
    $mockRepo = Mockery::mock(SendRequestRepositoryInterface::class);
    $mockRepo->shouldReceive('countActiveByUser')->andReturn(2);
    
    $service = new RequestService($mockRepo, $mockDeliveryRepo, $mockResponseRepo);
    
    // Test passes - under limit
    $service->checkActiveRequestsLimit($user, 3);
}
```

### **Repository Testing**
```php
// Repositories can be tested with database
public function test_can_find_matching_requests()
{
    $delivery = DeliveryRequest::factory()->create();
    $send = SendRequest::factory()->create(['from_location_id' => $delivery->from_location_id]);
    
    $matches = $this->sendRequestRepository->findMatchingForDelivery($delivery);
    
    $this->assertTrue($matches->contains($send));
}
```

---

## 📊 Performance Improvements

### **Before vs After**

| Aspect | Before | After | Improvement |
|--------|--------|--------|-------------|
| Controller LOC | 1043+ lines | ~100 lines | -90% |
| Request Matching | Synchronous | Asynchronous | Major |
| Database Queries | N+1 problems | Optimized with repos | Major |
| Code Testability | Difficult | Easy with DI | Major |
| Maintainability | Complex | Clean separation | Major |

### **Query Optimization**
- ✅ **Eager Loading** - Prevent N+1 queries
- ✅ **Repository Queries** - Optimized for specific use cases
- ✅ **Background Processing** - Non-blocking operations

---

## 🚀 Getting Started with New Architecture

### **Creating New Features**

1. **Create Repository Interface**
```php
interface NewFeatureRepositoryInterface extends BaseRepositoryInterface
{
    public function customQuery(): Collection;
}
```

2. **Implement Repository**
```php
class NewFeatureRepository extends BaseRepository implements NewFeatureRepositoryInterface
{
    public function customQuery(): Collection
    {
        return $this->model->where('custom_condition')->get();
    }
}
```

3. **Create Service**
```php
class NewFeatureService
{
    public function __construct(
        private NewFeatureRepositoryInterface $repository
    ) {}
    
    public function businessMethod(): array
    {
        $data = $this->repository->customQuery();
        // Business logic here
        return $processedData;
    }
}
```

4. **Update Controller**
```php
class NewFeatureController extends Controller
{
    public function __construct(
        private NewFeatureService $service
    ) {}
    
    public function index(): JsonResponse
    {
        $result = $this->service->businessMethod();
        return response()->json($result);
    }
}
```

5. **Register in Service Provider**
```php
$this->app->bind(NewFeatureRepositoryInterface::class, NewFeatureRepository::class);
```

---

## 📋 Best Practices

### **Controllers**
- ✅ Keep thin - only HTTP concerns
- ✅ Inject services via constructor
- ✅ Use form requests for validation
- ✅ Return consistent JSON responses

### **Services**
- ✅ Single responsibility per service
- ✅ Inject repositories via constructor
- ✅ Handle business logic only
- ✅ Throw exceptions for errors

### **Repositories**
- ✅ Implement interfaces
- ✅ Keep database queries here
- ✅ Return models or collections
- ✅ Use query builder efficiently

### **Background Jobs**
- ✅ Use for long-running operations
- ✅ Handle failures gracefully
- ✅ Keep jobs focused and small
- ✅ Use queues for scalability

---

## 🔧 Development Workflow

### **Adding New Endpoints**
1. Create/update repository methods
2. Create/update service methods
3. Add controller action
4. Add routes
5. Write tests
6. Update documentation

### **Modifying Business Logic**
1. Update service methods
2. Update tests
3. Verify controller still works
4. Check background jobs if affected

### **Database Changes**
1. Create migration
2. Update model relationships
3. Update repository methods
4. Update service logic if needed
5. Update tests

---

## 🎯 Architecture Benefits Summary

### **Maintainability**
- ✅ **Clear separation** of concerns
- ✅ **Single responsibility** principle
- ✅ **Easy to modify** individual components
- ✅ **Consistent patterns** across codebase

### **Testability**
- ✅ **Dependency injection** everywhere
- ✅ **Easy mocking** of services and repositories
- ✅ **Isolated testing** of business logic
- ✅ **Fast test execution** with mocks

### **Performance**
- ✅ **Async processing** with background jobs
- ✅ **Optimized queries** with repositories
- ✅ **Reduced coupling** for better caching
- ✅ **Type safety** reduces runtime errors

### **Developer Experience**
- ✅ **Better IDE support** with type hints
- ✅ **Easier debugging** with clear flow
- ✅ **Faster development** with consistent patterns
- ✅ **Self-documenting** code structure

---

## 🎉 Conclusion

The refactored PostLink API now follows modern Laravel best practices with:

- **Repository Pattern** for clean data access
- **Service Layer** for organized business logic  
- **Background Jobs** for better performance
- **Type-Safe Enums** for reliability
- **SOLID Principles** throughout
- **78% less complex controllers**
- **Excellent testability and maintainability**

This architecture will scale well as the application grows and makes it much easier for developers to understand, modify, and extend the codebase.

**Happy coding! 🚀**