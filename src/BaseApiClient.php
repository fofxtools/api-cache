<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\PendingRequest;
use FOfX\Helper;

class BaseApiClient
{
    protected string $clientName;
    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?string $version;
    protected bool $wslEnabled = false;
    protected bool $useCache   = true;

    protected PendingRequest $pendingRequest;
    protected ?ApiCacheManager $cacheManager;

    /**
     * Create a new API client instance
     *
     * @param string               $clientName   Client identifier
     * @param string|null          $baseUrl      Base URL for API requests
     * @param string|null          $apiKey       API authentication key
     * @param string|null          $version      API version
     * @param ApiCacheManager|null $cacheManager Optional cache manager instance
     */
    public function __construct(
        string $clientName = 'default',
        ?string $baseUrl = null,
        ?string $apiKey = null,
        ?string $version = null,
        ?ApiCacheManager $cacheManager = null
    ) {
        // Validate that $clientName only contains alphanumeric characters, hyphens, and underscores
        Helper\validate_identifier($clientName);

        $this->clientName = $clientName;
        $this->baseUrl    = $baseUrl ?? config("api-cache.apis.{$this->clientName}.base_url");
        $this->apiKey     = $apiKey ?? config("api-cache.apis.{$this->clientName}.api_key");
        $this->version    = $version ?? config("api-cache.apis.{$this->clientName}.version");

        $this->cacheManager = $this->resolveCacheManager($cacheManager);

        // Set the auth headers with cookies disabled
        // Cookies may cause issues with the cache key being unique each time
        $this->pendingRequest = Http::withHeaders($this->getAuthHeaders())->withOptions(['cookies' => false]);

        Log::debug('API client initialized', [
            'client'   => $this->clientName,
            'base_url' => $this->baseUrl,
            'version'  => $this->version,
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
     * @param string|null $clientName The client name
     *
     * @return string The table name
     */
    public function getTableName(?string $clientName = null): string
    {
        if ($clientName === null) {
            $clientName = $this->clientName;
        }

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
     * Get the use cache flag
     *
     * @return bool The use cache flag
     */
    public function getUseCache(): bool
    {
        return $this->useCache;
    }

    /**
     * Get authentication headers for the API request
     *
     * Child classes can override this to provide their own auth headers
     *
     * @return array Authentication headers
     */
    public function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Get authentication parameters for the API request
     *
     * Child classes can override this to provide their own auth parameters
     *
     * @return array Authentication parameters
     */
    public function getAuthParams(): array
    {
        return [];
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

    /**
     * Set the use cache flag
     *
     * @param bool $useCache Whether to use cache
     *
     * @return self
     */
    public function setUseCache(bool $useCache): self
    {
        $this->useCache = $useCache;

        return $this;
    }

    public function isWslEnabled(): bool
    {
        return $this->wslEnabled;
    }

    /**
     * Clear the rate limit for the client
     */
    public function clearRateLimit(): void
    {
        $this->cacheManager->clearRateLimit($this->clientName);
    }

    /**
     * Builds the full URL for an endpoint. Will be WSL aware if enabled.
     *
     * @param string      $endpoint   The API endpoint (with or without leading slash)
     * @param string|null $pathSuffix Optional path suffix to append to the URL
     *
     * @return string The complete URL
     */
    public function buildUrl(string $endpoint, ?string $pathSuffix = null): string
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        if ($pathSuffix !== null) {
            $url .= '/' . ltrim($pathSuffix, '/');
        }

        if ($this->wslEnabled) {
            $url = Helper\wsl_url($url);
        }

        Log::debug('Built URL for API request', [
            'client'      => $this->clientName,
            'endpoint'    => $endpoint,
            'path_suffix' => $pathSuffix,
            'base_url'    => $this->baseUrl,
            'url'         => $url,
            'wsl_enabled' => $this->wslEnabled,
        ]);

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

        Log::debug('Sending API request', [
            'client'   => $this->clientName,
            'method'   => $method,
            'endpoint' => $endpoint,
            'url'      => $url,
        ]);

        // Merge auth params with request params
        $paramsWithAuth = array_merge($this->getAuthParams(), $params);

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
            'HEAD'   => $request->head($url, $paramsWithAuth),
            'GET'    => $request->get($url, $paramsWithAuth),
            'POST'   => $request->post($url, $paramsWithAuth),
            'PUT'    => $request->put($url, $paramsWithAuth),
            'PATCH'  => $request->patch($url, $paramsWithAuth),
            'DELETE' => $request->delete($url, $paramsWithAuth),
            default  => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}")
        };

        $responseTime = microtime(true) - $startTime;

        Log::debug('API request completed', [
            'client'        => $this->clientName,
            'status'        => $response->status(),
            'response_time' => round($responseTime, 3),
        ]);

        // Return original params, request data, response data, and whether the cache was used or not
        return [
            'params'  => $params,
            'request' => [
                'base_url'   => $this->baseUrl,
                'full_url'   => $requestData['url'],
                'method'     => $requestData['method'],
                'attributes' => null,
                'headers'    => $requestData['headers'],
                'body'       => $requestData['body'],
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
     * - Check cache (if caching is enabled)
     * - Check rate limit
     * - Make request if needed
     * - Track rate limit usage
     * - Store in cache (if caching is enabled and request was successful)
     * - Return response
     *
     * @param string      $endpoint   API endpoint to call
     * @param array       $params     Request parameters
     * @param string      $method     HTTP method (GET, POST, etc.)
     * @param string|null $attributes Additional attributes to store with the response
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @throws RateLimitException        When rate limit is exceeded. Or when cache manager is not initialized.
     * @throws \JsonException            When cache key generation fails
     * @throws \InvalidArgumentException When HTTP method is not supported
     *
     * @return array API response data
     */
    public function sendCachedRequest(string $endpoint, array $params = [], string $method = 'GET', ?string $attributes = null, int $amount = 1): array
    {
        // Make sure $this->cacheManager is not null
        if ($this->cacheManager === null) {
            throw new \RuntimeException('Cache manager is not initialized');
        }

        Log::debug('Processing cached request', [
            'client'   => $this->clientName,
            'endpoint' => $endpoint,
            'method'   => $method,
        ]);

        // Generate cache key
        $cacheKey = $this->cacheManager->generateCacheKey(
            $this->clientName,
            $endpoint,
            $params,
            $method,
            $this->version
        );

        // Check cache
        if (!$this->useCache) {
            Log::debug('Caching disabled for this request', [
                'client'   => $this->clientName,
                'endpoint' => $endpoint,
                'method'   => $method,
            ]);
        } elseif ($cached = $this->cacheManager->getCachedResponse($this->clientName, $cacheKey)) {
            Log::debug('Cache hit', [
                'client'    => $this->clientName,
                'endpoint'  => $endpoint,
                'method'    => $method,
                'cache_key' => $cacheKey,
            ]);

            return $cached;
        } else {
            Log::debug('Cache miss', [
                'client'    => $this->clientName,
                'endpoint'  => $endpoint,
                'method'    => $method,
                'cache_key' => $cacheKey,
            ]);
        }

        // Check rate limit
        if (!$this->cacheManager->allowRequest($this->clientName)) {
            $availableIn = $this->cacheManager->getAvailableIn($this->clientName);
            Log::warning('Rate limit exceeded', [
                'client'       => $this->clientName,
                'available_in' => $availableIn,
            ]);

            throw new RateLimitException($this->clientName, $availableIn);
        }

        // Make the request
        $apiResult = $this->sendRequest($endpoint, $params, $method);

        // Increment attempts for the client to track rate limit usage
        $this->cacheManager->incrementAttempts($this->clientName, $amount);

        // Get the TTL from the config
        $ttl = config("api-cache.apis.{$this->clientName}.cache_ttl");

        // Trim attributes to 255 characters if not null, for Laravel string column limit
        $trimmedAttributes = $attributes === null ? null : mb_substr($attributes, 0, 255);

        // Store in cache if response is successful
        if (!$this->useCache) {
            Log::debug('Caching disabled for this request', [
                'client'   => $this->clientName,
                'endpoint' => $endpoint,
                'method'   => $method,
            ]);
        } elseif ($apiResult['response']->successful()) {
            $this->cacheManager->storeResponse(
                $this->clientName,
                $cacheKey,
                $params,
                $apiResult,
                $endpoint,
                $this->version,
                $ttl,
                $trimmedAttributes
            );
            Log::debug('Cache stored', [
                'client'    => $this->clientName,
                'endpoint'  => $endpoint,
                'method'    => $method,
                'cache_key' => $cacheKey,
            ]);
        } else {
            Log::warning('Failed to store API response in cache', [
                'client'           => $this->clientName,
                'endpoint'         => $endpoint,
                'version'          => $this->version,
                'cache_key'        => $cacheKey,
                'status_code'      => $apiResult['response']->status(),
                'response_headers' => $apiResult['response']->headers(),
                'response_body'    => $apiResult['response']->body(),
            ]);
        }

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

        // Instead of using factory, resolve from container
        return app(ApiCacheManager::class);
    }
}
