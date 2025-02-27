<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;
use FOfX\Helper;

class ApiCacheManager
{
    protected CacheRepository $repository;
    protected RateLimitService $rateLimiter;

    public function __construct(CacheRepository $repository, RateLimitService $rateLimiter)
    {
        $this->repository  = $repository;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Get the table name for a client
     *
     * @param string $clientName Client name
     *
     * @return string The table name
     */
    public function getTableName(string $clientName): string
    {
        return $this->repository->getTableName($clientName);
    }

    /**
     * Check if request is allowed by rate limiter
     *
     * @param string $clientName The API client identifier
     *
     * @return bool True if request is allowed, false otherwise
     */
    public function allowRequest(string $clientName): bool
    {
        $allowed = $this->rateLimiter->allowRequest($clientName);

        Log::debug('Rate limit check', [
            'client'  => $clientName,
            'allowed' => $allowed,
        ]);

        return $allowed;
    }

    /**
     * Get remaining attempts for the client
     *
     * @param string $clientName The API client identifier
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
     * @param string $clientName The API client identifier
     *
     * @return int Seconds until rate limit resets
     */
    public function getAvailableIn(string $clientName): int
    {
        return $this->rateLimiter->getAvailableIn($clientName);
    }

    /**
     * Increment attempts for the client
     *
     * @param string $clientName The API client identifier
     * @param int    $amount     The amount to increment by
     */
    public function incrementAttempts(string $clientName, int $amount = 1): void
    {
        $this->rateLimiter->incrementAttempts($clientName, $amount);

        Log::debug('Rate limit attempts incremented', [
            'client' => $clientName,
            'amount' => $amount,
        ]);
    }

    /**
     * Cache the API response
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
     * @param string      $clientName The API client identifier
     * @param string      $cacheKey   Cache key
     * @param array       $apiResult  API response data
     * @param string      $endpoint   The API endpoint
     * @param string|null $version    API version
     * @param int|null    $ttl        Cache TTL in seconds
     */
    public function storeResponse(
        string $clientName,
        string $cacheKey,
        array $apiResult,
        string $endpoint,
        ?string $version = null,
        ?int $ttl = null
    ): void {
        // Use default TTL from config if not provided
        if ($ttl === null) {
            $ttl = config("api-cache.apis.{$clientName}.cache_ttl");
        }

        /** @var \Illuminate\Http\Client\Response $response */
        $response = $apiResult['response'];

        $metadata = [
            'endpoint'             => $endpoint,
            'version'              => $version,
            'base_url'             => $apiResult['request']['base_url'],
            'full_url'             => $apiResult['request']['full_url'],
            'method'               => $apiResult['request']['method'],
            'request_headers'      => $apiResult['request']['headers'],
            'request_body'         => $apiResult['request']['body'],
            'response_headers'     => $response->headers(),
            'response_body'        => $response->body(),
            'response_status_code' => $response->status(),
            'response_size'        => strlen($response->body()),
            'response_time'        => $apiResult['response_time'],
        ];

        $this->repository->store($clientName, $cacheKey, $metadata, $ttl);

        Log::debug('Stored API response in cache', [
            'client' => $clientName,
            'key'    => $cacheKey,
            'ttl'    => $ttl,
        ]);
    }

    /**
     * Get cached response if available
     *
     * @param string $clientName The API client identifier
     * @param string $cacheKey   Cache key to look up
     *
     * @return array|null Cached response or null if not found
     */
    public function getCachedResponse(string $clientName, string $cacheKey): ?array
    {
        $cached = $this->repository->get($clientName, $cacheKey);
        if (!$cached) {
            return null;
        }

        // Reconstruct Response object
        $response = new \Illuminate\Http\Client\Response(
            new \GuzzleHttp\Psr7\Response(
                $cached['response_status_code'],
                $cached['response_headers'],
                $cached['response_body']
            )
        );

        // Return in same format as fresh responses
        // response_time is set to 0 because it's cached
        return [
            'request' => [
                'base_url' => $cached['base_url'],
                'full_url' => $cached['full_url'],
                'method'   => $cached['method'],
                'headers'  => $cached['request_headers'],
                'body'     => $cached['request_body'],
            ],
            'response'      => $response,
            'response_time' => 0,
        ];
    }

    /**
     * Normalize parameters for consistent cache keys.
     *
     * Rules:
     *  - Remove null values
     *  - Recursively handle arrays (keeping empty arrays in case they matter)
     *  - Sort keys for consistent ordering
     *  - Forbid objects/resources (throw exception)
     *  - Include a depth check to prevent infinite recursion
     *
     * @param array $params Parameters to normalize
     * @param int   $depth  Current recursion depth
     *
     * @throws \InvalidArgumentException When encountering unsupported types or exceeding max depth
     *
     * @return array Normalized parameters
     */
    public function normalizeParams(array $params, int $depth = 0): array
    {
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
                // Recurse
                $normalized[$key] = $this->normalizeParams($value, $depth + 1);
            } elseif (is_scalar($value)) {
                // Keep scalars as-is: bool, int, float, string
                $normalized[$key] = $value;
            } else {
                // Throw on objects, resources, closures, etc.
                $type = gettype($value);
                Log::warning('Unsupported parameter type', [
                    'type' => $type,
                    'key'  => $key,
                ]);

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
     * @param string      $clientName API client identifier
     * @param string      $endpoint   API endpoint
     * @param array       $params     Request parameters
     * @param string      $method     HTTP method
     * @param string|null $version    API version
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
        // Validate that $clientName only contains alphanumeric characters, hyphens, and underscores
        Helper\validate_identifier($clientName);

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
                $paramsHash,
            ];

            if ($version !== null) {
                $components[] = $version;
            }

            $key = implode('.', $components);

            Log::debug('Generated cache key', [
                'client' => $clientName,
                'key'    => $key,
            ]);

            return $key;
        } catch (\JsonException $e) {
            Log::error('Failed to generate cache key', [
                'client' => $clientName,
                'error'  => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
