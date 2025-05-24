<?php

declare(strict_types=1);

/** DataForSEO Standard-method POSTBACK webhook */

require_once __DIR__ . '/../examples/bootstrap.php';

use Illuminate\Support\Facades\DB;
use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\ApiCacheManager;

$client = new DataForSeoApiClient(app(ApiCacheManager::class));

validateHttpMethod($client);
validateIpWhitelist($client);
[$payload, $task, $taskId, $cacheKey, $cost, $rawData] = parseAndValidatePayload($client);
$endpoint                                              = resolveEndpoint($client, $taskId);
storeInCache($client, $payload, $cacheKey, $endpoint, $cost, $taskId, $rawData);

echo 'ok';

/* Helper functions - bottom up */

function _in_logit_POST($id_message, $data)
{
    @file_put_contents(__DIR__ . '/postback_url_example.log', PHP_EOL . date('Y-m-d H:i:s') . ': ' . $id_message . PHP_EOL . '---------' . PHP_EOL . print_r($data, true) . PHP_EOL . '---------', FILE_APPEND);
}

function validateHttpMethod(DataForSeoApiClient $client): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        abortWithError($client, 405, 'Method not allowed');
    }
}

function validateIpWhitelist(DataForSeoApiClient $client): void
{
    $allowedIps = config('api-cache.apis.dataforseo.whitelisted_ips');

    if (empty($allowedIps)) {
        return; // No whitelist = allow all
    }

    // Multi-header IP check for Cloudflare/proxy environments
    $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
             ?? $_SERVER['HTTP_X_FORWARDED_FOR']
             ?? $_SERVER['REMOTE_ADDR']
             ?? '';

    if (!in_array($clientIp, $allowedIps, true)) {
        abortWithError($client, 403, 'IP not whitelisted');
    }
}

function parseAndValidatePayload(DataForSeoApiClient $client): array
{
    $raw = file_get_contents('php://input');

    if (empty($raw)) {
        abortWithError($client, 400, 'Empty POST data');
    }

    // Handle DataForSEO gzip compression
    $decompressed = gzdecode($raw);
    $jsonData     = $decompressed !== false ? $decompressed : $raw;

    $payload = json_decode($jsonData, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        abortWithError($client, 400, 'Invalid JSON: ' . json_last_error_msg());
    }

    if (($payload['status_code'] ?? 0) !== 20000) {
        abortWithError($client, 400, 'DataForSEO error response');
    }

    //_in_logit_POST('result', $payload);

    $task = $payload['tasks'][0] ?? null;
    if (!$task) {
        abortWithError($client, 400, 'No task data in payload');
    }

    $taskId   = $task['id'] ?? null;
    $cacheKey = $task['tag'] ?? null;
    $cost     = $payload['cost'] ?? null;

    if (!$taskId) {
        abortWithError($client, 400, 'Missing task ID');
    }

    if (!$cacheKey) {
        abortWithError($client, 400, 'Missing cache key in tag');
    }

    return [$payload, $task, $taskId, $cacheKey, $cost, $jsonData];
}

function resolveEndpoint(DataForSeoApiClient $client, string $taskId): string
{
    // Method 1: GET parameter
    $endpoint = $_GET['endpoint'] ?? null;
    if ($endpoint) {
        return $endpoint;
    }

    // Method 2: Database lookup
    $endpoint = DB::table('api_cache_dataforseo_responses')
                  ->where('attributes', $taskId)
                  ->value('endpoint');

    if ($endpoint) {
        return $endpoint;
    }

    abortWithError($client, 422, 'Cannot determine endpoint for task');
}

function storeInCache(DataForSeoApiClient $client, array $payload, string $cacheKey, string $endpoint, ?float $cost, string $taskId, string $rawResponseData): void
{
    $manager = app(ApiCacheManager::class);

    $response = new \Illuminate\Http\Client\Response(
        new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/json'],
            $rawResponseData
        )
    );

    $apiResult = [
        'params'  => [],
        'request' => [
            'method'     => 'POST',
            'base_url'   => '',
            'full_url'   => '',
            'headers'    => [], // Empty - we don't have original outbound headers
            'body'       => '', // Empty - we don't have original request body in webhook context
            'attributes' => $taskId,
            'cost'       => $cost,
        ],
        'response'             => $response,
        'response_status_code' => 200,
        'response_size'        => strlen($rawResponseData),
        'response_time'        => null,
        'is_cached'            => false,
    ];

    $manager->storeResponse(
        $client->getClientName(),
        $cacheKey,
        [],
        $apiResult,
        $endpoint,
        attributes: $taskId
    );
}

function abortWithError(DataForSeoApiClient $client, int $httpCode, string $message): never
{
    $context = [
        'http_code'   => $httpCode,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip_address'  => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    // Use client's error logging infrastructure
    $client->logApiError('webhook_postback_error', $message, $context);

    http_response_code($httpCode);
    echo 'error';
    exit;
}
