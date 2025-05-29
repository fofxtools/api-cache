<?php

declare(strict_types=1);

/** DataForSEO Pingback webhook for Standard-method task completion notifications */

require_once __DIR__ . '/../examples/bootstrap.php';

use FOfX\ApiCache\DataForSeoApiClient;
use FOfX\ApiCache\ApiCacheManager;

$client = new DataForSeoApiClient(app(ApiCacheManager::class));

try {
    // Validate this is a GET request
    $client->validateHttpMethod('GET', 'pingback_invalid_method');
    $client->validateIpWhitelist('pingback_ip_not_whitelisted');

    // Process pingback notification and retrieve results
    // taskGet() will automatically store the results in cache
    [$responseArray, $task, $taskId, $cacheKey, $cost, $jsonData, $endpoint, $method] = $client->processPingbackResponse('pingback', 'pingback_response_error');

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
