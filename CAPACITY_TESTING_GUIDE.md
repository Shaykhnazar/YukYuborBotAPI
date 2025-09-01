# Capacity-Aware Matching System - Testing Guide

## Overview

This guide covers how to test the new capacity-aware matching system that limits deliverers to a maximum number of active responses and automatically rebalances when needed.

## Automated Testing

### 1. Unit Tests

**Run Unit Tests:**
```bash
vendor/bin/phpunit tests/Unit/Services/Matching/CapacityAwareMatchingServiceTest.php
vendor/bin/phpunit tests/Unit/Services/Matching/ResponseRebalancingServiceTest.php
```

**Unit Test Coverage:**
- ✅ Capacity-aware matching prioritizes least-loaded deliverers
- ✅ Deliverers at capacity are excluded from new matches
- ✅ Correct counting of active responses (pending + partial)
- ✅ Alternative deliverer finding for redistribution
- ✅ Rebalancing triggers only for matching responses from deliverers
- ✅ Response redistribution when alternatives available
- ✅ Auto-rejection when no alternatives available

### 2. Feature Tests

**Run Feature Tests:**
```bash
vendor/bin/phpunit tests/Feature/CapacityAwareMatchingTest.php
```

**Feature Test Coverage:**
- ✅ Fair distribution among multiple deliverers
- ✅ Capacity limits prevent over-assignment
- ✅ Rebalancing after deliverer acceptance
- ✅ Complete flow: matching → acceptance → chat creation
- ✅ Proper rejection handling

### 3. Run All Tests

```bash
# Run specific capacity tests
vendor/bin/phpunit --group=capacity

# Run all tests
vendor/bin/phpunit
```

## Manual Testing via API Endpoints

### Prerequisites

**Environment Setup:**
- Set `APP_ENV=local` or `APP_ENV=development`
- Capacity testing endpoints are only available in dev environments

### Available Endpoints

**Base URL:** `/api/dev/capacity`

#### 1. System Overview
```http
GET /api/dev/capacity/overview
```

**Response:**
```json
{
  "system_stats": {
    "total_deliverers_with_responses": 3,
    "deliverers_over_capacity": 1,
    "total_active_responses": 8,
    "capacity_utilization_rate": 88.89,
    "deliverer_details": [...]
  },
  "config": {
    "max_capacity": 3,
    "rebalancing_enabled": true
  }
}
```

#### 2. Deliverer Capacity Details
```http
GET /api/dev/capacity/deliverer/{delivererId}
```

**Response:**
```json
{
  "capacity_info": {
    "deliverer_id": 123,
    "max_capacity": 3,
    "current_load": 2,
    "available_capacity": 1,
    "pending_responses": 1,
    "partial_responses": 1,
    "is_at_capacity": false
  },
  "active_responses": [...]
}
```

#### 3. Create Test Scenario
```http
POST /api/dev/capacity/create-test-scenario
Content-Type: application/json

{
  "deliverers": 3,
  "send_requests": 9,
  "location": "Test Location"
}
```

**What it does:**
- Creates X deliverers with delivery requests
- Creates Y send requests from different users
- Automatically matches them using capacity-aware logic
- Returns distribution statistics

#### 4. Accept Response (Trigger Rebalancing)
```http
POST /api/dev/capacity/accept-response
Content-Type: application/json

{
  "response_id": 123,
  "user_id": 456
}
```

**Response:**
```json
{
  "success": true,
  "response_status": {
    "id": 123,
    "overall_status": "partial",
    "deliverer_status": "accepted",
    "sender_status": "pending"
  },
  "capacity_before": {
    "current_load": 3
  },
  "capacity_after": {
    "current_load": 2
  },
  "rebalancing_occurred": true
}
```

#### 5. List All Active Responses
```http
GET /api/dev/capacity/list-responses
```

#### 6. Reset Test Data
```http
DELETE /api/dev/capacity/reset-test-data
```

## Testing Scenarios

### Scenario 1: Basic Capacity Distribution

**Goal:** Verify fair distribution among deliverers

**Steps:**
1. `POST /api/dev/capacity/create-test-scenario` with `{"deliverers": 3, "send_requests": 9}`
2. `GET /api/dev/capacity/overview` - verify each deliverer has 3 responses
3. `GET /api/dev/capacity/list-responses` - verify fair distribution

**Expected Result:** Each of 3 deliverers should have exactly 3 responses

### Scenario 2: Capacity Limit Enforcement

**Goal:** Verify system stops assigning when at capacity

**Steps:**
1. `POST /api/dev/capacity/create-test-scenario` with `{"deliverers": 1, "send_requests": 5}`
2. `GET /api/dev/capacity/deliverer/{id}` for the single deliverer
3. Verify only 3 responses assigned (not 5)

**Expected Result:** Single deliverer should have max 3 responses, remaining 2 unassigned

### Scenario 3: Rebalancing After Acceptance

**Goal:** Verify automatic redistribution after deliverer accepts

**Steps:**
1. Create test scenario with multiple deliverers
2. Find a response where deliverer is at capacity
3. `POST /api/dev/capacity/accept-response` with that response
4. `GET /api/dev/capacity/overview` - check if rebalancing occurred
5. Verify excess responses were redistributed or rejected

**Expected Result:** Accepting deliverer should have ≤3 active responses after acceptance

### Scenario 4: Complete Flow Testing

**Goal:** Test full partnership creation flow

**Steps:**
1. Create test scenario
2. Accept response as deliverer (becomes 'partial')
3. Accept same response as sender (becomes 'accepted', creates chat)
4. Verify chat creation and request status updates

**Expected Result:** 
- Response status: 'accepted'
- Chat created between users
- Requests marked as 'matched'
- Proper cross-references set

### Scenario 5: No Alternative Deliverers

**Goal:** Test auto-rejection when no redistribution possible

**Steps:**
1. Create scenario where all deliverers are at capacity
2. Accept a response to trigger rebalancing
3. Verify excess responses are auto-rejected (not redistributed)

**Expected Result:** Excess responses marked as 'rejected' with appropriate message

## Configuration Testing

### Environment Variables

Test different configurations by modifying `.env`:

```env
# Capacity limits
DELIVERER_MAX_CAPACITY=2  # Lower for easier testing

# Rebalancing behavior
REBALANCING_ENABLED=false  # Disable to test without rebalancing
AUTO_REJECT_NO_ALTERNATIVES=false  # Keep pending instead of rejecting

# Distribution strategy
DISTRIBUTION_STRATEGY=round_robin  # or least_loaded, random
```

### Database Verification

**Check Response Distribution:**
```sql
SELECT user_id, COUNT(*) as response_count, overall_status
FROM responses 
WHERE overall_status IN ('pending', 'partial')
GROUP BY user_id, overall_status
ORDER BY user_id;
```

**Check Rebalancing Events:**
```sql
SELECT * FROM responses 
WHERE message LIKE '%Auto-rejected%' 
ORDER BY updated_at DESC;
```

## Performance Testing

### Load Testing Script

```bash
# Create large test scenario
curl -X POST "http://localhost:8000/api/dev/capacity/create-test-scenario" \
  -H "Content-Type: application/json" \
  -d '{"deliverers": 10, "send_requests": 100}'

# Monitor system performance
curl "http://localhost:8000/api/dev/capacity/overview"
```

### Memory Usage Monitoring

```bash
# Monitor while running tests
php artisan tinker
> memory_get_usage(true) // Before
> // Run capacity operations
> memory_get_usage(true) // After
```

## Troubleshooting

### Common Issues

**1. No Rebalancing Occurring**
- Check `REBALANCING_ENABLED=true` in `.env`
- Verify response is of type 'matching'
- Confirm user accepting is the deliverer role

**2. Tests Failing**
- Clear config cache: `php artisan config:clear`
- Check database state: use reset endpoint
- Verify factory states match expectations

**3. Capacity Not Enforced**
- Verify `CapacityAwareMatchingService` is being used in `Matcher`
- Check config values are loaded correctly
- Review logs for capacity calculation errors

### Debug Logging

Enable detailed logging in `config/logging.php`:

```php
'capacity' => [
    'driver' => 'single',
    'path' => storage_path('logs/capacity.log'),
    'level' => 'debug',
],
```

Add to services:
```php
Log::channel('capacity')->info('Capacity event', $data);
```

## Integration with Existing Tests

### Running Alongside Existing Tests

```bash
# Run capacity tests only
vendor/bin/phpunit --filter="Capacity"

# Run all matching-related tests
vendor/bin/phpunit tests/Unit/Services/Matching/

# Run full test suite
vendor/bin/phpunit
```

### CI/CD Integration

Add to your CI pipeline:

```yaml
- name: Run Capacity Tests
  run: |
    php artisan config:cache
    vendor/bin/phpunit tests/Unit/Services/Matching/CapacityAwareMatchingServiceTest.php
    vendor/bin/phpunit tests/Unit/Services/Matching/ResponseRebalancingServiceTest.php
    vendor/bin/phpunit tests/Feature/CapacityAwareMatchingTest.php
```

This comprehensive testing approach ensures the capacity-aware system works correctly in all scenarios and integrates seamlessly with your existing codebase.