<?php

namespace FOfX\ApiCache\Support;

use FOfX\ApiCache\ApiClients\BaseApiClient;
use FOfX\ApiCache\Exceptions\RateLimitException;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

/**
 * Handles API request caching and rate limiting.
 * Decorates API clients with caching and rate limiting functionality.
 */
class RequestHandler
{
    protected BaseApiClient $client;
    protected array $config;
    protected Logger $logger;

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

        // Set up logging
        $this->logger = new Logger('api-cache');
        $logPath      = defined('LARAVEL_START')
            ? storage_path('api-cache.log')
            : __DIR__ . '/../../storage/api-cache.log';

        $level = !empty($config['debug']) ? Level::Debug : Level::Info;
        $this->logger->pushHandler(new StreamHandler($logPath, $level));
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
            $this->logger->debug('Cache hit', [
                'cached_response' => $cached,
            ]);

            return $cached;
        }

        // Make the request
        $this->logger->debug('Making request', [
            'method'   => $method,
            'endpoint' => $endpoint,
            'options'  => $options,
        ]);
        $response = $this->client->request($method, $endpoint, $options);
        $this->logger->debug('Response received', [
            'response' => $response,
        ]);

        // Debug
        $this->logger->debug('Response details', [
            'response_size'    => strlen($response['response']['body']),
            'response_preview' => substr($response['response']['body'], 0, 100),
            'has_test_data'    => isset(json_decode($response['response']['body'], true)['test_data']),
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
            ->where('window_start', '>', now()->subSeconds($windowSize))
            ->first();

        // Get total count in window
        $count = DB::table($this->client->getRateLimitTableName())
            ->where('client', $client)
            ->where('endpoint', $endpoint)
            ->where('window_start', '>', now()->subSeconds($windowSize))
            ->sum('window_request_count');

        if ($count >= $maxRequests) {
            $retryAfter = $windowSize;  // Simplified retry calculation
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
     */
    protected function cacheResponse(string $client, string $url, string $method, array $response, array $options = []): void
    {
        // Skip caching if response is a server error
        if ($response['response']['statusCode'] >= 500) {
            $this->logger->error('Skipping caching of response due to server error', [
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
     */
    protected function storeInCache(string $client, string $key, string $url, string $method, array $options, array $response): void
    {
        // Extract just the endpoint part from the full path
        $parsedUrl = parse_url($url);
        $path      = $parsedUrl['path'] ?? $url;
        $endpoint  = preg_replace('#^/demo-api\.php/#', '/', $path);

        // Get cache TTL from config
        $ttl       = $this->config['clients'][$client]['cache_ttl'] ?? 3600;
        $expiresAt = $ttl < 0 ? null : now()->addSeconds($ttl);

        // Handle compression if enabled
        $body = $response['response']['body'];

        // Calculate sizes before any modifications
        $originalSize = strlen($body);
        $this->logger->debug('Original response size', [
            'size'    => $originalSize,
            'preview' => substr($body, 0, 100),
        ]);

        $compressed = null;
        if ($this->config['clients'][$client]['compression']['enabled'] ?? false) {
            $level          = $this->config['clients'][$client]['compression']['level'] ?? 6;
            $compressed     = gzcompress($body, $level);
            $compressedSize = strlen($compressed);
            $body           = null;  // Don't store raw when compressed

            $this->logger->debug('Compression details', [
                'original_size'   => $originalSize,
                'compressed_size' => $compressedSize,
                'ratio'           => round(($compressedSize / $originalSize) * 100, 1) . '%',
            ]);
        }

        // Store in database
        DB::table($this->client->getResponseTableName())->insert([
            'client'                   => $client,
            'key'                      => $key,
            'endpoint'                 => $endpoint,
            'base_url'                 => rtrim($this->client->buildUrl(''), '/'),
            'full_url'                 => $url,
            'method'                   => $method,
            'request_headers'          => $response['request']['headers'] ?? '{}',
            'request_body'             => $response['request']['body'] ?? null,
            'response_status_code'     => $response['response']['statusCode'],
            'response_headers'         => $response['response']['headers'] ?? '{}',
            'response_body_raw'        => $body,
            'response_body_compressed' => $compressed,
            'response_raw_size'        => $originalSize,
            'response_compressed_size' => isset($compressed) ? $compressedSize : null,
            'response_time'            => $response['response_time'] ?? null,
            'expires_at'               => $expiresAt,

            // Add client-specific fields
            'response_format' => $options['response_format'] ?? null,
            'input_value'     => $options['input_value'] ?? null,
            'input_type'      => $options['input_type'] ?? null,

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
        // Get client-specific fields from options
        $specificFields = array_intersect_key(
            $options,
            array_flip($this->client->getClientSpecificFields())
        );

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
        $endpoint   = parse_url($url, PHP_URL_PATH);
        $windowSize = $this->config['clients'][$client]['rate_limits']['window_size'] ?? 60;

        // First, try to update existing record
        $updated = DB::table($this->client->getRateLimitTableName())
            ->where('client', $client)
            ->where('endpoint', $endpoint)
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
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        // Clean up old windows
        DB::table($this->client->getRateLimitTableName())
            ->where('window_start', '<', now()->subSeconds($windowSize * 2))
            ->delete();
    }
}
