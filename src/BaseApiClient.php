<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * @param string               $clientName   Client identifier
     * @param string               $baseUrl      Base URL for API requests
     * @param string               $apiKey       API authentication key
     * @param string|null          $version      API version
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

        $this->clientName     = $clientName;
        $this->baseUrl        = $baseUrl;
        $this->apiKey         = $apiKey;
        $this->version        = $version;
        $this->cacheManager   = $cacheManager ?? app(ApiCacheManager::class);
        $this->pendingRequest = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept'        => 'application/json',
        ]);

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
     * Get the API version
     *
     * @return string|null The API version
     */
    public function getVersion(): ?string
    {
        return $this->version;
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
     * Builds the full URL for an endpoint
     *
     * @param string $endpoint The API endpoint
     *
     * @return string The complete URL
     */
    abstract public function buildUrl(string $endpoint): string;

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

        Log::debug('API request completed', [
            'client'        => $this->clientName,
            'status'        => $response->status(),
            'response_time' => round($responseTime, 3),
        ]);

        return [
            'request' => [
                'base_url' => $this->baseUrl,
                'full_url' => $requestData['url'],
                'method'   => $requestData['method'],
                'headers'  => $requestData['headers'],
                'body'     => $requestData['body'],
            ],
            'response'      => $response,
            'response_time' => $responseTime,
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
     * @param array  $params   Request parameters
     * @param string $method   HTTP method (GET, POST, etc.)
     *
     * @throws RateLimitException        When rate limit is exceeded
     * @throws \JsonException            When cache key generation fails
     * @throws \InvalidArgumentException When HTTP method is not supported
     *
     * @return array API response data
     */
    public function sendCachedRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
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
        if ($cached = $this->cacheManager->getCachedResponse($this->clientName, $cacheKey)) {
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
        $this->cacheManager->incrementAttempts($this->clientName);

        // Store in cache if response is successful
        if ($apiResult['response']->successful()) {
            $this->cacheManager->storeResponse(
                $this->clientName,
                $cacheKey,
                $apiResult,
                $endpoint,
                $this->version
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
}
