<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

require_once __DIR__ . '/bootstrap.php';

/**
 * Run Zyte API client tests
 *
 * @param bool $compressionEnabled Whether to enable compression for the test
 * @param bool $requestInfo        Whether to enable request info
 * @param bool $responseInfo       Whether to enable response info
 * @param bool $testRateLimiting   Whether to test rate limiting
 *
 * @return void
 */
function runZyteTests(bool $compressionEnabled, bool $requestInfo = true, bool $responseInfo = true, bool $testRateLimiting = true): void
{
    echo "\nRunning Zyte API tests with compression " . ($compressionEnabled ? 'enabled' : 'disabled') . "...\n";
    echo str_repeat('-', 80) . "\n";

    // Test client configuration
    $clientName = 'zyte';
    config()->set("api-cache.apis.{$clientName}.rate_limit_max_attempts", 17);
    config()->set("api-cache.apis.{$clientName}.rate_limit_decay_seconds", 60);
    config()->set("api-cache.apis.{$clientName}.compression_enabled", $compressionEnabled);

    // Create response tables for the test client
    $dropExisting = false;
    createClientTables($clientName, $dropExisting);

    // Create client instance. Clear rate limit since we might have run tests before.
    $client = new ZyteApiClient();
    $client->setTimeout(120);
    $client->clearRateLimit();

    // Test extractCommon endpoint
    echo "\nTesting extractCommon endpoint...\n";
    $url = 'https://www.fiverr.com/categories';

    try {
        echo "URL: {$url}\n";
        echo "Extract common with browserHtml...\n";
        $result = $client->extractCommon($url, browserHtml: true);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractCommon: {$e->getMessage()}\n";
    }

    // Test extractBrowserHtml endpoint
    echo "\nTesting extractBrowserHtml endpoint...\n";

    try {
        $url = 'https://www.fiverr.com/categories/graphics-design/creative-logo-design';
        echo "URL: {$url}\n";
        $result = $client->extractBrowserHtml($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractBrowserHtml: {$e->getMessage()}\n";
    }

    // Test extractArticle endpoint
    echo "\nTesting extractArticle endpoint...\n";

    try {
        $url = 'https://www.cnn.com/2020/06/01/media/cnn-first-day-anniversary';
        echo "URL: {$url}\n";
        $result = $client->extractArticle($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractArticle: {$e->getMessage()}\n";
    }

    // Test extractArticleList endpoint
    echo "\nTesting extractArticleList endpoint...\n";

    try {
        $url = 'https://www.cnn.com/us';
        echo "URL: {$url}\n";
        $result = $client->extractArticleList($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractArticleList: {$e->getMessage()}\n";
    }

    // Test extractArticleNavigation endpoint
    echo "\nTesting extractArticleNavigation endpoint...\n";

    try {
        $url = 'https://www.nytimes.com/section/world';
        echo "URL: {$url}\n";
        $result = $client->extractArticleNavigation($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractArticleNavigation: {$e->getMessage()}\n";
    }

    // Test extractForumThread endpoint
    echo "\nTesting extractForumThread endpoint...\n";

    try {
        $url = 'https://www.reddit.com/r/reddit.com/comments/87/the_downing_street_memo/';
        echo "URL: {$url}\n";
        $result = $client->extractForumThread($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractForumThread: {$e->getMessage()}\n";
    }

    // Test extractJobPosting endpoint
    echo "\nTesting extractJobPosting endpoint...\n";

    try {
        $url = 'https://uk.indeed.com/career/paralegal-assistant/salaries/Datchet';
        echo "URL: {$url}\n";
        $result = $client->extractJobPosting($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractJobPosting: {$e->getMessage()}\n";
    }

    // Test extractJobPostingNavigation endpoint
    echo "\nTesting extractJobPostingNavigation endpoint...\n";

    try {
        $url = 'https://www.indeed.com/l-abanda,-al-jobs.html';
        echo "URL: {$url}\n";
        $result = $client->extractJobPostingNavigation($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractJobPostingNavigation: {$e->getMessage()}\n";
    }

    // Test extractPageContent endpoint
    echo "\nTesting extractPageContent endpoint...\n";

    try {
        $url = 'https://en.wikipedia.org/wiki/Nupedia';
        echo "URL: {$url}\n";
        $result = $client->extractPageContent($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractPageContent: {$e->getMessage()}\n";
    }

    // Test extractProduct endpoint
    echo "\nTesting extractProduct endpoint...\n";

    try {
        $url = 'https://www.amazon.com/dp/B00R92CL5E';
        echo "URL: {$url}\n";
        $result = $client->extractProduct($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractProduct: {$e->getMessage()}\n";
    }

    // Test extractProductList endpoint
    echo "\nTesting extractProductList endpoint...\n";

    try {
        $url = 'https://www.ebay.com/deals';
        echo "URL: {$url}\n";
        $result = $client->extractProductList($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractProductList: {$e->getMessage()}\n";
    }

    // Test extractProductNavigation endpoint
    echo "\nTesting extractProductNavigation endpoint...\n";

    try {
        $url = 'https://www.walmart.com/cp/electronics/3944';
        echo "URL: {$url}\n";
        $result = $client->extractProductNavigation($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractProductNavigation: {$e->getMessage()}\n";
    }

    // Test extractSerp endpoint
    echo "\nTesting extractSerp endpoint...\n";

    try {
        $url = 'https://www.google.com/search?q=speed+test&hl=en&gl=us';
        echo "URL: {$url}\n";
        $result = $client->extractSerp($url);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractSerp: {$e->getMessage()}\n";
    }

    // Test extractCustomAttributes endpoint
    echo "\nTesting extractCustomAttributes endpoint...\n";

    try {
        $url = 'https://www.zyte.com/blog/intercept-network-patterns-within-zyte-api/';
        echo "URL: {$url}\n";
        $customAttributes = [
            'summary' => [
                'type'        => 'string',
                'description' => 'A two sentence article summary',
            ],
            'article_sentiment' => [
                'type' => 'string',
                'enum' => ['positive', 'negative', 'neutral'],
            ],
        ];
        $result = $client->extractCustomAttributes($url, $customAttributes, 'article');
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing extractCustomAttributes: {$e->getMessage()}\n";
    }

    // Test screenshot endpoint
    echo "\nTesting screenshot endpoint...\n";

    try {
        $url = 'https://www.fiverr.com/categories';
        echo "URL: {$url}\n";
        $result = $client->screenshot($url, screenshotOptions: ['fullPage' => true]);
        echo format_api_response($result, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing screenshot: {$e->getMessage()}\n";
    }

    // Test cached request
    echo "\nTesting cached request...\n";

    try {
        $url = 'https://www.fiverr.com/categories';
        echo "\nExtracting browserHtml again for (should be cached): {$url}\n";
        $result2 = $client->extractBrowserHtml($url);
        echo format_api_response($result2, $requestInfo, $responseInfo);
    } catch (\Exception $e) {
        echo "Error testing caching: {$e->getMessage()}\n";
    }

    // Test rate limiting with caching disabled
    if ($testRateLimiting) {
        echo "\nTesting rate limiting with caching disabled...\n";
        $client->setUseCache(false);

        try {
            $url = 'https://www.fiverr.com/categories';
            for ($i = 1; $i <= 6; $i++) {
                echo "Request {$i}...\n";
                $result = $client->extractBrowserHtml($url);
                echo format_api_response($result, $requestInfo, $responseInfo);
            }
        } catch (RateLimitException $e) {
            echo "Successfully hit rate limit: {$e->getMessage()}\n";
            echo "Available in: {$e->getAvailableInSeconds()} seconds\n";
        }
    }

    echo "\nZyte API tests completed.\n";
}

$start = microtime(true);

$requestInfo      = false;
$responseInfo     = false;
$testRateLimiting = false;

// Run tests without compression
runZyteTests(false, $requestInfo, $responseInfo, $testRateLimiting);

// Run tests with compression
runZyteTests(true, $requestInfo, $responseInfo, $testRateLimiting);

$end = microtime(true);

echo 'Time taken: ' . ($end - $start) . " seconds\n";
