# PHP API Cache Library

The purpose of this library will be to be able to cache the responses from any standard API and different endpoints for an API. Such as the OpenAI API, SEMRush API, Pixabay API, DataForSEO API, etc.

## Project Philosophies

- KISS (Keep It Simple Stupid)
- YAGNI (You Aren't Gonna Need It)
- MVP (Minimal Viable Product)
- POLS (Principle of Least Surprise)
- COC (Convention Over Configuration)
- DRY (Don't Repeat Yourself)

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
- `request_headers` (mediumblob)
- `request_body` (mediumblob)
- `response_status_code`
- `response_headers` (mediumblob)
- `response_body` (mediumblob)
- `response_size`
- `response_time` (double)
- `expires_at` (nullable, allows for permanent caching if `null`)
- `created_at`
- `updated_at`

## Proposed Classes

Other classes may be added as necessary.

- ApiCacheManager (manager for API request caching logic)
- BaseApiClient (abstract class to be extended by each client API like the OpenAI API, YouTube API, etc.)
- CacheRepository (handles database caching CRUD logic)
- CompressionService (handles compression logic)
- RateLimitService (handles rate limiting logic for each API/endpoint using Laravel's rate limiting)
- DemoApiClient (child class that extends the BaseApiClient class and makes requests to the local DemoApiController endpoints)
- DemoApiController (a local mock demonstration API for development testing, with sample endpoints including GET and POST)

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
        'demo' => [
            'api_key' => env('DEMO_API_KEY'),
            'base_url' => env('DEMO_BASE_URL'),
            'cache_ttl' => env('DEMO_CACHE_TTL', null),
            'compression_enabled' => env('DEMO_COMPRESSION_ENABLED', false),
            'default_endpoint' => env('DEMO_DEFAULT_ENDPOINT', 'prediction'),
            'rate_limit_max_attempts' => env('DEMO_RATE_LIMIT_MAX_ATTEMPTS', 1000),
            'rate_limit_decay_seconds' => env('DEMO_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL'),
            'cache_ttl' => env('OPENAI_CACHE_TTL', null),
            'compression_enabled' => env('OPENAI_COMPRESSION_ENABLED', false),
            'default_endpoint' => env('OPENAI_DEFAULT_ENDPOINT', 'chat/completions'),
            'rate_limit_max_attempts' => env('OPENAI_RATE_LIMIT_MAX_ATTEMPTS', 60),
            'rate_limit_decay_seconds' => env('OPENAI_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
        'pixabay' => [
            // ...
        ],
        // etc.
    ],
];
```

## Demo API Server
For testing and development, a simple demo API server is included. 

### Setup
1. Start the PHP development server:
```bash
php -S localhost:8000 -t public
```

2. The demo server will be available at:
```
http://localhost:8000/demo-api-server.php/v1
```

### Endpoints
- GET /predictions
  - Parameters: query (string), max_results (int)
  - Returns: Array of prediction results
- POST /reports
  - Parameters: report_type (string), data_source (string)
  - Returns: Report data