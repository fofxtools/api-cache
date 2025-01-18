# PHP API Cache Library

The purpose of this library will be to be able to cache the responses from any standard API and different endpoints for an API. Such as the OpenAI API, SEMRush API, Pixabay API, DataForSEO API, etc.

## Project Philosophies

- KISS (Keep It Simple)
- YAGNI (You Aren't Gonna Need It)
- Minimalism (MVP approach)

## Project Overview

Create a Laravel-based PHP library for caching API responses, designed to work with various APIs (OpenAI, YouTube, Pixabay, SEMRush, etc.). The library will use:

- Laravel (no front end)
- Database for caching
- Laravel HTTP client
- Laravel built-in logging
- Laravel built-in rate limiting

## Core Features

- Request caching with expiry
- Rate limiting
- Job scheduling
- Environment file based configuration
- Optional response compression (disabled by default)

## Database Structure

- Each API client will have its own set of tables
- Default table prefix: `api_cache_`
- Main table for caching responses: `api_cache_{client}_responses` (e.g., `api_cache_openai_responses`)
- Compressed responses tables: `api_cache_{client}_responses_compressed` (e.g., `api_cache_openai_responses_compressed`)

### Shared Field List for `api_cache_{client}_responses` Tables (can add more fields if necessary)

- `id`
- `key`
- `client`
- `version`
- `endpoint`
- `base_url`
- `full_url`
- `method`
- `request_headers` (mediumtext)
- `request_body` (mediumtext)
- `response_status_code`
- `response_headers` (mediumtext)
- `response_body` (mediumtext)
- `response_size`
- `response_time` (double)
- `expires_at` (nullable, allows for permanent caching if `null`)
- `created_at`
- `updated_at`

### Shared Field List for `api_cache_{client}_responses_compressed` Tables (can add more fields if necessary)

- `id`
- `key`
- `client`
- `version`
- `endpoint`
- `base_url`
- `full_url`
- `method`
- `request_headers_compressed` (mediumblob)
- `request_body_compressed` (mediumblob)
- `response_status_code`
- `response_headers_compressed` (mediumblob)
- `response_body_compressed` (mediumblob)
- `response_size_compressed`
- `response_time` (double)
- `expires_at` (nullable, allows for permanent caching if `null`)
- `created_at`
- `updated_at`

## Proposed Classes

Other classes may be added as necessary.

- ApiCacheHandler (manager for API request caching logic)
- BaseApiClient (to be extended by each client API like the OpenAI API, YouTube API, etc.)
- CacheRepository (handles database caching CRUD logic)
- CompressionService (handles compression logic)
- RateLimitService (handles rate limiting logic for each API/endpoint using Laravel's rate limiting)
- SimulatedApiClient (extends the BaseApiClient class and makes requests to the local SimulatedApiController endpoints)
- SimulatedApiController (a local mock demonstration API for development testing, with simulated endpoints including GET and POST)

## Configuration and Installation

1. **Environment Configuration File** (each API client and endpoint combination has its own set of settings)

- API base URLs
- API keys
- Compression settings
- Cache TTLs (e.g., `cache_ttl` => 3600 for 1 hour, or `null` for no expiry)
- Rate limits

2. **Possible `config/api-cache.php` File for Package Configuration**

```php
return [
    'apis' => [
        'openai' => [
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            'cache_ttl' => env('OPENAI_CACHE_TTL', null),
            'rate_limit' => [
                'requests_per_minute' => 60,
            ],
            'compression' => [
                'enabled' => false,
            ],
        ],
        'pixabay' => [
            // ...
        ],
        'simulated' => [
            'base_url' => env('SIMULATED_API_BASE_URL', 'http://localhost/simulated-api/v1'),
            'api_key' => null, // Simulated API typically does not require an API key
            'cache_ttl' => env('SIMULATED_API_CACHE_TTL', null),
            'rate_limit' => [
                'requests_per_minute' => 1000, // Higher limit to accommodate extensive testing
            ],
            'compression' => [
                'enabled' => false,
            ],
        ],
        // etc.
    ],
];
```