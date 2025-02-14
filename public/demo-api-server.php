<?php

declare(strict_types=1);

/**
 * This is a simple API server for testing the API cache library.
 *
 * It can be started with the command:
 * (0.0.0.0 is used to allow connections from Ubuntu WSL, use localhost for Windows only):
 *
 * php -S 0.0.0.0:8000 -t public
 *
 * Then for instance you can visit the following URLs in a browser in Windows:
 *
 * http://localhost:8000/demo-api-server.php/health
 * http://localhost:8000/demo-api-server.php/v1/predictions?query=test&max_results=5&api_key=demo-api-key
 *
 * Or in Ubuntu WSL, using curl:
 *
 * curl http://$(grep nameserver /etc/resolv.conf | awk '{print $2}'):8000/demo-api-server.php/health
 *
 * To test the POST reports endpoint, use the following command in Windows CMD:
 *
 * curl -X POST ^
 * -H "Authorization: Bearer demo-api-key" ^
 * -H "Content-Type: application/json" ^
 * -d "{\"report_type\":\"monthly\",\"data_source\":\"sales\"}" ^
 * http://localhost:8000/demo-api-server.php/v1/reports
 *
 * Or in Ubuntu WSL:
 *
 * curl -X POST \
 * -H "Authorization: Bearer demo-api-key" \
 * -H "Content-Type: application/json" \
 * -d "{\"report_type\":\"monthly\",\"data_source\":\"sales\"}" \
 * http://$(grep nameserver /etc/resolv.conf | awk '{print $2}'):8000/demo-api-server.php/v1/reports
 */

// Simple router based on path and method
$path   = $_SERVER['PATH_INFO'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'];

// Set JSON content type
header('Content-Type: application/json');

// Helper function for JSON responses
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

// Validate API key
function validateApiKey(): bool
{
    $apiKey = 'demo-api-key';

    // Check GET parameter first (for easy testing)
    if (isset($_GET['api_key']) && $_GET['api_key'] === $apiKey) {
        return true;
    }

    // Fall back to checking Authorization header
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/', $authHeader, $matches)) {
        return false;
    }
    $providedKey = $matches[1];

    return $providedKey === $apiKey;
}

// Check health. Does not require API key or version.
if ($path === '/health' || $path === '/v1/health') {
    jsonResponse([
        'status' => 'OK',
        'server' => [
            'php_version'    => PHP_VERSION,
            'time_utc'       => gmdate('Y-m-d\TH:i:s\Z'),
            'remote_addr'    => $_SERVER['REMOTE_ADDR'],
            'server_addr'    => $_SERVER['SERVER_ADDR'] ?? 'unknown',
            'server_port'    => $_SERVER['SERVER_PORT'],
            'http_host'      => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'],
            'request_uri'    => $_SERVER['REQUEST_URI'],
        ],
    ]);
    exit;
}

// Check API key before processing
if (!validateApiKey()) {
    jsonResponse(['error' => 'Invalid API key'], 401);
    exit;
}

// API version check
if (!str_starts_with($path, '/v1/')) {
    jsonResponse(['error' => 'API version not found'], 404);
    exit;
}

// Remove version prefix
$path = substr($path, 3);

// Route handling
match(true) {
    // GET /predictions
    $method === 'GET' && $path === '/predictions' => handlePredictions(),

    // POST /reports
    $method === 'POST' && $path === '/reports' => handleReports(),

    // 404 for everything else
    default => handle404(),
};

// Handler functions
function handlePredictions()
{
    // Validate required parameters
    $query      = $_GET['query'] ?? null;
    $maxResults = (int)($_GET['max_results'] ?? 10);

    if (!$query) {
        jsonResponse(['error' => 'Query parameter is required'], 400);

        return;
    }

    // Generate demo predictions
    $predictions = [];
    for ($i = 1; $i <= $maxResults; $i++) {
        $predictions[] = [
            'id'         => $i,
            'query'      => $query,
            'prediction' => "Demo prediction {$i} for: {$query}",
            'confidence' => rand(50, 100) / 100,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    jsonResponse([
        'success' => true,
        'data'    => $predictions,
        'meta'    => [
            'total' => count($predictions),
            'query' => $query,
        ],
    ]);
}

function handleReports()
{
    // Get POST data
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate required parameters
    $reportType = $data['report_type'] ?? null;
    $dataSource = $data['data_source'] ?? null;

    if (!$reportType || !$dataSource) {
        jsonResponse(['error' => 'report_type and data_source are required'], 400);

        return;
    }

    // Generate demo report
    $report = [
        'id'           => rand(1000, 9999),
        'type'         => $reportType,
        'source'       => $dataSource,
        'generated_at' => date('Y-m-d H:i:s'),
        'data'         => [
            'summary' => "Demo report for {$reportType} using {$dataSource}",
            'metrics' => [
                'value1' => rand(100, 1000),
                'value2' => rand(1000, 10000),
                'ratio'  => rand(1, 100) / 100,
            ],
        ],
    ];

    jsonResponse([
        'success' => true,
        'data'    => $report,
    ]);
}

function handle404()
{
    jsonResponse([
        'status' => 'error',
        'code'   => 404,
        'error'  => 'Not found',
    ], 404);
}
