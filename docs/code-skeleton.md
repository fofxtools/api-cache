# Code Skeleton Structure

## Project Structure Overview

```
├── config/
│   └── api-cache.php
├── database/
│   └── migrations/
│       └── create_demo_cache_tables.php
├── src/
│   ├── ApiCacheHandler.php
│   ├── BaseApiClient.php
│   ├── CacheRepository.php
│   ├── CompressionService.php
│   ├── RateLimitService.php
│   ├── OpenAIApiClient.php
│   └── DemoApiClient.php
└── tests/
    ├── Unit/
    │   ├── ApiCacheHandlerTest.php
    │   ├── BaseApiClientTest.php
    │   ├── CacheRepositoryTest.php
    │   ├── CompressionServiceTest.php
    │   └── RateLimitServiceTest.php
    └── Integration/
        ├── ApiClientIntegrationTest.php
        ├── CacheStorageIntegrationTest.php
        └── RateLimitIntegrationTest.php
```

## Exceptions

```php
namespace FOfX\ApiCache;

/**
 * Base exception for API cache errors
 */
class ApiCacheException extends \Exception
{
    protected array $context = [];

    public function __construct(
        string $message,
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Returns the context of the exception
     */
    public function getContext(): array
    {
        return $this->context;
    }
}

/**
 * Thrown when rate limit is exceeded
 */
class RateLimitException extends ApiCacheException
{
    protected string $clientName;
    protected int $availableInSeconds;

    public function __construct(
        string $clientName,
        int $availableInSeconds,
        array $context = [],
        ?\Throwable $previous = null
    ) {
        $message = "Rate limit exceeded for client '{$clientName}'. Available in {$availableInSeconds} seconds.";
        parent::__construct($message, $context, 429, $previous);
        
        $this->clientName = $clientName;
        $this->availableInSeconds = $availableInSeconds;
    }

    /**
     * Returns the client name
     */
    public function getClientName(): string
    {
        return $this->clientName;
    }

    /**
     * Returns the number of seconds until the rate limit is available again
     */
    public function getAvailableInSeconds(): int
    {
        return $this->availableInSeconds;
    }
}

/**
 * Thrown when cache operations fail
 */
class CacheException extends ApiCacheException
{
    protected string $operation;

    public function __construct(
        string $operation,
        string $message,
        array $context = [],
        ?\Throwable $previous = null
    ) {
        $fullMessage = "Cache {$operation} failed: {$message}";
        parent::__construct($fullMessage, $context, 0, $previous);
        
        $this->operation = $operation;
    }

    /**
     * Returns the operation that failed
     */
    public function getOperation(): string
    {
        return $this->operation;
    }
}
```

## Core Components

### Base API Client

```php
namespace FOfX\ApiCache;

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
    ) {
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->version = $version;
        $this->handler = $handler ?? app(ApiCacheHandler::class);
        $this->client = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept' => 'application/json',
        ]);
    }
    
    /**
     * Returns the client name
     */
    abstract public function getClientName(): string;
    
    /**
     * Builds the full URL for an endpoint
     */
    abstract public function buildUrl(string $endpoint): string;
    
    /**
     * Sends an API request
     * 
     * Algorithm:
     * - Build full URL from endpoint
     * - Track request timing
     * - Send HTTP request using Laravel HTTP client
     * - Return response with timing data
     */
    public function sendRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $url = $this->buildUrl($endpoint);
        $startTime = microtime(true);
        $requestData = [];
        
        // Create new client instance with Laravel HTTP client request capture middleware
        $client = $this->client->withMiddleware(
            function ($request, $next) use (&$requestData) {
                // Capture full request details
                $requestData['method'] = $request->getMethod();
                $requestData['url'] = (string) $request->getUri();
                $requestData['headers'] = $request->getHeaders();
                $requestData['body'] = (string) $request->getBody();
                return $next($request);
            }
        );
        
        /** @var \Illuminate\Http\Client\Response $response */
        $response = match($method) {
            'HEAD' => $client->head($url, $params),
            'GET' => $client->get($url, $params),
            'POST' => $client->post($url, $params),
            'PUT' => $client->put($url, $params),
            'PATCH' => $client->patch($url, $params),
            'DELETE' => $client->delete($url, $params),
            default => throw new ApiCacheException("Unsupported HTTP method: {$method}")
        };
        
        return [
            'request' => [
                'base_url' => $this->baseUrl,
                'full_url' => $requestData['url'],
                'method' => $requestData['method'],
                'headers' => $requestData['headers'],
                'body' => $requestData['body'],
            ],
            'response' => $response,
            'response_time' => microtime(true) - $startTime
        ];
    }

    /**
     * Send request with caching and rate limiting
     */
    protected function sendCachedRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        return $this->handler->processRequest($this, $endpoint, $params, $method);
    }
}
```

### Child API Clients

These child classes extend the BaseApiClient class and provide specific implementations for different APIs.

In array_merge(), required parameters are passed after $additionalParams. This allows them to override $additionalParams
in case of a conflict.

```php
// DemoApiClient.php
namespace FOfX\ApiCache;

class DemoApiClient extends BaseApiClient
{
    /**
     * Returns the client name
     */
    public function getClientName(): string 
    {
        return 'demo';
    }
    
    /**
     * Builds the full URL for an endpoint
     */
    public function buildUrl(string $endpoint): string 
    {
        return "{$this->baseUrl}/{$endpoint}";
    }
    
    /**
     * Get predictions based on query parameters
     */
    public function prediction(
        string $query,
        int $maxResults = 10,
        array $additionalParams = []
    ): array {
        $params = array_merge($additionalParams, [
            'query' => $query,
            'max_results' => $maxResults,
        ]);

        return $this->sendCachedRequest('predictions', $params, 'GET');
    }

    /**
     * Get report based on type and source
     */
    public function report(
        string $reportType,
        string $dataSource,
        array $additionalParams = []
    ): array {
        $params = array_merge($additionalParams, [
            'report_type' => $reportType,
            'data_source' => $dataSource,
        ]);

        return $this->sendCachedRequest('reports', $params, 'POST');
    }
}

// OpenAIApiClient.php
namespace FOfX\ApiCache;

class OpenAIApiClient extends BaseApiClient
{
    /**
     * Returns the client name
     */
    public function getClientName(): string 
    {
        return 'openai';
    }
    
    /**
     * Builds the full URL for an endpoint
     */
    public function buildUrl(string $endpoint): string 
    {
        return "{$this->baseUrl}/{$endpoint}";
    }

    /**
     * Get completions based on prompt parameters
     */
    public function completions(
        string $prompt,
        string $model = 'gpt-3.5-turbo-instruct',
        int $maxTokens = 16,
        int $n = 1,
        float $temperature = 1.0,
        float $topP = 1.0,
        array $additionalParams = []
    ): array {
        $params = array_merge($additionalParams, [
            'prompt'       => $prompt,
            'model'        => $model,
            'max_tokens'   => $maxTokens,
            'n'            => $n,
            'temperature'  => $temperature,
            'top_p'        => $topP,
        ]);

        return $this->sendCachedRequest('completions', $params, 'POST');
    }

    /**
     * Get chat completions based on messages parameters
     */
    public function chatCompletions(
        array|string $messages,
        string $model = 'gpt-4o-mini',
        ?int $maxCompletionTokens = null,
        int $n = 1,
        float $temperature = 1.0,
        float $topP = 1.0,
        array $additionalParams = []
    ): array {
        // If messages is a string, assume it is a prompt and wrap it in an array
        if (is_string($messages)) {
            $messages = ['role' => 'user', 'content' => $messages];
        }

        $params = array_merge($additionalParams, [
            'messages'     => $messages,
            'model'        => $model,
            'n'            => $n,
            'temperature'  => $temperature,
            'top_p'        => $topP,
        ]);

        if ($maxCompletionTokens !== null) {
            $params['max_completion_tokens'] = $maxCompletionTokens;
        }

        return $this->sendCachedRequest('chat/completions', $params, 'POST');
    }
}
```

### Services

```php
// ApiCacheHandler.php
namespace FOfX\ApiCache;

/**
 * Main entry point for caching API responses.
 * Orchestrates caching, rate limiting, and API requests.
 */
class ApiCacheHandler
{
    protected CacheRepository $repository;
    protected RateLimitService $rateLimiter;
    
    /**
     * @param CacheRepository $repository For caching responses
     * @param RateLimitService $rateLimiter For rate limit tracking
     */
    public function __construct(CacheRepository $repository, RateLimitService $rateLimiter)
    {
        $this->repository = $repository;
        $this->rateLimiter = $rateLimiter;
    }
    
    /**
     * Main method to handle API requests with caching and rate limiting
     * 
     * Algorithm:
     * - Generate cache key from client name, endpoint, params, version
     * - Look for cached response
     * - If valid cached response exists, return it
     * - If no cache or expired:
     *    - Check rate limit ($this->rateLimiter->allowRequest())
     *    - If rate limited, throw RateLimitException
     *    - Make API request
     *    - If request fails, let request exceptions bubble up (HttpException, etc)
     *    - Track request in rate limiter ($this->rateLimiter->incrementAttempts())
     *    - Cache the response
     *    - Return response
     * 
     * @param BaseApiClient $client The API client making the request
     * @param string $endpoint API endpoint to call
     * @param array $params Request parameters
     * @return array API response data
     * @throws RateLimitException When rate limit exceeded
     * @throws ApiCacheException When request fails
     */
    public function processRequest(BaseApiClient $client, string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $cacheKey = $this->generateCacheKey(
            $client->getClientName(),
            $endpoint,
            $params,
            $client->version
        );
        
        // Check cache first
        if ($cached = $this->getCachedResponse($client, $cacheKey)) {
            return $cached;
        }
        
        // Make live request if no cache
        if (!$this->rateLimiter->allowRequest($client->getClientName())) {
            throw new RateLimitException(
                $client->getClientName(),
                $this->rateLimiter->getAvailableIn($client->getClientName())
            );
        }
        
        $apiResult = $client->sendRequest($endpoint, $params, $method);
        $this->rateLimiter->incrementAttempts($client->getClientName());
        
        // Cache the response
        $this->cacheResponse($client, $cacheKey, $apiResult, $endpoint);
        
        return $apiResult;
    }
    
    /**
     * Get cached response
     */
    protected function getCachedResponse(
        BaseApiClient $client,
        string $cacheKey
    ): ?array {
        return $this->repository->get(
            $client->getClientName(),
            $cacheKey
        );
    }
    
    /**
     * Cache the response
     * 
     * Algorithm:
     * - Prepare response metadata (based on Laravel HTTP client's response):
     *   - response_status_code (from $apiResult['response']->status())
     *   - response_headers (from $apiResult['response']->headers())
     *   - response_body (from $apiResult['response']->body())
     *   - response_size (calculated from response_body)
     *   - response_time (from $apiResult['response_time'])
     * - Store prepared response in repository
     */
    protected function cacheResponse(
        BaseApiClient $client,
        string $cacheKey,
        array $apiResult,
        string $endpoint,
        ?int $ttl = null
    ): void {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = $apiResult['response'];
        
        $metadata = [
            'endpoint' => $endpoint,
            'version' => $client->version,
            'base_url' => $apiResult['request']['base_url'],
            'full_url' => $apiResult['request']['full_url'],
            'method' => $apiResult['request']['method'],
            'request_headers' => $apiResult['request']['headers'],
            'request_body' => $apiResult['request']['body'],
            'response_status_code' => $response->status(),
            'response_headers' => $response->headers(),
            'response_body' => $response->body(),
            'response_size' => strlen($response->body()),
            'response_time' => $apiResult['response_time'],
        ];
        
        $this->repository->store(
            $client->getClientName(),
            $cacheKey,
            $metadata,
            $ttl
        );
    }
    
    /**
     * Generate cache key
     * 
     * Algorithm:
     * - Get normalized params
     * - Generate hash from normalized params
     * - Combine components into key: "{client}.{endpoint}.{hash}.{version}"
     * - Generate hash that uniquely identifies this request
     */
    public function generateCacheKey(
        string $client,
        string $endpoint,
        array $params,
        ?string $version = null
    ): string {
        // To be implemented
    }

    /**
     * Ensure deterministic serialization of nested structures
     * 
     * Algorithm:
     * - Filter out null/empty values
     * - Sort array by keys
     * - Convert remaining values to strings
     * - Handle nested arrays via json_encode
     */
    public function normalizeParams(array $params): array {
        // To be implemented
    }
}

// RateLimitService.php
namespace FOfX\ApiCache;

class RateLimitService
{
    protected RateLimiter $limiter;
    
    public function __construct(RateLimiter $limiter) 
    {
        $this->limiter = $limiter;
    }
    
    /**
     * Allow request if there are remaining attempts
     */
    public function allowRequest(string $clientName): bool 
    {
        return $this->getRemainingAttempts($clientName) > 0;
    }
    
    /**
     * Increment attempts for the client
     * 
     * Algorithm:
     * - Add attempt for the client
     * - Use configured decay time in seconds
     */
    public function incrementAttempts(string $clientName): void 
    {
        $decaySeconds = config("api-cache.apis.{$clientName}.rate_limit_decay_seconds");
        $this->limiter->increment($clientName, $decaySeconds);
    }
    
    /**
     * Get remaining attempts for the client
     * 
     * Algorithm:
     * - Get remaining attempts for client
     * - Return attempts left within window
     * - Return 0 if limit exceeded
     */
    public function getRemainingAttempts(string $clientName): int 
    {
        $requestsPerMinute = config("api-cache.apis.{$clientName}.rate_limit_requests_per_minute");
        
        return max(0, $this->limiter->remaining($clientName, $requestsPerMinute));
    }

    /**
     * Get Laravel RateLimiter availableIn() value
     */
    public function getAvailableIn(string $clientName): int
    {
        // If not rate limited, return 0
        $maxAttempts = config("api-cache.apis.{$clientName}.rate_limit_requests_per_minute");
        if (!$this->limiter->tooManyAttempts($clientName, $maxAttempts)) {
            return 0;
        }
        
        // Return seconds until rate limit resets
        return $this->limiter->availableIn($clientName);
    }
}

// CompressionService.php
namespace FOfX\ApiCache;

class CompressionService
{
    protected bool $enabled = false;
    
    public function __construct(bool $enabled = false)
    {
        $this->enabled = $enabled;
    }
    
    /**
     * Compress data
     * 
     * Algorithm:
     * - If compression disabled, return data as-is
     * - Compress using gzcompress
     * - Return compressed string or throw CacheException
     */
    public function compress(string $data): string
    {
        if (!$this->enabled) {
            return $data;
        }
        
        $compressed = gzcompress($data);
        if ($compressed === false) {
            throw new CacheException('compress', 'Failed to compress data');
        }
        
        return $compressed;
    }
    
    /**
     * Decompress data
     * 
     * Algorithm:
     * - If compression disabled, return data as-is
     * - Decompress using gzuncompress
     * - Return decompressed string or throw CacheException
     */
    public function decompress(string $data): string
    {
        if (!$this->enabled) {
            return $data;
        }
        
        $decompressed = gzuncompress($data);
        if ($decompressed === false) {
            throw new CacheException('decompress', 'Failed to decompress data');
        }
        
        return $decompressed;
    }
    
    /**
     * Check if compression is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
```

### Repository

```php
// CacheRepository.php
namespace FOfX\ApiCache;

use Illuminate\Database\Connection;

class CacheRepository
{
    protected Connection $db;
    protected CompressionService $compression;
    
    public function __construct(Connection $db, CompressionService $compression)
    {
        $this->db = $db;
        $this->compression = $compression;
    }
    
    /**
     * Prepare headers for storage (always array)
     * 
     * @param array|null $headers HTTP headers array
     * @return string|null JSON encoded and optionally compressed headers
     */
    protected function prepareHeaders(?array $headers): ?string
    {
        if ($headers === null) {
            return null;
        }
        
        $encoded = json_encode($headers);
        return $this->compression->isEnabled()
            ? $this->compression->compress($encoded)
            : $encoded;
    }

    /**
     * Retrieve headers from storage (always array)
     * 
     * @param string|null $data Stored header data
     * @return array|null Decoded headers array
     */
    protected function retrieveHeaders(?string $data): ?array
    {
        if ($data === null) {
            return null;
        }
        
        $raw = $this->compression->isEnabled()
            ? $this->compression->decompress($data)
            : $data;
            
        return json_decode($raw, true);
    }

    /**
     * Prepare body for storage (always string)
     * 
     * @param string|null $body Raw body content
     * @return string|null Optionally compressed body
     */
    protected function prepareBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }
        
        return $this->compression->isEnabled()
            ? $this->compression->compress($body)
            : $body;
    }

    /**
     * Retrieve body from storage (always string)
     * 
     * @param string|null $data Stored body data
     * @return string|null Raw body content
     */
    protected function retrieveBody(?string $data): ?string
    {
        if ($data === null) {
            return null;
        }
        
        return $this->compression->isEnabled()
            ? $this->compression->decompress($data)
            : $data;
    }
    
    /**
     * Store the response in the cache
     * 
     * Algorithm:
     * - Determine table name based on compression
     * - Calculate expires_at from ttl
     * - Validate required fields
     * - Expected data structure:
     *   - response_status_code: HTTP status code
     *   - response_headers: Array of headers
     *   - response_body: Raw response body
     *   - response_size: Body length in bytes
     *   - response_time: Request duration in seconds
     * - Prepare data for storage
     * - Store in database
     *
     * @param string $client Client identifier
     * @param string $key Cache key
     * @param array $metadata Response metadata
     * @param int|null $ttl Time to live in seconds
     * @throws CacheException When required fields are missing
     */
    public function store(string $client, string $key, array $metadata, ?int $ttl = null): void
    {
        $table = $this->getTableName($client);
        $expiresAt = $ttl ? now()->addSeconds($ttl) : null;
        
        // Ensure required fields exist
        if (empty($metadata['endpoint']) || empty($metadata['response_body'])) {
            throw new CacheException('store', 'Missing required fields');
        }
        
        // Set defaults for optional fields
        $metadata = array_merge([
            'version' => null,
            'base_url' => null,
            'full_url' => null,
            'method' => null,
            'request_headers' => null,
            'request_body' => null,
            'response_status_code' => null,
            'response_headers' => null,
            'response_size' => strlen($metadata['response_body'] ?? ''),
            'response_time' => null,
        ], $metadata);
        
        $this->db->table($table)->insert([
            'client' => $client,
            'key' => $key,
            'version' => $metadata['version'],
            'endpoint' => $metadata['endpoint'],
            'base_url' => $metadata['base_url'],
            'full_url' => $metadata['full_url'],
            'method' => $metadata['method'],
            'request_headers' => $this->prepareHeaders($metadata['request_headers']),
            'request_body' => $this->prepareBody($metadata['request_body']),
            'response_status_code' => $metadata['response_status_code'],
            'response_headers' => $this->prepareHeaders($metadata['response_headers']),
            'response_body' => $this->prepareBody($metadata['response_body']),
            'response_size' => $metadata['response_size'],
            'response_time' => $metadata['response_time'],
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    
    /**
     * Get the response from the cache
     * 
     * Algorithm:
     * - Get from correct table
     * - Check if expired
     * - Return null if not found or expired
     * - Decompress if needed
     * - Return data
     */
    public function get(string $client, string $key): ?array
    {
        $table = $this->getTableName($client);
        
        $data = $this->db->table($table)
            ->where('key', $key)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
            
        if (!$data) {
            return null;
        }
        
        return [
            'version' => $data->version,
            'endpoint' => $data->endpoint,
            'base_url' => $data->base_url,
            'full_url' => $data->full_url,
            'method' => $data->method,
            'request_headers' => $this->retrieveHeaders($data->request_headers),
            'request_body' => $this->retrieveBody($data->request_body),
            'response_status_code' => $data->response_status_code,
            'response_headers' => $this->retrieveHeaders($data->response_headers),
            'response_body' => $this->retrieveBody($data->response_body),
            'response_size' => $data->response_size,
            'response_time' => $data->response_time,
            'expires_at' => $data->expires_at,
        ];
    }
    
    /**
     * Cleanup expired responses
     * 
     * Algorithm:
     * - If client specified, clean only that client's tables
     * - Otherwise clean all clients from config
     */
    public function cleanup(?string $client = null): void
    {
        $clients = $client 
            ? [$client]
            : array_keys(config('api-cache.apis'));
        
        foreach ($clients as $client) {
            $this->db->table($this->getTableName($client))
                ->where('expires_at', '<=', now())
                ->delete();
        }
    }
    
    /**
     * Get the table name for the client
     */
    protected function getTableName(string $client): string
    {
        $suffix = $this->compression->isEnabled() ? '_compressed' : '';
        return "api_cache_{$client}_responses{$suffix}";
    }
}
```

### Database Migrations

```php
// create_demo_cache_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Standard response table
        Schema::create('api_cache_demo_responses', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('client');
            $table->string('version')->nullable();
            $table->string('endpoint');
            $table->string('base_url')->nullable();
            $table->string('full_url')->nullable();
            $table->string('method')->nullable();
            $table->mediumText('request_headers')->nullable();
            $table->mediumText('request_body')->nullable();
            $table->integer('response_status_code')->nullable();
            $table->mediumText('response_headers')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->integer('response_size')->nullable();
            $table->double('response_time')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['client', 'endpoint', 'version']);
            $table->index('expires_at');
        });

        // Compressed response table
        Schema::create('api_cache_demo_responses_compressed', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('client');
            $table->string('version')->nullable();
            $table->string('endpoint');
            $table->string('base_url')->nullable();
            $table->string('full_url')->nullable();
            $table->string('method')->nullable();
            $table->mediumBlob('request_headers_compressed')->nullable();
            $table->mediumBlob('request_body_compressed')->nullable();
            $table->integer('response_status_code')->nullable();
            $table->mediumBlob('response_headers_compressed')->nullable();
            $table->mediumBlob('response_body_compressed')->nullable();
            $table->integer('response_size_compressed')->nullable();
            $table->double('response_time')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['client', 'endpoint', 'version']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_demo_responses');
        Schema::dropIfExists('api_cache_demo_responses_compressed');
    }
};
```

### Configuration

```php
// api-cache.php
return [
    'apis' => [
        'demo' => [
            'api_key' => env('DEMO_API_KEY'),
            'base_url' => env('DEMO_BASE_URL'),
            'cache_ttl' => env('DEMO_CACHE_TTL', null),
            'compression_enabled' => env('DEMO_COMPRESSION_ENABLED', false),
            'default_endpoint' => env('DEMO_DEFAULT_ENDPOINT', 'prediction'),
            'rate_limit_requests_per_minute' => env('DEMO_RATE_LIMIT_REQUESTS_PER_MINUTE', 1000),
            'rate_limit_decay_seconds' => env('DEMO_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL'),
            'cache_ttl' => env('OPENAI_CACHE_TTL', null),
            'compression_enabled' => env('OPENAI_COMPRESSION_ENABLED', false),
            'default_endpoint' => env('OPENAI_DEFAULT_ENDPOINT', 'chat/completions'),
            'rate_limit_requests_per_minute' => env('OPENAI_RATE_LIMIT_REQUESTS_PER_MINUTE', 60),
            'rate_limit_decay_seconds' => env('OPENAI_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
    ],
];
```