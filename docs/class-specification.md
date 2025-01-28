# Class Specification

## Exceptions

```php
class ApiCacheException extends Exception {}
class RateLimitException extends ApiCacheException {}
class CacheException extends ApiCacheException {}
```

## Core Classes

### ApiCacheHandler

Main entry point for caching API responses.

```php
class ApiCacheHandler
{
    protected CacheRepository $repository;
    protected RateLimitService $rateLimiter;
    
    public function __construct(CacheRepository $repository, RateLimitService $rateLimiter);
    
    // Main method to handle API requests
    public function processRequest(BaseApiClient $client, string $endpoint, array $params = []): array;
    
    // Check if response exists in cache
    protected function getCachedResponse(string $cacheKey): ?array;
    
    // Store response in cache
    protected function cacheResponse(string $cacheKey, array $response, ?int $ttl = null): void;
    
    // Generate cache key from request parameters
    public function generateCacheKey(string $client, string $endpoint, array $params, ?string $version = null): string;

    // Normalize parameters for consistent cache key generation
    public function normalizeParams(array $params): array;
}
```

### BaseApiClient

Base class for all API clients.

```php
abstract class BaseApiClient
{
    protected string $baseUrl;
    protected string $apiKey;
    protected ?string $version;
    protected HttpClient $client;
    protected ApiCacheHandler $handler;
    
    public function __construct(
        string $baseUrl,
        string $apiKey,
        ?string $version = null,
        ?ApiCacheHandler $handler = null
    );
    
    // Get client name (e.g., 'openai', 'youtube')
    abstract public function getClientName(): string;
    
    // Build API-specific URL
    abstract public function buildUrl(string $endpoint): string;
    
    // Send uncached API request
    public function sendRequest(string $endpoint, array $params = [], string $method = 'GET'): array;

    // Send request with caching and rate limiting
    protected function sendCachedRequest(string $endpoint, array $params = [], string $method = 'GET'): array;
}
```

### CacheRepository

Handles database operations for caching.

```php
class CacheRepository
{
    protected Connection $db;
    protected CompressionService $compression;
    
    public function __construct(Connection $db, CompressionService $compression);
    
    // Store response in cache
    public function store(string $client, string $key, array $data, ?int $ttl = null): void;
    
    // Retrieve response from cache
    public function get(string $client, string $key): ?array;
    
    // Check if cache exists and is valid
    public function exists(string $client, string $key): bool;
    
    // Remove expired cache entries
    public function cleanup(): void;
}
```

### RateLimitService

Handles API rate limiting using Laravel's limiter.

```php
class RateLimitService
{
    protected RateLimiter $limiter;
    
    public function __construct(RateLimiter $limiter);
    
    // Check if client has remaining attempts
    public function allowRequest(string $clientName): bool;
    
    // Add attempt for the client
    public function incrementAttempts(string $clientName): void;
    
    // Get remaining attempts for client
    public function getRemainingAttempts(string $clientName): int;
}
```

### CompressionService

Optional service for response compression.

```php
class CompressionService
{
    protected bool $enabled = false;
    
    public function __construct(bool $enabled = false);
    
    // Compress data
    public function compress(string $data): string;
    
    // Decompress data
    public function decompress(string $data): string;
    
    // Check if compression is enabled
    public function isEnabled(): bool;
}
```

## Sample API Client

### DemoApiClient

A sample implementation of BaseApiClient.

```php
class DemoApiClient extends BaseApiClient
{
    // Returns the client identifier
    public function getClientName(): string;
    
    // Builds the full URL for an endpoint
    public function buildUrl(string $endpoint): string;
    
    // Get predictions based on query parameters
    public function prediction(
        string $query,
        int $maxResults = 10,
        array $additionalParams = []
    ): array;

    // Get report based on type and source
    public function report(
        string $reportType,
        string $dataSource,
        array $additionalParams = []
    ): array;
}
```

## Example API Client Implementation

Example of a real-world API client implementation.

```php
class OpenAIApiClient extends BaseApiClient
{
    // Returns the client identifier
    public function getClientName(): string;
    
    // Builds the full URL for an endpoint
    public function buildUrl(string $endpoint): string;

    // Send a completion request to OpenAI
    public function completions(
        string $prompt,
        string $model = 'gpt-3.5-turbo-instruct',
        int $maxTokens = 16,
        int $n = 1,
        float $temperature = 1.0,
        float $topP = 1.0,
        array $additionalParams = []
    ): array;

    // Send a chat completion request to OpenAI
    public function chatCompletions(
        array|string $messages,
        string $model = 'gpt-4o-mini',
        ?int $maxCompletionTokens = null,
        int $n = 1,
        float $temperature = 1.0,
        float $topP = 1.0,
        array $additionalParams = []
    ): array;
}
```