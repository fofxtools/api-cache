<?php

namespace FOfX\ApiCache\ApiClients;

/**
 * Demo API client for local development and examples.
 * Uses a local PHP server to demonstrate API client implementation.
 */
class DemoApiClient extends BaseApiClient
{
    /**
     * Get the base URL for the demo API.
     *
     * @return string
     */
    protected function getBaseUrl(): string
    {
        return env('DEMO_API_URL', 'http://localhost:8000/demo-api.php');
    }

    public function getClientName(): string
    {
        return 'demo_api';
    }

    public function getClientSpecificFields(): array
    {
        return [
            'response_format' => 'string',
            'input_value'     => 'string',
            'input_type'      => 'string',
        ];
    }

    protected function executeRequest(string $method, string $url, array $options): array
    {
        // Debug request details
        $this->logger->debug('Request details', [
            'method'     => $method,
            'url'        => $url,
            'headers'    => $options['headers'] ?? [],
            'body_size'  => isset($options['body']) ? strlen($options['body']) : 0,
            'input_type' => $options['input_type'] ?? 'default',
        ]);

        // Use Guzzle Middleware's makeRequest method
        $this->client->makeRequest($method, $url, $options);
        // Return the output
        return $this->client->getOutput();
    }
}
