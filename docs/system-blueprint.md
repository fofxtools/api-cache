# System Blueprint

## System Overview

The API Cache Library is a Laravel-based system that provides caching capabilities for various API clients. The system intercepts API requests, manages cached responses, and handles rate limiting and compression.

## Core Components

### 1. Request Pipeline

```
[Application] -> [ApiCacheHandler] -> [CacheRepository]
                        ↓                    ↑
                  [BaseApiClient]     [Database Tables]
                        ↓
                   [API Server]
```

### 2. Component Responsibilities

#### ApiCacheHandler

- Entry point for all API requests
- Orchestrates the caching flow
- Determines if cached response is valid
- Manages cache invalidation
- Coordinates with rate limiting service

#### BaseApiClient

- Handles basic HTTP communications
- Provides common request/response handling
- Handles generic error catching
- Provides interface for child clients

#### API-Specific Clients (extends BaseApiClient, e.g. DemoApiClient)

- Manage API authentication
- Handle API-specific endpoints
- Process API-specific responses
- Handle API-specific error cases

#### CacheRepository

- Manages database operations
- Handles cache key generation
- Stores and retrieves cached responses
- Manages cache expiration
- Coordinates with compression service

#### RateLimitService

- Tracks API request counts
- Enforces rate limits
- Manages rate limit windows
- Handles rate limit exceptions

#### CompressionService

- Compresses response data
- Decompresses cached data
- Manages compression settings
- Handles compression errors

## Data Flow

### 1. Request Flow

```
1. Application makes API request
2. ApiCacheHandler intercepts request
3. Check cache for valid response
   └─ If found: Return cached response
   └─ If not found: Continue to step 4
4. Check rate limits
   └─ If exceeded: Queue or reject request
   └─ If allowed: Continue to step 5
5. Make API request
6. Process response:
   ├─ If 1xx: Don't cache (informational)
   ├─ If 2xx: Cache response
   ├─ If 3xx: Don't cache (redirect)
   ├─ If 4xx: Cache response (except 401/403)
   ├─ If 5xx: Don't cache
   └─ If other: Don't cache (unexpected)
7. Return response to application
```

### 2. Caching Flow

```
1. Generate cache key from request parameters
2. Store response data:
   ├─ Headers
   ├─ Body
   ├─ Status code
   ├─ Metadata
   └─ Expiration time
3. Apply compression if enabled
4. Write to appropriate database table
```

### 3. Cache Retrieval Flow

```
1. Generate cache key
2. Check cache validity:
   ├─ Existence (record exists)
   ├─ Expiration (not expired)
   └─ Data completeness (all required fields present)
3. Decompress if necessary
4. Return cached response
```

## Database Structure

### Tables Per API Client

```
api_cache_{client}_responses
└─ Standard response storage

api_cache_{client}_responses_compressed
└─ Compressed response storage (optional)
```

## Rate Limiting

Uses Laravel's built-in rate limiting system via cache:
- No additional tables required
- Configurable via cache driver (Redis recommended)
- Automatic expiration handling

## Configuration Structure

### Environment Variables

```
{CLIENT}_API_KEY=
{CLIENT}_BASE_URL=
{CLIENT}_CACHE_TTL=
{CLIENT}_RATE_LIMIT_RPM=
{CLIENT}_COMPRESSION_ENABLED=
```

### Config File Structure

```
api-cache.php
└─ apis
   └─ {client}
      ├─ base_url
      ├─ api_key
      ├─ cache_ttl
      ├─ rate_limit
      └─ compression
```

## Error Handling

### Error Categories

1. Cache Errors
   - Invalid cache data
   - Storage failures
   - Retrieval failures

2. API Errors
   - Connection failures
   - Authentication errors
   - Rate limit exceeded
   - Invalid responses

3. Compression Errors
   - Compression failures
   - Decompression failures
   - Data integrity issues

### Error Response Flow

```
1. Error occurs
2. Log error details
3. Determine error type
4. Execute error-specific handling
5. Return appropriate response
```

## Monitoring and Logging

### Key Metrics

- Cache hit/miss rates
- API response times
- Rate limit status
- Compression ratios
- Error rates

### Log Categories

- Cache operations
- API requests
- Rate limit events
- Compression events
- System errors 