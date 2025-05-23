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

class BaseApiClient
{
    protected string $clientName;
    protected string $baseUrl;
    protected string $apiKey;
    protected ?string $version;

    protected PendingRequest $pendingRequest;
    protected ?ApiCacheManager $cacheManager;
    
    /**
     * Create a new API client instance
     *
     * @param string $clientName Client identifier
     * @param string|null $baseUrl Base URL for API requests
     * @param string|null $apiKey API authentication key
     * @param string|null $version API version
     * @param ApiCacheManager|null $cacheManager Optional cache manager instance
     */
    public function __construct(
        string $clientName,
        ?string $baseUrl = null,
        ?string $apiKey = null,
        ?string $version = null,
        ?ApiCacheManager $cacheManager = null
    ) {
        // Validate that $clientName only contains alphanumeric characters, hyphens, and underscores
        Helper\validate_identifier($clientName);

        $this->clientName = $clientName;
        $this->baseUrl = $baseUrl ?? config("api-cache.apis.{$this->clientName}.base_url");
        $this->apiKey = $apiKey ?? config("api-cache.apis.{$this->clientName}.api_key");
        $this->version = $version ?? config("api-cache.apis.{$this->clientName}.version");
        
        $this->cacheManager = $this->resolveCacheManager($cacheManager);
        $this->pendingRequest = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept' => 'application/json',
        ]);
    }

    /**
     * Get the client name
     *
     * @return string The client name
     */
    public function getClientName(): string
    {
        return $this->clientName;
    }

    /**
     * Get the base URL. Will be WSL aware if enabled.
     *
     * @return string The base URL
     */
    public function getBaseUrl(): string
    {
        if ($this->wslEnabled) {
            return Helper\wsl_url($this->baseUrl);
        }

        return $this->baseUrl;
    }

    /**
     * Get the API key
     *
     * @return string|null The API key
     */
    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    /**
     * Get the API version
     *
     * @return string|null The API version
     */
    public function getVersion(): ?string
    {
        return $this->version;
    }

    /**
     * Get the table name for this client
     *
     * @return string The table name
     */
    public function getTableName(string $clientName): string
    {
        return $this->cacheManager->getTableName($clientName);
    }

    /**
     * Get the current request timeout in seconds
     *
     * @return int|null Timeout in seconds, or null if no timeout set
     */
    public function getTimeout(): ?int
    {
        return $this->pendingRequest->getOptions()['timeout'] ?? null;
    }

    /**
     * Set the client name
     *
     * @param string $clientName The client name
     *
     * @return self
     */
    public function setClientName(string $clientName): self
    {
        $this->clientName = $clientName;

        return $this;
    }

    /**
     * Set the base URL
     *
     * @param string $baseUrl The base URL
     *
     * @return self
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    /**
     * Set the API key
     *
     * @param string $apiKey The API key
     *
     * @return self
     */
    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Set the API version
     *
     * @param string $version The API version
     *
     * @return self
     */
    public function setVersion(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Set the WSL enabled flag
     *
     * @param bool $enabled Whether WSL aware URL is enabled
     *
     * @return self
     */
    public function setWslEnabled(bool $enabled = true): self
    {
        $this->wslEnabled = $enabled;

        return $this;
    }

    /**
     * Set request timeout in seconds
     *
     * @param int $seconds Timeout in seconds
     *
     * @return self
     */
    public function setTimeout(int $seconds): self
    {
        $this->pendingRequest->timeout($seconds);

        return $this;
    }

    public function isWslEnabled(): bool
    {
        return $this->wslEnabled;
    }

    /**
     * Builds the full URL for an endpoint. Will be WSL aware if enabled.
     *
     * @param string $endpoint The API endpoint (with or without leading slash)
     *
     * @return string The complete URL
     */
    public function buildUrl(string $endpoint): string
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        if ($this->wslEnabled) {
            $url = Helper\wsl_url($url);
        }

        return $url;
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
        $url         = $this->buildUrl($endpoint);
        $startTime   = microtime(true);
        $requestData = [];

        // Add request capture middleware to pending request
        $request = $this->pendingRequest->withMiddleware(
            function (callable $handler) use (&$requestData) {
                return function (\Psr\Http\Message\RequestInterface $request, array $options) use ($handler, &$requestData) {
                    // Capture full request details
                    $requestData['method']  = $request->getMethod();
                    $requestData['url']     = (string) $request->getUri();
                    $requestData['headers'] = $request->getHeaders();
                    $requestData['body']    = (string) $request->getBody();

                    // Pass both request and options to next handler
                    return $handler($request, $options);
                };
            }
        );

        /** @var \Illuminate\Http\Client\Response $response */
        $response = match($method) {
            'HEAD'   => $request->head($url, $params),
            'GET'    => $request->get($url, $params),
            'POST'   => $request->post($url, $params),
            'PUT'    => $request->put($url, $params),
            'PATCH'  => $request->patch($url, $params),
            'DELETE' => $request->delete($url, $params),
            default  => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}")
        };

        $responseTime = microtime(true) - $startTime;

        return [
            'request' => [
                'base_url' => $this->baseUrl,
                'full_url' => $requestData['url'],
                'method'   => $requestData['method'],
                'headers'  => $requestData['headers'],
                'body'     => $requestData['body'],
            ],
            'response'             => $response,
            'response_status_code' => $response->status(),
            'response_size'        => strlen($response->body()),
            'response_time'        => $responseTime,
            'is_cached'            => false,
        ];
    }

    /**
     * Send request with caching and rate limiting
     *
     * Algorithm:
     * - Generate cache key
     * - Check cache
     * - Check rate limit
     * - Make request if needed
     * - Track rate limit usage
     * - Store in cache
     * - Return response
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
        // Make sure $this->cacheManager is not null
        if ($this->cacheManager === null) {
            throw new \RuntimeException('Cache manager is not initialized');
        }
        
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

    /**
     * Get the health endpoint of the API
     *
     * @return array The health endpoint response
     */
    public function getHealth(): array
    {
        return $this->sendRequest('health');
    }

    /**
     * Resolve the cache manager
     *
     * @param ApiCacheManager|null $cacheManager Optional cache manager instance
     *
     * @return ApiCacheManager|null The resolved cache manager
     */
    protected function resolveCacheManager(?ApiCacheManager $cacheManager): ?ApiCacheManager
    {
        if ($cacheManager !== null) {
            return $cacheManager;
        }
        
        return app(ApiCacheManager::class);
    }
}
```

### Child API Clients

These child classes extend the BaseApiClient class and provide specific implementations for different APIs.

In array_merge(), original parameters are passed after $additionalParams. This allows them to override $additionalParams
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
    public function predictions(
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
     * Get reports based on type and source
     */
    public function reports(
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
     * Get the table name for a client
     *
     * @param string $clientName Client identifier
     * @return string The table name
     */
    public function getTableName(string $clientName): string
    {
        return $this->repository->getTableName($clientName);
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
     * @param int $amount Amount to increment by (default 1)
     */
    public function incrementAttempts(string $clientName, int $amount = 1): void
    {
        $this->rateLimiter->incrementAttempts($clientName, $amount);
    }

    /**
     * Cache the response
     * 
     * Algorithm:
     * - Prepare response metadata (based on Laravel HTTP client's response):
     *   - response_headers (from $apiResult['response']->headers())
     *   - response_body (from $apiResult['response']->body())
     *   - response_status_code (from $apiResult['response']->status())
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
            'response_headers' => $response->headers(),
            'response_body' => $response->body(),
            'response_status_code' => $response->status(),
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
     * - Increment by amount (default 1)
     */
    public function incrementAttempts(string $clientName, int $amount = 1): void 
    {
        $decaySeconds = config("api-cache.apis.{$clientName}.rate_limit_decay_seconds");
        $this->limiter->increment($clientName, $decaySeconds, $amount);
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
    /**
     * Check if compression is enabled
     * 
     * @param string $clientName Client name
     * @return bool True if compression is enabled, false otherwise
     */
    public function isEnabled(string $clientName): bool
    {
        return config("api-cache.apis.{$clientName}.compression_enabled");
    }
    
    /**
     * Compress data
     * 
     * Algorithm:
     * - If compression disabled, return data as-is
     * - Compress using gzcompress
     * - Return compressed string or throw exception
     *
     * @param string $clientName Client name
     * @param string $data Raw data to compress
     * @return string Compressed data if enabled, original data if not
     * @throws \RuntimeException When compression fails
     */
    public function compress(string $clientName, string $data): string
    {
        if (!$this->isEnabled($clientName)) {
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
     * @param string $clientName Client name
     * @param string $data Raw data to decompress
     * @return string Decompressed data if enabled, original data if not
     * @throws \RuntimeException When decompression fails
     */
    public function decompress(string $clientName, string $data): string
    {
        if (!$this->isEnabled($clientName)) {
            return $data;
        }
        
        $decompressed = @gzuncompress($data);
        if ($decompressed === false) {
            throw new \RuntimeException('Failed to decompress data');
        }        

        return $decompressed;
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
     * 
     * @param string $clientName Client name
     * @return string The table name
     */
    public function getTableName(string $clientName): string
    {
        $suffix = $this->compression->isEnabled($clientName) ? '_compressed' : '';
        return "api_cache_{$clientName}_responses{$suffix}";
    }
    
    /**
     * Prepare headers for storage (always array)
     * 
     * @param string $clientName Client name
     * @param array|null $headers HTTP headers array
     * @return string|null JSON encoded and optionally compressed headers
     */
    public function prepareHeaders(string $clientName, ?array $headers): ?string
    {
        if ($headers === null) {
            return null;
        }
        
        $encoded = json_encode($headers);
        return $this->compression->isEnabled($clientName)
            ? $this->compression->compress($clientName, $encoded)
            : $encoded;
    }

    /**
     * Retrieve headers from storage (always array)
     * 
     * @param string $clientName Client name
     * @param string|null $data Stored header data
     * @return array|null Decoded headers array
     */
    public function retrieveHeaders(string $clientName, ?string $data): ?array
    {
        if ($data === null) {
            return null;
        }
        
        $raw = $this->compression->isEnabled($clientName)
            ? $this->compression->decompress($clientName, $data)
            : $data;
            
        return json_decode($raw, true);
    }

    /**
     * Prepare body for storage (always string)
     * 
     * @param string $clientName Client name
     * @param string|null $body Raw body content
     * @return string|null Optionally compressed body
     */
    public function prepareBody(string $clientName, ?string $body): ?string
    {
        if ($body === null) {
            return null;
        }
        
        return $this->compression->isEnabled($clientName)
            ? $this->compression->compress($clientName, $body)
            : $body;
    }

    /**
     * Retrieve body from storage (always string)
     * 
     * @param string $clientName Client name
     * @param string|null $data Stored body data
     * @return string|null Raw body content
     */
    protected function retrieveBody(string $clientName, ?string $data): ?string
    {
        if ($data === null) {
            return null;
        }
        
        return $this->compression->isEnabled($clientName)
            ? $this->compression->decompress($clientName, $data)
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
     *   - response_headers: Array of headers
     *   - response_body: Raw response body
     *   - response_status_code: HTTP status code
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
        $now = now();
        if ($ttl) {
            $expiresAt = $now->copy()->addSeconds($ttl);
        } else {
            $expiresAt = null;
        }
        
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
            'response_headers' => null,
            'response_status_code' => null,
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
            'response_headers' => $this->prepareHeaders($metadata['response_headers']),
            'response_body' => $this->prepareBody($metadata['response_body']),
            'response_status_code' => $metadata['response_status_code'],
            'response_size' => $metadata['response_size'],
            'response_time' => $metadata['response_time'],
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
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
            'response_headers' => $this->retrieveHeaders($data->response_headers),
            'response_body' => $this->retrieveBody($data->response_body),
            'response_status_code' => $data->response_status_code,
            'response_size' => $data->response_size,
            'response_time' => $data->response_time,
            'expires_at' => $data->expires_at,
        ];
    }
    
    /**
     * Delete expired responses
     * 
     * Algorithm:
     * - If client specified, delete only that client's expired responses
     * - Otherwise delete all expired responses from all clients
     */
    public function deleteExpired(?string $clientName = null): void
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
            $table->mediumText('response_headers')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->integer('response_status_code')->nullable();
            $table->integer('response_size')->nullable();
            $table->double('response_time')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Indexes
            // In MySQL, we might hit the 64 character limit for indexes. So manually set the index name.
            $table->index(['client', 'endpoint', 'version'], 'client_endpoint_version_index');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_cache_demo_responses');
    }
};

// create_api_cache_demo_responses_compressed_table.php
// Must use charset('binary') as Laravel does not support mediumBlob
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
            $table->binary('request_headers')->nullable();
            $table->binary('request_body')->nullable();
            $table->binary('response_headers')->nullable();
            $table->binary('response_body')->nullable();
            $table->integer('response_status_code')->nullable();
            $table->integer('response_size')->nullable();
            $table->double('response_time')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Indexes
            // In MySQL, we might hit the 64 character limit for indexes. So manually set the index name.
            $table->index(['client', 'endpoint', 'version'], 'client_endpoint_version_index');
            $table->index('expires_at');
        });

        // Alter the request and response headers and body columns
        // For MySQL use MEDIUMBLOB, for SQL Server use VARBINARY(MAX)
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            Schema::getConnection()->statement("
                ALTER TABLE api_cache_demo_responses_compressed
                MODIFY request_headers MEDIUMBLOB,
                MODIFY request_body MEDIUMBLOB,
                MODIFY response_headers MEDIUMBLOB,
                MODIFY response_body MEDIUMBLOB
            ");
        } elseif ($driver === 'sqlsrv') {
            Schema::getConnection()->statement("
                ALTER TABLE api_cache_demo_responses_compressed
                ALTER COLUMN request_headers VARBINARY(MAX),
                ALTER COLUMN request_body VARBINARY(MAX),
                ALTER COLUMN response_headers VARBINARY(MAX),
                ALTER COLUMN response_body VARBINARY(MAX)
            ");
        }
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
            'api_key' => env('DEMO_API_KEY', 'demo-api-key'),
            'base_url' => env('DEMO_BASE_URL', 'http://localhost:8000/demo-api-server.php/v1'),
            'version' => env('DEMO_VERSION', null),
            'cache_ttl' => env('DEMO_CACHE_TTL', null),
            'compression_enabled' => env('DEMO_COMPRESSION_ENABLED', false),
            'rate_limit_max_attempts' => env('DEMO_RATE_LIMIT_MAX_ATTEMPTS', 1000),
            'rate_limit_decay_seconds' => env('DEMO_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY', null),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'version' => env('OPENAI_VERSION', null),
            'cache_ttl' => env('OPENAI_CACHE_TTL', null),
            'compression_enabled' => env('OPENAI_COMPRESSION_ENABLED', false),
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
    return $providedKey === 'demo-api-key';  // Hardcoded for demo purposes
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