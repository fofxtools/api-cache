<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;

class ScrapingdogApiClient extends BaseApiClient
{
    /**
     * Constructor for ScrapingdogApiClient
     *
     * @param ApiCacheManager|null $cacheManager Optional manager for caching and rate limiting
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        Log::debug('Initializing Scrapingdog API client');

        $clientName = 'scrapingdog';

        parent::__construct(
            $clientName,
            config("api-cache.apis.{$clientName}.base_url"),
            config("api-cache.apis.{$clientName}.api_key"),
            config("api-cache.apis.{$clientName}.version"),
            $cacheManager
        );
    }

    /**
     * Override parent method since Scrapingdog API uses query parameter authentication with 'api_key' parameter
     *
     * @return array Authentication headers
     */
    public function getAuthHeaders(): array
    {
        return [];
    }

    /**
     * Override parent method since Scrapingdog API uses query parameter authentication with 'api_key' parameter
     *
     * @return array Authentication parameters
     */
    public function getAuthParams(): array
    {
        return [
            'api_key' => $this->apiKey,
        ];
    }

    /**
     * Calculate the number of credits required for a request
     *
     * According to Scrapingdog documentation and request builder:
     * - Basic static scraping: 1 credit
     * - Dynamic (JS rendering) with normal proxies: 5 credits
     * - Premium residential proxies: 10 credits
     * - Dynamic + Premium: 25 credits
     * - Super Proxy: 75 credits
     * - AI Query or AI Extract Rules: +5 credits
     *
     * @param bool|null   $dynamic          Whether dynamic rendering is enabled
     * @param bool|null   $premium          Whether premium residential proxies are used
     * @param string|null $ai_query         User prompt to get AI-optimized response
     * @param array|null  $ai_extract_rules Rules for extracting data without parsing HTML
     * @param bool|null   $super_proxy      Whether to use super proxy
     *
     * @return int Number of credits required
     */
    public function calculateCredits(
        ?bool $dynamic = null,
        ?bool $premium = null,
        ?string $ai_query = null,
        ?array $ai_extract_rules = null,
        ?bool $super_proxy = null
    ): int {
        // Start with base credit cost
        $credits = 1;

        // Super proxy takes precedence over dynamic and premium
        if ($super_proxy === true) {
            $credits = 75;
        }
        // Dynamic + Premium combination has a fixed cost
        elseif ($dynamic === true && $premium === true) {
            $credits = 25;
        }
        // Individual feature costs
        elseif ($dynamic === true) {
            $credits = 5;
        } elseif ($premium === true) {
            $credits = 10;
        }

        // Add extra credits if AI is used
        if ($ai_query !== null || $ai_extract_rules !== null) {
            $credits += 5;
        }

        return $credits;
    }

    /**
     * Scrape a web page using Scrapingdog API
     *
     * @param string      $url              The URL to scrape
     * @param bool|null   $dynamic          Whether to use dynamic rendering (default: false)
     * @param bool|null   $premium          Whether to use premium residential proxies
     * @param bool|null   $custom_headers   Whether to allow passing custom headers
     * @param int|null    $wait             Wait time in milliseconds (0-35000) for JS rendering, used with dynamic=true
     * @param string|null $country          Country code for geo-location (e.g. "us", "gb")
     * @param string|null $session_number   Session ID to reuse the same proxy for multiple requests
     * @param bool|null   $image            Whether to scrape image URLs
     * @param bool|null   $markdown         Whether to get HTML data in markdown format
     * @param string|null $ai_query         User prompt to get AI-optimized response
     * @param array|null  $ai_extract_rules Rules for extracting data without parsing HTML
     * @param bool|null   $super_proxy      Whether to use super proxy
     * @param array       $additionalParams Additional parameters to pass to the API
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param int|null    $amount           Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @throws \InvalidArgumentException If mutually exclusive parameters are used together
     *
     * @return array The API response data
     */
    public function scrape(
        string $url,
        ?bool $dynamic = false,
        ?bool $premium = null,
        ?bool $custom_headers = null,
        ?int $wait = null,
        ?string $country = null,
        ?string $session_number = null,
        ?bool $image = null,
        ?bool $markdown = null,
        ?string $ai_query = null,
        ?array $ai_extract_rules = null,
        ?bool $super_proxy = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?int $amount = null
    ): array {
        // Check for mutually exclusive parameters
        if ($super_proxy === true && ($dynamic === true || $premium === true)) {
            throw new \InvalidArgumentException(
                'Super proxy cannot be used together with dynamic or premium parameters'
            );
        }

        Log::debug('Making Scrapingdog scrape request', [
            'url'              => $url,
            'dynamic'          => $dynamic,
            'premium'          => $premium,
            'custom_headers'   => $custom_headers,
            'wait'             => $wait,
            'country'          => $country,
            'session_number'   => $session_number,
            'image'            => $image,
            'markdown'         => $markdown,
            'ai_query'         => $ai_query,
            'ai_extract_rules' => $ai_extract_rules,
            'super_proxy'      => $super_proxy,
        ]);

        $originalParams = [
            'url' => $url,
        ];

        if ($dynamic !== null) {
            $originalParams['dynamic'] = $dynamic;
        }

        if ($premium !== null) {
            $originalParams['premium'] = $premium;
        }

        if ($custom_headers !== null) {
            $originalParams['custom_headers'] = $custom_headers;
        }

        if ($wait !== null) {
            $originalParams['wait'] = $wait;
        }

        if ($country !== null) {
            $originalParams['country'] = $country;
        }

        if ($session_number !== null) {
            $originalParams['session_number'] = $session_number;
        }

        if ($image !== null) {
            $originalParams['image'] = $image;
        }

        if ($markdown !== null) {
            $originalParams['markdown'] = $markdown;
        }

        if ($ai_query !== null) {
            $originalParams['ai_query'] = $ai_query;
        }

        if ($ai_extract_rules !== null) {
            $originalParams['ai_extract_rules'] = json_encode($ai_extract_rules);
        }

        if ($super_proxy !== null) {
            $originalParams['super_proxy'] = $super_proxy;
        }

        // Add additional parameters
        $params = array_merge($additionalParams, $originalParams);

        // Calculate credits required for this request if amount is not provided
        if ($amount === null) {
            $credits = $this->calculateCredits($dynamic, $premium, $ai_query, $ai_extract_rules, $super_proxy);

            Log::debug('Calculated credits for request', [
                'credits'          => $credits,
                'dynamic'          => $dynamic,
                'premium'          => $premium,
                'ai_query'         => $ai_query,
                'ai_extract_rules' => $ai_extract_rules,
                'super_proxy'      => $super_proxy,
            ]);
        } else {
            $credits = $amount;
        }

        // Pass URL as attributes if attributes is not provided
        $attributes = $url;

        return $this->sendCachedRequest('scrape', $params, 'GET', $attributes, $credits);
    }
}
