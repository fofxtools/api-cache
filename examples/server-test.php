<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Support\Facades\Http;

$baseApiClient = new BaseApiClient('default');
$baseApiClient->setWslEnabled(true);
$baseUrl = $baseApiClient->getBaseUrl();

// HTTP request for (on Windows) localhost:8000/demo-api-server.php/health
$response = Http::get("{$baseUrl}/health");

echo $response->body() . "\n";
