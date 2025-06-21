<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

$start = microtime(true);

// Override database configuration to use MySQL
$databaseConnection = 'mysql';

// Use global to avoid PHPStan error
global $capsule;

$capsule->addConnection(
    config("database.connections.{$databaseConnection}")
);
$schema = app('db')->connection()->getSchemaBuilder();

$dropExisting = false;
$clientName   = 'dataforseo';

// Enable compression
// NOTE: This setting change will not work for webhooks, which run in a separate process
// You need to edit the .env file instead
//config(["api-cache.apis.{$clientName}.compression_enabled" => true]);

createClientTables($clientName, $dropExisting);
create_errors_table($schema, 'api_cache_errors', false);

$dfs = new DataForSeoApiClient();

// Sample data arrays with three different sets
$keywordsArrays = [
    ['apple iphone',     'vacuum cleaner',   'kitchenaid mixer'],
    ['samsung galaxy',   'robot vacuum',     'blender vitamix'],
    ['google pixel',     'handheld vacuum',  'food processor'],
];
$keywordsSetA = $keywordsArrays[0];

$asinArray = [
    'B00R92CL5E',   // NETGEAR Wi-Fi Range Extender EX3700
    'B09B8V1LZ3',   // Amazon Echo Dot
    'B0BZWRLRLK',   // Ring Battery Doorbell
];

$urlArrays = [
    ['https://www.fiverr.com/categories',     'https://www.reddit.com/r/',                 'https://github.com/topics'],
    ['https://www.upwork.com/services',       'https://news.ycombinator.com/',             'https://stackoverflow.com/questions'],
    ['https://www.freelancer.com/jobs',       'https://medium.com/explore-topics',         'https://dev.to/tags'],
];
$urlSetA = $urlArrays[0];

$domainArray = [
    'fiverr.com',
    'upwork.com',
    'freelancer.com',
];

$maxBid    = 10;
$matchType = 'exact';

// Test single keyword endpoints
foreach ($keywordsSetA as $keyword) {
    $result   = $dfs->serpGoogleOrganicStandardRegular($keyword, usePingback: true, postTaskIfNotCached: true);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->serpGoogleOrganicStandardAdvanced($keyword, usePingback: true, postTaskIfNotCached: true);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    // With people_also_ask_click_depth 4 and load_async_ai_overview true and expand_ai_overview true
    // people_also_ask_click_depth and load_async_ai_overview incur extra charges

    $result   = $dfs->serpGoogleOrganicStandardAdvanced($keyword, peopleAlsoAskClickDepth: 4, loadAsyncAiOverview: true, expandAiOverview: true, usePingback: true, postTaskIfNotCached: true);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->serpGoogleAutocompleteStandardAdvanced($keyword, usePingback: true, postTaskIfNotCached: true);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->labsAmazonRelatedKeywordsLive($keyword);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->merchantAmazonProductsStandardAdvanced($keyword, usePingback: true, postTaskIfNotCached: true);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);
}

// Test keywords array endpoints
foreach ($keywordsArrays as $keywordSet) {
    $result   = $dfs->keywordsDataGoogleAdsSearchVolumeStandard($keywordSet, usePingback: true, postTaskIfNotCached: true);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->keywordsDataGoogleAdsKeywordsForKeywordsStandard($keywordSet, usePingback: true, postTaskIfNotCached: true);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->keywordsDataGoogleAdsAdTrafficByKeywordsStandard($keywordSet, $maxBid, $matchType, usePingback: true, postTaskIfNotCached: true);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->labsAmazonBulkSearchVolumeLive($keywordSet);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);
}

// Test single domain endpoints
foreach ($domainArray as $domain) {
    $result   = $dfs->keywordsDataGoogleAdsKeywordsForSiteStandard($domain, usePingback: true, postTaskIfNotCached: true);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);
}

// Test Amazon ASIN endpoints
foreach ($asinArray as $asin) {
    $result   = $dfs->merchantAmazonAsinStandardAdvanced($asin, usePingback: true, postTaskIfNotCached: true);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->merchantAmazonSellersStandardAdvanced($asin, usePingback: true, postTaskIfNotCached: true);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->labsAmazonRankedKeywordsLive($asin);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);
}

// Change API base URL to use sandbox.dataforseo.com rather than api.dataforseo.com
// Because the Backlinks API requires a subscription, we need to use the sandbox to test it
$dfs->setBaseUrl('https://sandbox.dataforseo.com/v3');

// Test single domain endpoints with Backlinks API (make sure sandbox is enabled)
foreach ($domainArray as $domain) {
    $result   = $dfs->backlinksHistoryLive($domain);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->backlinksDomainPagesLive($domain);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);
}

// Test single URL endpoints with Backlinks API (make sure sandbox is enabled)
foreach ($urlSetA as $url) {
    $result   = $dfs->backlinksSummaryLive($url);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->backlinksBacklinksLive($url);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->backlinksAnchorsLive($url);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->backlinksDomainPagesSummaryLive($url);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->backlinksReferringDomainsLive($url);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->backlinksReferringNetworksLive($url);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);
}

// Test URL array endpoints with Backlinks API (make sure sandbox is enabled)
foreach ($urlArrays as $urlSet) {
    $result   = $dfs->backlinksBulkRanksLive($urlSet);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->backlinksBulkBacklinksLive($urlSet);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);

    $result   = $dfs->backlinksBulkSpamScoreLive($urlSet);
    $response = $result['response'];
    $body     = $response->body();
    echo "Response Body:\n";
    print_r($body);
}

$end = microtime(true);

echo "\n\nTime taken: " . ($end - $start) . ' seconds';
