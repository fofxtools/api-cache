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
        $url = rtrim($this->getBaseUrl(), '/') . '/' . ltrim($endpoint, '/');

        $this->logger->debug('Building URL:', [
            'base_url'  => $this->getBaseUrl(),
            'endpoint'  => $endpoint,
            'final_url' => $url,
        ]);

        return $url;
    }

    /**
     * Make an HTTP request and return standardized response.
     *
     * @param string $method   HTTP method (GET, POST, etc.)
     * @param string $endpoint API endpoint path
     * @param array  $options  Request options for Guzzle
     *
     * @return array ['status_code' => int, 'headers' => array, 'body' => string]
     */
    public function request(string $method, string $endpoint, array $options = []): array
    {
        $url       = $this->buildUrl($endpoint);
        $startTime = microtime(true);
        $response  = $this->client->makeRequest($method, $url, $options);
        $endTime   = microtime(true);

        return [
            'status_code'   => $response->getStatusCode(),
            'headers'       => $response->getHeaders(),
            'body'          => (string) $response->getBody(),
            'response_time' => $endTime - $startTime,
        ];
    }
}
