<?php

/**
 * Simple test API endpoint for local development and testing.
 * Returns predictable JSON responses with various HTTP status codes.
 */

// Allow testing from any origin
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

// Get the request path (everything after test-api.php/)
$path = $_SERVER['PATH_INFO'] ?? '/';

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Basic response structure
$response = [
    'success'   => true,
    'method'    => $method,
    'path'      => $path,
    'timestamp' => time(),
];

// Handle different endpoints
switch ($path) {
    case '/success':
        $response['data'] = [
            'id'          => 123,
            'name'        => 'Test Item',
            'description' => 'This is a successful response',
        ];

        break;

    case '/rate-limit':
        header('HTTP/1.1 429 Too Many Requests');
        header('Retry-After: 60');
        $response = [
            'success'     => false,
            'error'       => 'Rate limit exceeded',
            'retry_after' => 60,
        ];

        break;

    case '/error':
        header('HTTP/1.1 500 Internal Server Error');
        $response = [
            'success' => false,
            'error'   => 'Internal server error',
            'details' => 'Something went wrong',
        ];

        break;

    default:
        header('HTTP/1.1 404 Not Found');
        $response = [
            'success' => false,
            'error'   => 'Endpoint not found',
        ];
}

// Output JSON response
echo json_encode($response, JSON_PRETTY_PRINT);
