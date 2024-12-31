<?php

namespace FOfX\ApiCache\ApiClients;

use FOfX\GuzzleMiddleware\MiddlewareClient;
use Monolog\Logger;

/**
 * Base class for all API clients.
 * Provides common HTTP request functionality and standardized response format.
 */
abstract class BaseApiClient
{
    protected MiddlewareClient $client;
    protected Logger $logger;

    /**
     * Create a new API client instance.
     *
     * @param Logger $logger Logger instance for logging
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->client = new MiddlewareClient(
            [
                'http_errors' => false,
                'timeout'     => 30,
            ],
            $this->logger
        );
    }

    /**
     * Get the base URL for this API client.
     *
     * @return string
     */
    abstract protected function getBaseUrl(): string;

    /**
     * Build full URL from endpoint.
     *
     * @param string $endpoint API endpoint path
     *
     * @return string Full URL
     */
    public function buildUrl(string $endpoint): string
    {
        return rtrim($this->getBaseUrl(), '/') . '/' . ltrim($endpoint, '/');
    }

    /**
     * Make the actual HTTP request - to be implemented by child classes
     */
    abstract protected function executeRequest(string $method, string $url, array $options): array;

    /**
     * Make a request with standard logging and processing
     */
    public function request(string $method, string $endpoint, array $options = []): array
    {
        $url       = $this->buildUrl($endpoint);
        $startTime = microtime(true);

        // Child class implements the actual request
        $response = $this->executeRequest($method, $url, $options);

        $endTime                   = microtime(true);
        $response['response_time'] = $endTime - $startTime;

        return $response;
    }

    /**
     * Get the client name for table prefixes.
     */
    abstract public function getClientName(): string;

    /**
     * Get client-specific fields and their types.
     *
     * @return array<string, string> Field name => field type
     */
    abstract public function getClientSpecificFields(): array;

    /**
     * Get the response table name for this client.
     */
    public function getResponseTableName(): string
    {
        return 'api_cache_' . $this->getClientName() . '_responses';
    }

    /**
     * Get the rate limit table name for this client.
     */
    public function getRateLimitTableName(): string
    {
        return 'api_cache_' . $this->getClientName() . '_rate_limits';
    }
}
