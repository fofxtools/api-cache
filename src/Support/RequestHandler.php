<?php

namespace FOfX\ApiCache\Support;

use FOfX\ApiCache\Exceptions\ApiException;
use FOfX\ApiCache\Exceptions\RateLimitException;
use FOfX\GuzzleMiddleware\MiddlewareClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

class RequestHandler
{
    protected MiddlewareClient $client;
    protected array $config;
    protected Logger $logger;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        // Create a logger for the middleware
        $this->logger = new Logger('api-cache');
        
        // Handle both package and local testing scenarios
        $logPath = defined('LARAVEL_START') 
            ? storage_path('logs/api-cache.log')
            : __DIR__ . '/../../storage/logs/api-cache.log';
            
        $this->logger->pushHandler(new StreamHandler($logPath, Level::Info));
        
        // Initialize the middleware client
        $this->client = new MiddlewareClient(
            [
                'http_errors' => false,
                'timeout' => $config['timeout'] ?? 30,
            ],
            $this->logger
        );
    }

    public function send(string $client, string $method, string $url, array $options = []): array
    {
        // Check rate limits first
        $this->checkRateLimit($client, $url);

        // Try to get from cache
        if ($cached = $this->getFromCache($client, $url)) {
            return $cached;
        }

        try {
            $startTime = microtime(true);
            $response = $this->client->makeRequest($method, $url, $options);
            $endTime = microtime(true);

            $result = [
                'status_code' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => (string) $response->getBody(),
                'response_time' => $endTime - $startTime,
            ];

            // Cache the response
            $this->cacheResponse($client, $url, $result, $options);

            // Update rate limit
            $this->updateRateLimit($client, $url);

            return $result;
        } catch (GuzzleException $e) {
            throw new ApiException($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function checkRateLimit(string $client, string $url): void
    {
        $endpoint = parse_url($url, PHP_URL_PATH);
        $windowSize = $this->config['clients'][$client]['rate_limits']['window_size'] ?? 60; // default 60 seconds
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
                    'client' => $client,
                    'endpoint' => $endpoint,
                    'window_start' => $currentWindow->window_start,
                    'current_count' => $currentWindow->window_request_count,
                    'max_requests' => $maxRequests,
                ]);
        }
    }

    protected function getFromCache(string $client, string $url): ?array
    {
        $key = $this->generateCacheKey($client, $url);
        
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
                'status_code' => $cached->response_status_code,
                'headers' => json_decode($cached->response_headers, true) ?? [],
                'body' => $cached->response_body_raw,
                'response_time' => $cached->response_time,
                'from_cache' => true,
            ];
        }

        return null;
    }

    protected function cacheResponse(string $client, string $url, array $response, array $options): void
    {
        $key = $this->generateCacheKey($client, $url);
        $ttl = $this->config['clients'][$client]['cache_ttl'] ?? 3600; // default 1 hour
        
        DB::table('api_cache_responses')->insert([
            'client' => $client,
            'key' => $key,
            'endpoint' => parse_url($url, PHP_URL_PATH),
            'base_url' => parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST),
            'full_url' => $url,
            'method' => $options['method'] ?? 'GET',
            'request_headers' => json_encode($options['headers'] ?? []),
            'request_body' => $options['body'] ?? null,
            'response_status_code' => $response['status_code'],
            'response_headers' => json_encode($response['headers']),
            'response_body_raw' => $response['body'],
            'response_raw_size' => strlen($response['body']),
            'response_time' => $response['response_time'],
            'expires_at' => $ttl ? now()->addSeconds($ttl) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function generateCacheKey(string $client, string $url): string
    {
        return md5($client . '|' . $url);
    }

    protected function updateRateLimit(string $client, string $url): void
    {
        $endpoint = parse_url($url, PHP_URL_PATH);
        $windowSize = $this->config['clients'][$client]['rate_limits']['window_size'] ?? 60;

        // First, try to update existing record
        $updated = DB::table('api_cache_rate_limits')
            ->where('client', $client)
            ->where('endpoint', $endpoint)
            ->where('window_start', now()->startOfMinute())
            ->update([
                'window_request_count' => DB::raw('window_request_count + 1'),
                'updated_at' => now(),
            ]);

        // If no record was updated, insert new one
        if (!$updated) {
            DB::table('api_cache_rate_limits')->insert([
                'client' => $client,
                'endpoint' => $endpoint,
                'window_start' => now()->startOfMinute(),
                'window_request_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Clean up old windows
        DB::table('api_cache_rate_limits')
            ->where('window_start', '<', now()->subSeconds($windowSize * 2))
            ->delete();
    }
} 