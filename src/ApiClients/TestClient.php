<?php

namespace FOfX\ApiCache\ApiClients;

/**
 * Test API client for local development and testing.
 * Uses a local PHP server to simulate API responses.
 */
class TestClient extends BaseApiClient
{
    /**
     * Get the base URL for the test API.
     *
     * @return string
     */
    protected function getBaseUrl(): string
    {
        return 'http://localhost:8000/test-api.php';
    }
}
