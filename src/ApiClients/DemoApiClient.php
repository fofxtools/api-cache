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

        // Make the request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        // Set headers
        if (!empty($options['headers'])) {
            $headers = [];
            foreach ($options['headers'] as $key => $value) {
                $headers[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // Set body for POST/PUT
        if (in_array($method, ['POST', 'PUT'])) {
            // Get client-specific fields
            $clientFields = array_intersect_key(
                $options,
                array_flip($this->getClientSpecificFields())
            );

            // Prepare request data
            $requestData = [
                'test_data'       => null,
                'input_type'      => $clientFields['input_type'] ?? 'default',
                'input_value'     => $clientFields['input_value'] ?? null,
                'response_format' => $clientFields['response_format'] ?? 'json',
            ];

            // Add body data if exists
            if (!empty($options['body'])) {
                $bodyData    = json_decode($options['body'], true) ?? [];
                $requestData = array_merge($requestData, $bodyData);
            }

            // Set as POST fields
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        }

        // Execute request
        $response = curl_exec($ch);
        $info     = curl_getinfo($ch);

        curl_close($ch);

        return [
            'status_code' => $info['http_code'],
            'headers'     => [],
            'body'        => $response,
        ];
    }
}
