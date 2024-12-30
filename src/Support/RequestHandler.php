<?php

namespace FOfX\ApiCache\Support;

use FOfX\ApiCache\ApiClients\BaseApiClient;
use FOfX\ApiCache\ApiClients\TestClient;
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
     * @param array $config Configuration for caching and rate limiting
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Set up logging
        $this->logger = new Logger('api-cache');
        $logPath      = defined('LARAVEL_START')
            ? storage_path('logs/api-cache.log')
            : __DIR__ . '/../../storage/logs/api-cache.log';

        // Use debug level if enabled in config
        $level = !empty($config['debug']) ? Level::Debug : Level::Info;
        $this->logger->pushHandler(new StreamHandler($logPath, $level));

        // Create client with logger
        $this->client = new TestClient($this->logger);
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
        if ($cached = $this->getFromCache($clientName, $fullUrl, $method)) {
            return $cached;
        }

        // Make the request
        $response = $this->client->request($method, $endpoint, $options);

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
        $windowSize  = $this->config['clients'][$client]['rate_limits']['window_size'] ?? 60; // default 60 seconds
        $maxRequests = $this->config['clients'][$client]['rate_limits']['max_requests'] ?? 60; // default 60 requests

        // Get current window
        $currentWindow = DB::table('api_cache_rate_limits')
            ->where('client', $client)
            ->where('endpoint', $endpoint)
            ->where('window_start', '>', now()->subSeconds($windowSize))
            ->first();

        if ($currentWindow && $currentWindow->window_request_count >= $maxRequests) {
            $retryAfter = (int)(
                $windowSize - now()->diffInSeconds(Carbon::parse($currentWindow->window_start))
            );

            throw (new RateLimitException("Rate limit exceeded for {$client}"))
                ->setRetryAfter($retryAfter)
                ->setContext([
                    'client'        => $client,
                    'endpoint'      => $endpoint,
                    'window_start'  => $currentWindow->window_start,
                    'current_count' => $currentWindow->window_request_count,
                    'max_requests'  => $maxRequests,
                ]);
        }
    }

    /**
     * Try to get cached response for this request.
     *
     * @param string $client Name of the client (for config lookup)
     * @param string $url    Full URL for the request
     * @param string $method HTTP method
     *
     * @return array|null Cached response or null if not found
     */
    protected function getFromCache(string $client, string $url, string $method): ?array
    {
        $key = $this->generateCacheKey($client, $url, $method);

        $cached = DB::table('api_cache_responses')
            ->where('client', $client)
            ->where('key', $key)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($cached) {
            return [
                'status_code'   => $cached->response_status_code,
                'headers'       => json_decode($cached->response_headers, true) ?? [],
                'body'          => $cached->response_body_raw,
                'response_time' => $cached->response_time,
                'from_cache'    => true,
            ];
        }

        return null;
    }

    /**
     * Cache an API response.
     *
     * @param string $client   Name of the client (for config lookup)
     * @param string $url      Full URL for the request
     * @param string $method   HTTP method used
     * @param array  $response API response
     * @param array  $options  Request options
     *
     * @return void
     */
    protected function cacheResponse(string $client, string $url, string $method, array $response, array $options): void
    {
        $key = $this->generateCacheKey($client, $url, $method);
        $ttl = $this->config['clients'][$client]['cache_ttl'] ?? 3600;

        // Parse URL components
        $parsedUrl = parse_url($url);
        $baseUrl   = '';

        // Build base URL including port if present
        if (!empty($parsedUrl['scheme']) && !empty($parsedUrl['host'])) {
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
            if (!empty($parsedUrl['port'])) {
                $baseUrl .= ':' . $parsedUrl['port'];
            }
        }

        $this->logger->debug('URL Parsing:', [
            'original_url' => $url,
            'parsed_url'   => $parsedUrl,
            'base_url'     => $baseUrl,
        ]);

        DB::table('api_cache_responses')->insert([
            'client'               => $client,
            'key'                  => $key,
            'endpoint'             => $parsedUrl['path'] ?? $url,
            'base_url'             => $baseUrl,
            'full_url'             => $url,
            'method'               => $method,
            'request_headers'      => json_encode($options['headers'] ?? []),
            'request_body'         => $options['body'] ?? null,
            'response_status_code' => $response['status_code'],
            'response_headers'     => json_encode($response['headers']),
            'response_body_raw'    => $response['body'],
            'response_raw_size'    => strlen($response['body']),
            'response_time'        => $response['response_time'],
            'expires_at'           => $ttl ? now()->addSeconds($ttl) : null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    /**
     * Generate cache key for a request.
     *
     * @param string $client Name of the client (for config lookup)
     * @param string $url    Full URL for the request
     * @param string $method HTTP method
     *
     * @return string Cache key
     */
    protected function generateCacheKey(string $client, string $url, string $method): string
    {
        return md5($client . '|' . $method . '|' . $url);
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
        $updated = DB::table('api_cache_rate_limits')
            ->where('client', $client)
            ->where('endpoint', $endpoint)
            ->where('window_start', now()->startOfMinute())
            ->update([
                'window_request_count' => DB::raw('window_request_count + 1'),
                'updated_at'           => now(),
            ]);

        // If no record was updated, insert new one
        if (!$updated) {
            DB::table('api_cache_rate_limits')->insert([
                'client'               => $client,
                'endpoint'             => $endpoint,
                'window_start'         => now()->startOfMinute(),
                'window_request_count' => 1,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        // Clean up old windows
        DB::table('api_cache_rate_limits')
            ->where('window_start', '<', now()->subSeconds($windowSize * 2))
            ->delete();
    }
}
