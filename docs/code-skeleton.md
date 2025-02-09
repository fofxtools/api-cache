# Code Skeleton Structure

## Project Structure Overview

```
├── config/
│   └── api-cache.php
├── database/
│   └── migrations/
│       ├── create_api_cache_demo_responses_table.php
│       └── create_api_cache_demo_responses_compressed_table.php
├── public/
│   └── demo-api-server.php
├── src/
│   ├── ApiCacheManager.php
│   ├── BaseApiClient.php
│   ├── CacheRepository.php
│   ├── CompressionService.php
│   ├── RateLimitService.php
│   ├── OpenAIApiClient.php
│   └── DemoApiClient.php
└── tests/
    ├── Unit/
    │   ├── ApiCacheManagerTest.php
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
/**
 * Thrown when rate limit is exceeded
 */
class RateLimitException extends \Exception
{
    protected string $clientName;
    protected int $availableInSeconds;

    public function __construct(
        string $clientName,
        int $availableInSeconds,
        string $message = '',
        ?\Throwable $previous = null
    ) {
        $message = $message ?: "Rate limit exceeded for client '{$clientName}'. Available in {$availableInSeconds} seconds.";
        parent::__construct($message, 429, $previous);
        
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
```

## Core Components

### Base API Client

```php
namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use FOfX\Helper;

abstract class BaseApiClient
{
    protected string $clientName;
    protected string $baseUrl;
    protected string $apiKey;
    protected ?string $version;
    protected PendingRequest $pendingRequest;
    protected ApiCacheManager $cacheManager;
    
    /**
     * Create a new API client instance
     *
     * @param string $clientName Client identifier
     * @param string $baseUrl Base URL for API requests
     * @param string $apiKey API authentication key
     * @param string|null $version API version
     * @param ApiCacheManager|null $cacheManager Optional cache manager instance
     */
    public function __construct(
        string $clientName,
        string $baseUrl,
        string $apiKey,
        ?string $version = null,
        ?ApiCacheManager $cacheManager = null
    ) {
        // Validate that $clientName only contains alphanumeric characters, hyphens, and underscores
        Helper\validate_identifier($clientName);

        $this->clientName = $clientName;
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->version = $version;
        $this->cacheManager = $cacheManager ?? app(ApiCacheManager::class);
        $this->pendingRequest = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept' => 'application/json',
        ]);
    }

    /**
     * Builds the full URL for an endpoint
     *
     * @param string $endpoint The API endpoint
     * @return string The complete URL
     */
    abstract public function buildUrl(string $endpoint): string;
    
    public function getClientName(): string {
        return $this->clientName;
    }
    
    /**
     * Get the API version
     */
    public function getVersion(): ?string
    {
        return $this->version;
    }
    
    /**
     * Sends an API request
     * 
     * Algorithm:
     * - Build full URL from endpoint
     * - Track request timing
     * - Send HTTP request using Laravel's HTTP client
     * - Return response with timing data
     *
     * @throws \InvalidArgumentException When HTTP method is not supported
     */
    public function sendRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $url = $this->buildUrl($endpoint);
        $startTime = microtime(true);
        $requestData = [];
        
        // Add request capture middleware to pending request
        $request = $this->pendingRequest->withMiddleware(
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
            'HEAD' => $request->head($url, $params),
            'GET' => $request->get($url, $params),
            'POST' => $request->post($url, $params),
            'PUT' => $request->put($url, $params),
            'PATCH' => $request->patch($url, $params),
            'DELETE' => $request->delete($url, $params),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}")
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
     *
     * Algorithm:
     * 1. Generate cache key
     * 2. Check cache
     * 3. Check rate limit
     * 4. Make request if needed
     * 5. Track rate limit usage
     * 6. Store in cache
     * 7. Return response
     *
     * @param string $endpoint API endpoint to call
     * @param array $params Request parameters
     * @param string $method HTTP method (GET, POST, etc.)
     * 
     * @throws RateLimitException When rate limit is exceeded
     * @throws \JsonException When cache key generation fails
     * @throws \InvalidArgumentException When HTTP method is not supported
     * 
     * @return array API response data
     */
    public function sendCachedRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        // Generate cache key
        $cacheKey = $this->cacheManager->generateCacheKey(
            $this->clientName,
            $endpoint,
            $params,
            $method,
            $this->version
        );
        
        // Check cache
        if ($cached = $this->cacheManager->getCachedResponse($this->clientName, $cacheKey)) {
            return $cached;
        }
        
        // Check rate limit
        if (!$this->cacheManager->allowRequest($this->clientName)) {
            throw new RateLimitException(
                $this->clientName,
                $this->cacheManager->getAvailableIn($this->clientName)
            );
        }
        
        // Make the request
        $apiResult = $this->sendRequest($endpoint, $params, $method);
        
        // Increment attempts for the client to track rate limit usage
        $this->cacheManager->incrementAttempts($this->clientName);
        
        // Store in cache
        $this->cacheManager->storeResponse(
            $this->clientName,
            $cacheKey,
            $apiResult,
            $endpoint,
            $this->version
        );
        
        return $apiResult;
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
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        parent::__construct(
            'demo',
            config('api-cache.apis.demo.base_url'),
            config('api-cache.apis.demo.api_key'),
            'v1',
            $cacheManager
        );
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
     * Constructor for OpenAIApiClient
     * 
     * @param ApiCacheManager $cacheManager Optional manager for caching and rate limiting
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        parent::__construct(
            'openai',
            config('api-cache.apis.openai.base_url'),
            config('api-cache.apis.openai.api_key'),
            'v1',
            $cacheManager
        );
    }
    
    /**
     * Builds the full URL for an endpoint
     * 
     * @param string $endpoint The API endpoint to build the URL for
     * @return string The full URL for the endpoint
     */
    public function buildUrl(string $endpoint): string 
    {
        return "{$this->baseUrl}/{$endpoint}";
    }

    /**
     * Get legacy text completions based on prompt parameters
     * 
     * @param string $prompt The prompt to use for the completions
     * @param string $model The model to use for the completions
     * @param int $maxTokens The maximum number of tokens to generate
     * @param int $n The number of completions to generate
     * @param array $additionalParams Additional parameters to include in the request
     * @return array The API response data
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
     * 
     * @param array|string $messages The messages to use for the completions
     * @param string $model The model to use for the completions
     * @param int|null $maxCompletionTokens The maximum number of tokens to generate
     * @param int $n The number of completions to generate
     * @param float $temperature The temperature to use for the completions
     * @param float $topP The top P value to use for the completions
     * @param array $additionalParams Additional parameters to include in the request
     * @return array The API response data
     * @throws \InvalidArgumentException When messages are not properly formatted
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
        // If messages is a string, assume it is a prompt and wrap it in an array of messages
        if (is_string($messages)) {
            $messages = [['role' => 'user', 'content' => $messages]];
        }

        // Ensure all elements in messages are properly formatted with role and content
        foreach ($messages as $message) {
            if (!isset($message['role']) || !isset($message['content'])) {
                throw new \InvalidArgumentException("Invalid message format: each message must have 'role' and 'content' keys");
            }
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
// ApiCacheManager.php
namespace FOfX\ApiCache;

/**
 * Manages caching and rate limiting for API responses.
 * Provides focused methods for cache operations and rate limiting.
 */
class ApiCacheManager
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
     * Check if request is allowed by rate limiter
     *
     * @param string $clientName Client identifier
     * @return bool True if request is allowed
     */
    public function allowRequest(string $clientName): bool
    {
        return $this->rateLimiter->allowRequest($clientName);
    }

    /**
     * Get remaining attempts for the client
     *
     * @param string $clientName Client identifier
     *
     * @return int Remaining attempts
     */
    public function getRemainingAttempts(string $clientName): int
    {
        return $this->rateLimiter->getRemainingAttempts($clientName);
    }

    /**
     * Get seconds until rate limit resets
     *
     * @param string $clientName Client identifier
     * @return int Seconds until available
     */
    public function getAvailableIn(string $clientName): int
    {
        return $this->rateLimiter->getAvailableIn($clientName);
    }

    /**
     * Increment attempts for the client
     *
     * @param string $clientName Client identifier
     */
    public function incrementAttempts(string $clientName): void
    {
        $this->rateLimiter->incrementAttempts($clientName);
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
     *
     * @param string $clientName Client identifier
     * @param string $cacheKey Cache key
     * @param array $apiResult API response data
     * @param string $endpoint The API endpoint
     * @param string|null $version API version
     * @param int|null $ttl Cache TTL in seconds (null for default from config)
     * @throws \Exception When storage fails
     */
    public function storeResponse(
        string $clientName,
        string $cacheKey,
        array $apiResult,
        string $endpoint,
        ?string $version = null,
        ?int $ttl = null
    ): void {
        // If TTL is not provided, use default from config
        if ($ttl === null) {
            $ttl = config("api-cache.apis.{$clientName}.cache_ttl");
        }
        
        /** @var \Illuminate\Http\Client\Response $response */
        $response = $apiResult['response'];
        
        $metadata = [
            'endpoint' => $endpoint,
            'version' => $version,
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
        
        $this->repository->store($clientName, $cacheKey, $metadata, $ttl);
    }
    
    /**
     * Get cached response if available
     *
     * @param string $clientName Client identifier
     * @param string $cacheKey Cache key to look up
     * @return array|null Cached response or null if not found
     */
    public function getCachedResponse(string $clientName, string $cacheKey): ?array
    {
        return $this->repository->get($clientName, $cacheKey);
    }

    /**
     * Normalize parameters for consistent cache keys.
     *
     * Rules:
     *  - Remove null values
     *  - Recursively handle arrays (keeping empty arrays in case they matter)
     *  - Sort keys for consistent ordering
     *  - Forbid objects/resources (throw exception), as they rarely make sense for external APIs
     *  - Include a depth check to prevent infinite recursion or excessive nesting
     *
     * This approach is minimal, avoids special transformations of booleans/strings, 
     * and suits typical JSON-based APIs.
     * 
     * @param array $params Parameters to normalize
     * @param int $depth Current recursion depth
     * 
     * @throws \InvalidArgumentException When encountering unsupported types or exceeding max depth
     * 
     * @return array Normalized parameters
     */
    public function normalizeParams(array $params, int $depth = 0): array 
    {
        // Define a reasonable maximum depth to prevent infinite recursion
        $maxDepth = 20;

        if ($depth > $maxDepth) {
            throw new \InvalidArgumentException(
                "Maximum recursion depth ({$maxDepth}) exceeded in parameters: {$depth}"
            );
        }

        // Filter out nulls first
        $filtered = [];
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $filtered[$key] = $value;
            }
        }

        // Sort keys for stable ordering
        ksort($filtered);

        $normalized = [];
        foreach ($filtered as $key => $value) {
            if (is_array($value)) {
                // Recurse, keeping empty arrays intact
                $normalized[$key] = $this->normalizeParams($value, $depth + 1);
            } elseif (is_scalar($value)) {
                // Keep scalars as-is: bool, int, float, string
                $normalized[$key] = $value;
            } else {
                // Throw on objects, resources, closures, etc.
                $type = gettype($value);
                throw new \InvalidArgumentException("Unsupported parameter type: {$type}");
            }
        }

        return $normalized;
    }
    
    /**
     * Generate a unique cache key for the request.
     * 
     * Format: "{client}.{method}.{endpoint}.{params_hash}.{version}"
     *
     * Algorithm:
     * - Normalize parameters for consistent ordering
     * - Encode normalized parameters to JSON
     * - Generate SHA1 hash of the JSON parameters
     * - Build cache key components with lowercased method
     * - Append version if provided
     * - Concatenate components with dots
     *
     * @param string $clientName API client identifier
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     * @param string $method HTTP method
     * @param string|null $version API version
     * 
     * @throws \JsonException When parameter normalization or JSON encoding fails
     * 
     * @return string Cache key
     */
    public function generateCacheKey(
        string $clientName,
        string $endpoint,
        array $params,
        string $method = 'GET',
        ?string $version = null
    ): string {
        try {
            // Normalize parameters for stable ordering
            $normalizedParams = $this->normalizeParams($params);

            // Encode normalized parameters to JSON
            $jsonParams = json_encode($normalizedParams, JSON_THROW_ON_ERROR);
            $paramsHash = sha1($jsonParams);

            // Build the cache key components
            $components = [
                $clientName,
                strtolower($method),
                ltrim($endpoint, '/'),
                $paramsHash
            ];

            if ($version !== null) {
                $components[] = $version;
            }

            return implode('.', $components);
        } catch (\JsonException $e) {
            throw $e;
        }
    }
}

// RateLimitService.php
namespace FOfX\ApiCache;

use Illuminate\Cache\RateLimiter;

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
        $maxAttempts = config("api-cache.apis.{$clientName}.rate_limit_max_attempts");
        
        return max(0, $this->limiter->remaining($clientName, $maxAttempts));
    }

    /**
     * Get Laravel RateLimiter availableIn() value
     */
    public function getAvailableIn(string $clientName): int
    {
        // If not rate limited, return 0
        $maxAttempts = config("api-cache.apis.{$clientName}.rate_limit_max_attempts");
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
     * - Return compressed string or throw exception
     *
     * @param string $data Raw data to compress
     * @return string Compressed data if enabled, original data if not
     * @throws \RuntimeException When compression fails
     */
    public function compress(string $data): string
    {
        if (!$this->enabled) {
            return $data;
        }
        
        $compressed = gzcompress($data);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress data');
        }
        
        return $compressed;
    }
    
    /**
     * Decompress data
     * 
     * Algorithm:
     * - If compression disabled, return data as-is
     * - Decompress using gzuncompress
     * - Return decompressed string or throw exception
     *
     * @param string $data Raw data to decompress
     * @return string Decompressed data if enabled, original data if not
     * @throws \RuntimeException When decompression fails
     */
    public function decompress(string $data): string
    {
        if (!$this->enabled) {
            return $data;
        }
        
        $decompressed = gzuncompress($data);
        if ($decompressed === false) {
            throw new \RuntimeException('Failed to decompress data');
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
     * Get the table name for the client
     */
    public function getTableName(string $clientName): string
    {
        $suffix = $this->compression->isEnabled() ? '_compressed' : '';
        return "api_cache_{$clientName}_responses{$suffix}";
    }
    
    /**
     * Prepare headers for storage (always array)
     * 
     * @param array|null $headers HTTP headers array
     * @return string|null JSON encoded and optionally compressed headers
     */
    public function prepareHeaders(?array $headers): ?string
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
    public function retrieveHeaders(?string $data): ?array
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
    public function prepareBody(?string $body): ?string
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
     * @throws \InvalidArgumentException When required fields are missing
     */
    public function store(string $clientName, string $key, array $metadata, ?int $ttl = null): void
    {
        $table = $this->getTableName($clientName);
        $expiresAt = $ttl ? now()->addSeconds($ttl) : null;
        
        // Ensure required fields exist
        if (empty($metadata['endpoint']) || empty($metadata['response_body'])) {
            throw new \InvalidArgumentException('Missing required fields, endpoint and response_body are required');
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
            'client' => $clientName,
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
    public function get(string $clientName, string $key): ?array
    {
        $table = $this->getTableName($clientName);
        
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
    public function cleanup(?string $clientName = null): void
    {
        $clientsArray = $clientName
            ? [$clientName]
            : array_keys(config('api-cache.apis'));
        
        foreach ($clientsArray as $clientElement) {
            $this->db->table($this->getTableName($clientElement))
                ->where('expires_at', '<=', now())
                ->delete();
        }
    }
}
```

### Database Migrations

```php
// create_api_cache_demo_responses_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_demo_responses');
    }
};

// create_api_cache_demo_responses_compressed_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_cache_demo_responses_compressed', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('client');
            $table->string('version')->nullable();
            $table->string('endpoint');
            $table->string('base_url')->nullable();
            $table->string('full_url')->nullable();
            $table->string('method')->nullable();
            $table->mediumText('request_headers')->charset('binary')->nullable();
            $table->mediumText('request_body')->charset('binary')->nullable();
            $table->integer('response_status_code')->nullable();
            $table->mediumText('response_headers')->charset('binary')->nullable();
            $table->mediumText('response_body')->charset('binary')->nullable();
            $table->integer('response_size')->nullable();
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
    ],
];
```

### Demo API Server

```php
// demo-api-server.php
<?php

declare(strict_types=1);

// Simple router based on path and method
$path = $_SERVER['PATH_INFO'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'];

// Set JSON content type
header('Content-Type: application/json');

// Validate API key
function validateApiKey(): bool {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/', $authHeader, $matches)) {
        return false;
    }
    $providedKey = $matches[1];
    return $providedKey === 'demo-key';  // Hardcoded for demo purposes
}

// Check API key before processing
if (!validateApiKey()) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

// API version check
if (!str_starts_with($path, '/v1/')) {
    http_response_code(404);
    echo json_encode(['error' => 'API version not found']);
    exit;
}

// Remove version prefix
$path = substr($path, 3);

// Route handling
match(true) {
    // GET /predictions
    $method === 'GET' && $path === '/predictions' => handlePredictions(),
    
    // POST /reports
    $method === 'POST' && $path === '/reports' => handleReports(),
    
    // 404 for everything else
    default => handle404(),
};

// Handler functions
function handlePredictions() {
    // Implementation will go here
}

function handleReports() {
    // Implementation will go here
}

function handle404() {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
```