<?php

require __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

/**
 * Simple test API endpoint for local development and testing.
 * Returns predictable JSON responses with various HTTP status codes.
 */

// Allow testing from any origin
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

// Set up logging
$logger  = new Logger('api-cache');
$logPath = __DIR__ . '/../storage/api-cache.log';
$logger->pushHandler(new StreamHandler($logPath, Level::Debug));

// Get request data
$method   = $_SERVER['REQUEST_METHOD'];
$path     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$endpoint = basename($path);

// Default to 200 OK unless changed by endpoint handling
$status = 200;

// Get client-specific inputs from headers or query/body
$responseFormat = $_SERVER['HTTP_ACCEPT'] ?? $_GET['response_format'] ?? 'json';
$inputValue     = isset($_GET['input_value']) ? urldecode($_GET['input_value']) : null;
$inputType      = $_GET['input_type'] ?? 'default';

// Log input details
$logger->debug('Request input details', [
    'raw_input'     => $_GET['input_value'] ?? null,
    'decoded_input' => $inputValue,
    'input_type'    => $inputType,
]);

// Get request body for POST requests
$body = null;
if ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    $body    = json_decode($rawBody, true);

    // Get input type from POST data if available
    $inputType = $_POST['input_type'] ?? $body['input_type'] ?? $inputType;

    $logger->debug('Request details', [
        'method'       => $method,
        'endpoint'     => $endpoint,
        'input_type'   => $inputType,
        'raw_length'   => strlen($rawBody),
        'raw_preview'  => substr($rawBody, 0, 100),
        'decoded_body' => $body,
    ]);
}

// Prepare response data
$response = [
    'success'     => true,
    'endpoint'    => $endpoint,
    'method'      => $method,
    'input_value' => $inputValue ?? $body['input_value'] ?? null,
    'input_type'  => $inputType,
    'status_code' => $status,
    'timestamp'   => time(),
];

// Handle different endpoints
switch ($endpoint) {
    case 'success':
        $status = 200;
        // Include test data if provided
        if ($body && isset($body['test_data'])) {
            $response['test_data'] = $body['test_data'];
            $logger->debug('Test data added to response', [
                'test_data_length' => strlen($body['test_data']),
                'response_size'    => strlen(json_encode($response)),
            ]);
        }

        // Include input data in success response
        if ($inputValue) {
            $logger->debug('Including input value in response', [
                'input_length'  => strlen($inputValue),
                'input_preview' => substr($inputValue, 0, 100),
            ]);
        }

        break;

    case 'rate-limit':
        $status                  = 429;
        $response['error']       = 'Rate limit exceeded';
        $response['retry_after'] = 60;

        break;

    case 'error':
        $status              = 500;
        $response['success'] = false;
        $response['error']   = 'Test error response';

        break;

    default:
        $status              = 404;
        $response['success'] = false;
        $response['error']   = 'Endpoint not found';
}

// Format response based on requested format
http_response_code($status);

switch ($responseFormat) {
    case 'xml':
        header('Content-Type: application/xml');
        $xml = new SimpleXMLElement('<response/>');
        array_walk_recursive($response, function ($value, $key) use ($xml) {
            $xml->addChild($key, $value);
        });
        echo $xml->asXML();

        break;

    case 'text':
        header('Content-Type: text/plain');
        echo implode("\n", array_map(
            fn ($k, $v) => "$k: $v",
            array_keys($response),
            array_values($response)
        ));

        break;

    case 'json':
    default:
        header('Content-Type: application/json');
        echo json_encode($response, JSON_PRETTY_PRINT);
}
