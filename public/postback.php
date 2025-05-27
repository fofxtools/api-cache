<?php

declare(strict_types=1);

/** DataForSEO Standard-method POSTBACK webhook */

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\ApiCacheManager;

$client = new DataForSeoApiClient(app(ApiCacheManager::class));

try {
    $client->validateHttpMethod('postback_invalid_method');
    $client->validateIpWhitelist('postback_ip_not_whitelisted');
    [$payload, $task, $taskId, $cacheKey, $cost, $rawData] = $client->processPostbackResponse('postback', 'postback_response_error');
    $endpoint                                              = $client->resolveEndpoint($taskId, $payload);
    $client->storeInCache($payload, $cacheKey, $endpoint, $cost, $taskId, $rawData);

    echo 'ok';
} catch (\RuntimeException $e) {
    // Extract HTTP code from the exception message if available
    if (preg_match('/API error \((\d+)\):/', $e->getMessage(), $matches)) {
        http_response_code((int) $matches[1]);
    } else {
        http_response_code(500);
    }
    echo 'error';
}
