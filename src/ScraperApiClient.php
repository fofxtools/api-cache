<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;
use Pdp\Rules;
use Pdp\Domain;
use FOfX\Helper;

class ScraperApiClient extends BaseApiClient
{
    /**
     * Constructor for ScraperApiClient
     *
     * @param ApiCacheManager|null $cacheManager Optional manager for caching and rate limiting
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        Log::debug('Initializing ScraperAPI client');

        $clientName = 'scraperapi';

        parent::__construct(
            $clientName,
            config("api-cache.apis.{$clientName}.base_url"),
            config("api-cache.apis.{$clientName}.api_key"),
            config("api-cache.apis.{$clientName}.version"),
            $cacheManager
        );
    }

    /**
     * Get authentication headers for the API request
     *
     * ScraperAPI doesn't use Bearer token authentication
     *
     * @return array Authentication headers
     */
    public function getAuthHeaders(): array
    {
        return [];
    }

    /**
     * Get authentication parameters for the API request
     *
     * ScraperAPI uses query parameter authentication with 'api_key' parameter
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
     * @param string $url              The URL to scrape
     * @param array  $additionalParams Additional parameters to calculate credits from
     *
     * @return int Number of credits required
     */
    protected function calculateCredits(string $url, array $additionalParams = []): int
    {
        // Use php-domain-parser to get the registrable domain
        $pslPath          = download_public_suffix_list();
        $publicSuffixList = Rules::fromPath($pslPath);

        // Extract hostname from URL if it contains a protocol
        $hostname = parse_url($url, PHP_URL_HOST) ?? $url;
        $domain   = Domain::fromIDNA2008($hostname);

        // Use registrableDomain() instead of domain() to get the registrable domain
        $result            = $publicSuffixList->resolve($domain);
        $registrableDomain = $result->registrableDomain()->toString();

        // Strip the www just in case
        // For some domains like www.httpbin.org, registrableDomain() seems to still retain the www. prefix
        $registrableDomain = Helper\strip_www($registrableDomain);

        // Define known domains and their credit costs
        $domainCredits = [
            // Amazon: 5 credits
            'amazon.com'    => 5,
            'amazon.co.uk'  => 5,
            'amazon.de'     => 5,
            'amazon.fr'     => 5,
            'amazon.it'     => 5,
            'amazon.es'     => 5,
            'amazon.ca'     => 5,
            'amazon.com.au' => 5,
            'amazon.co.jp'  => 5,
            'amazon.in'     => 5,

            // Walmart: 5 credits
            'walmart.com'    => 5,
            'walmart.co.uk'  => 5,
            'walmart.de'     => 5,
            'walmart.fr'     => 5,
            'walmart.it'     => 5,
            'walmart.es'     => 5,
            'walmart.ca'     => 5,
            'walmart.com.au' => 5,
            'walmart.co.jp'  => 5,
            'walmart.in'     => 5,

            // Search engines: 25 credits
            'google.com'    => 25,
            'google.co.uk'  => 25,
            'google.de'     => 25,
            'google.fr'     => 25,
            'google.it'     => 25,
            'google.es'     => 25,
            'google.ca'     => 25,
            'google.com.au' => 25,
            'google.co.jp'  => 25,
            'google.in'     => 25,

            'bing.com'    => 25,
            'bing.co.uk'  => 25,
            'bing.de'     => 25,
            'bing.fr'     => 25,
            'bing.it'     => 25,
            'bing.es'     => 25,
            'bing.ca'     => 25,
            'bing.com.au' => 25,
            'bing.co.jp'  => 25,
            'bing.in'     => 25,

            // Social media: 30 credits
            'linkedin.com' => 30,
            'twitter.com'  => 30,
            'x.com'        => 30,
        ];

        return $domainCredits[$registrableDomain] ?? 1;
    }

    /**
     * Scrape a URL using ScraperAPI
     *
     * @param string      $url              The URL to scrape
     * @param bool        $autoparse        Whether to automatically parse JSON responses
     * @param string|null $outputFormat     The output format (e.g. 'llm', 'json', etc.)
     * @param array       $additionalParams Additional parameters to pass to the API
     *
     * @return array The API response data
     */
    public function scrape(
        string $url,
        bool $autoparse = false,
        ?string $outputFormat = null,
        array $additionalParams = []
    ): array {
        Log::debug('Making ScraperAPI request', [
            'url'           => $url,
            'autoparse'     => $autoparse,
            'output_format' => $outputFormat,
        ]);

        $params = ['url' => $url];

        // Add autoparse if true
        if ($autoparse) {
            $params['autoparse'] = 'true';
        }

        // Add output format if provided
        if ($outputFormat) {
            $params['output_format'] = $outputFormat;
        }

        // Add additional parameters
        $params = array_merge($params, $additionalParams);

        // Calculate credits required for this request
        $credits = $this->calculateCredits($url, $params);

        // Pass the domain as attributes using php-domain-parser
        $pslPath = download_public_suffix_list();
        $publicSuffixList = Rules::fromPath($pslPath);
        $hostname = parse_url($url, PHP_URL_HOST) ?? $url;
        $domain = Domain::fromIDNA2008($hostname);
        $result = $publicSuffixList->resolve($domain);
        $registrableDomain = $result->registrableDomain()->toString();

        // Strip the www just in case
        $registrableDomain = Helper\strip_www($registrableDomain);

        // Pass registrableDomain as attributes
        return $this->sendCachedRequest('', $params, 'GET', $credits, $registrableDomain);
    }
}
