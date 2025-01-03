<?php

namespace FOfX\ApiCache\Support;

use FOfX\ApiCache\ApiClients\BaseApiClient;
use FOfX\ApiCache\Exceptions\RateLimitException;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Handles API request caching and rate limiting.
 * Decorates API clients with caching and rate limiting functionality.
 */
class RequestHandler
{
    protected BaseApiClient $client;
    protected array $config;

    /**
     * Create a new request handler instance.
     *
     * @param BaseApiClient $client The API client to handle requests for
     * @param array         $config Configuration for caching and rate limiting
     */
    public function __construct(BaseApiClient $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * Send an API request with caching and rate limiting.
     *
     * @param string $clientName Name of the client (for config lookup)
     * @param string $method     HTTP method
     * @param string $endpoint   API endpoint
     * @param array  $options    Additional request options
     *
     * @throws RateLimitException When rate limit is exceeded
     *
     * @return array API response
     */
    public function send(string $clientName, string $method, string $endpoint, array $options = []): array
    {
        // Build full URL first
        $fullUrl = $this->client->buildUrl($endpoint);

        // Check rate limits
        $this->checkRateLimit($clientName, $fullUrl);

        // Try cache first
        if ($cached = $this->getFromCache($clientName, $fullUrl, $method, $options)) {
            Log::debug('Cache hit', [
                'cached_response' => $cached,
            ]);

            return $cached;
        }

        // Make the request
        Log::debug('Making request', [
            'method'   => $method,
            'endpoint' => $endpoint,
            'options'  => $options,
        ]);
        $response = $this->client->request($method, $endpoint, $options);
        Log::debug('Response received', [
            'response' => $response,
        ]);

        // Debug
        Log::debug('Response details', [
            'response_size'    => strlen($response['response']['body']),
            'response_preview' => substr($response['response']['body'], 0, 100),
        ]);

        // Cache the response
        $this->cacheResponse($clientName, $fullUrl, $method, $response, $options);

        // Update rate limit
        $this->updateRateLimit($clientName, $fullUrl);

        return $response;
    }

    /**
     * Check if request would exceed rate limits.
     *
     * @param string $client Name of the client (for config lookup)
     * @param string $url    Full URL for the request
     *
     * @throws RateLimitException
     *
     * @return void
     */
    protected function checkRateLimit(string $client, string $url): void
    {
        $endpoint    = parse_url($url, PHP_URL_PATH);
        $windowSize  = (int)($this->config['clients'][$client]['rate_limits']['window_size'] ?? 60);
        $maxRequests = (int)($this->config['clients'][$client]['rate_limits']['max_requests'] ?? 60);

        // Get current window
        $currentWindow = DB::table($this->client->getRateLimitTableName())
            ->where('client', $client)
            ->where('endpoint', $endpoint)
            ->where('status', 'active')
            ->where('window_start', '>', now()->subSeconds($windowSize))
            ->first();

        // Get total count in window
        $count = DB::table($this->client->getRateLimitTableName())
            ->where('client', $client)
            ->where('endpoint', $endpoint)
            ->where('status', 'active')
            ->where('window_start', '>', now()->subSeconds($windowSize))
            ->sum('window_request_count');

        if ($count >= $maxRequests) {
            $retryAfter = $windowSize;
            if ($currentWindow) {
                $retryAfter = (int)(
                    $windowSize - now()->diffInSeconds(Carbon::parse($currentWindow->window_start))
                );
            }

            throw (new RateLimitException("Rate limit exceeded for {$client}"))
                ->setRetryAfter($retryAfter)
                ->setContext([
                    'client'        => $client,
                    'endpoint'      => $endpoint,
                    'window_start'  => $currentWindow ? $currentWindow->window_start : null,
                    'current_count' => $count,
                    'max_requests'  => $maxRequests,
                ]);
        }
    }

    /**
     * Try to get cached response for this request.
     *
     * @param string $client  Name of the client (for config lookup)
     * @param string $url     Full URL for the request
     * @param string $method  HTTP method
     * @param array  $options Request options
     *
     * @return array|null Cached response or null if not found
     */
    protected function getFromCache(string $client, string $url, string $method, array $options = []): ?array
    {
        $key = $this->generateCacheKey($client, $url, $method, $options);

        $cached = DB::table($this->client->getResponseTableName())
            ->where('client', $client)
            ->where('key', $key)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($cached) {
            // Handle compressed vs raw response
            $body = $cached->response_body_compressed
                ? gzuncompress($cached->response_body_compressed)
                : $cached->response_body_raw;

            return [
                'request' => [
                    'headers' => json_decode($cached->request_headers, true) ?? [],
                    'body'    => $cached->request_body,
                ],
                'response' => [
                    'statusCode' => $cached->response_status_code,
                    'headers'    => $cached->response_headers,
                    'body'       => $body,
                ],
                'response_time'  => $cached->response_time,
                'from_cache'     => true,
                'was_compressed' => !empty($cached->response_body_compressed),
            ];
        }

        return null;
    }

    /**
     * Cache a successful API response.
     *
     * @param string $client   Name of the client (for config lookup)
     * @param string $url      Full URL for the request
     * @param string $method   HTTP method
     * @param array  $response API response
     * @param array  $options  Request options
     *
     * @return void
     */
    protected function cacheResponse(string $client, string $url, string $method, array $response, array $options = []): void
    {
        // Skip caching if response is a server error
        if ($response['response']['statusCode'] >= 500) {
            Log::error('Skipping caching of response due to server error', [
                'response' => $response,
            ]);

            return;
        }

        // Get cache TTL from config
        $ttl = $this->config['clients'][$client]['cache_ttl'] ?? 3600;

        // Build cache key
        $key = $this->generateCacheKey($client, $url, $method, $options);

        // Store in cache
        $this->storeInCache($client, $key, $url, $method, $options, $response);
    }

    /**
     * Store response data in cache with proper endpoint handling.
     *
     * @param string $client   Name of the client (for config lookup)
     * @param string $key      Cache key
     * @param string $url      Full URL for the request
     * @param string $method   HTTP method
     * @param array  $options  Request options
     * @param array  $response API response
     *
     * @return void
     */
    protected function storeInCache(string $client, string $key, string $url, string $method, array $options, array $response): void
    {
        // Extract just the endpoint part from the full path
        $parsedUrl = parse_url($url);
        $path      = $parsedUrl['path'] ?? $url;
        $endpoint  = $this->client->cleanEndpointPath($path);

        // Get cache TTL from config
        $ttl       = $this->config['clients'][$client]['cache_ttl'] ?? 3600;
        $expiresAt = $ttl < 0 ? null : now()->addSeconds($ttl);

        $body = is_array($response['response']['body'])
            ? json_encode($response['response']['body'])
            : $response['response']['body'];

        // Calculate sizes before any modifications
        $originalSize = strlen($body);
        Log::debug('Original response size', [
            'size'    => $originalSize,
            'preview' => substr($body, 0, 100),
        ]);

        // Handle compression if enabled
        $compressed = null;
        if ($this->config['clients'][$client]['compression']['enabled'] ?? false) {
            $level          = $this->config['clients'][$client]['compression']['level'] ?? 6;
            $compressed     = gzcompress($body, $level);
            $compressedSize = strlen($compressed);
            $body           = null;  // Don't store raw when compressed

            Log::debug('Compression details', [
                'original_size'     => $originalSize,
                'compressed_size'   => $compressedSize,
                'compression_ratio' => round(($compressedSize / $originalSize) * 100, 1) . '%',
            ]);
        }

        // Extract client-specific fields from request
        $clientFields = [];
        $validFields  = $this->client->getClientSpecificFields();

        // Check query parameters
        if (isset($options['query'])) {
            foreach ($validFields as $field => $type) {
                if (isset($options['query'][$field])) {
                    $clientFields[$field] = is_array($options['query'][$field])
                        ? json_encode($options['query'][$field])
                        : $options['query'][$field];
                }
            }
        }

        // Check body for POST/PUT requests
        if (isset($options['body']) && is_string($options['body'])) {
            $body = json_decode($options['body'], true);
            if ($body) {
                foreach ($validFields as $field => $type) {
                    if (isset($body[$field])) {
                        $clientFields[$field] = $body[$field];
                    }
                }
            }
        }

        // Handle request headers
        $requestHeaders = $response['request']['headers'] ?? '{}';
        if (is_array($requestHeaders)) {
            $requestHeaders = json_encode($requestHeaders);
        }

        // Handle request body
        $requestBody = $response['request']['body'] ?? null;
        if (is_array($requestBody)) {
            $requestBody = json_encode($requestBody);
        }

        // Handle response headers
        $responseHeaders = $response['response']['headers'] ?? '{}';
        if (is_array($responseHeaders)) {
            $responseHeaders = json_encode($responseHeaders);
        }

        // Store in database
        DB::table($this->client->getResponseTableName())->insert([
            'client'                   => $client,
            'key'                      => $key,
            'endpoint'                 => $endpoint,
            'base_url'                 => $this->client->getBaseUrl(),
            'full_url'                 => $url,
            'method'                   => $method,
            'request_headers'          => $requestHeaders,
            'request_body'             => $requestBody,
            'response_status_code'     => $response['response']['statusCode'],
            'response_headers'         => $responseHeaders,
            'response_body_raw'        => $body,
            'response_body_compressed' => $compressed,
            'response_raw_size'        => $originalSize,
            'response_compressed_size' => isset($compressed) ? $compressedSize : null,
            'response_time'            => $response['response_time'] ?? null,
            'expires_at'               => $expiresAt,

            // Add client-specific fields
            'response_format' => $clientFields['response_format'] ?? null,
            'input_value'     => isset($clientFields['input_value'])
                ? (is_array($clientFields['input_value'])
                    ? json_encode($clientFields['input_value'])
                    : $clientFields['input_value'])
                : null,
            'input_type' => $clientFields['input_type'] ?? null,

            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Generate cache key for a request.
     *
     * @param string $client  Name of the client (for config lookup)
     * @param string $url     Full URL for the request
     * @param string $method  HTTP method
     * @param array  $options Request options
     *
     * @return string Cache key
     */
    protected function generateCacheKey(string $client, string $url, string $method, array $options = []): string
    {
        $specificFields = [];
        $validFields    = $this->client->getClientSpecificFields();

        // Extract from query parameters
        if (isset($options['query'])) {
            foreach ($validFields as $field => $type) {
                if (isset($options['query'][$field])) {
                    $specificFields[$field] = is_array($options['query'][$field])
                        ? json_encode($options['query'][$field])
                        : $options['query'][$field];
                }
            }
        }

        // Extract from POST/PUT body
        if (in_array($method, ['POST', 'PUT']) && isset($options['body'])) {
            $body = is_string($options['body']) ? json_decode($options['body'], true) : $options['body'];
            if ($body) {
                foreach ($validFields as $field => $type) {
                    if (isset($body[$field])) {
                        $specificFields[$field] = is_array($body[$field])
                            ? json_encode($body[$field])
                            : $body[$field];
                    }
                }
            }
        }

        $keyParts = [
            'client' => $client,
            'method' => $method,
            'url'    => $url,
            'inputs' => $specificFields,
        ];

        return md5(json_encode($keyParts));
    }

    /**
     * Update rate limit counters for an endpoint.
     *
     * @param string $client Name of the client (for config lookup)
     * @param string $url    Full URL for the request
     *
     * @return void
     */
    protected function updateRateLimit(string $client, string $url): void
    {
        $parsedUrl  = parse_url($url);
        $path       = $parsedUrl['path'] ?? $url;
        $endpoint   = $this->client->cleanEndpointPath($path);
        $windowSize = $this->config['clients'][$client]['rate_limits']['window_size'] ?? 60;

        // First, try to update existing record
        $updated = DB::table($this->client->getRateLimitTableName())
            ->where('client', $client)
            ->where('endpoint', $endpoint)
            ->where('status', 'active')
            ->where('window_start', now()->startOfMinute())
            ->update([
                'window_request_count' => DB::raw('window_request_count + 1'),
                'updated_at'           => now(),
            ]);

        // If no record was updated, insert new one
        if (!$updated) {
            DB::table($this->client->getRateLimitTableName())->insert([
                'client'               => $client,
                'endpoint'             => $endpoint,
                'window_start'         => now()->startOfMinute(),
                'window_request_count' => 1,
                'status'               => 'active',
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        // Archive old windows
        DB::table($this->client->getRateLimitTableName())
            ->where('status', 'active')
            ->where('window_start', '<', now()->subSeconds($windowSize * 2))
            ->update(['status' => 'archived']);
    }
}
