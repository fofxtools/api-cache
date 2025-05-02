<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;

/**
 * OpenPageRank API Client
 *
 * Client for interacting with the OpenPageRank API.
 *
 * @see https://www.domcop.com/openpagerank/documentation
 */
class OpenPageRankApiClient extends BaseApiClient
{
    /**
     * Create a new OpenPageRank API client instance
     *
     * @param ApiCacheManager|null $cacheManager Optional cache manager instance
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        Log::debug('Initializing OpenPageRank API client');

        $clientName = 'openpagerank';

        parent::__construct(
            $clientName,
            config("api-cache.apis.{$clientName}.base_url"),
            config("api-cache.apis.{$clientName}.api_key"),
            config("api-cache.apis.{$clientName}.version"),
            $cacheManager
        );
    }

    /**
     * Override the getAuthHeaders method to use the OpenPageRank API-OPR header for authentication
     *
     * @return array Authentication headers
     */
    public function getAuthHeaders(): array
    {
        return [
            'API-OPR' => $this->apiKey,
            'Accept'  => 'application/json',
        ];
    }

    /**
     * OpenPageRank API doesn't use query parameters for authentication
     *
     * @return array Empty array as no auth parameters are needed
     */
    public function getAuthParams(): array
    {
        return [];
    }

    /**
     * Get PageRank data for one or more domains
     *
     * @param array       $domains          Array of domain names to check (max 100 domains per request)
     * @param array       $additionalParams Additional parameters to include in the request
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException If too many domains are provided
     *
     * @return array API response data
     */
    public function getPageRank(
        array $domains,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate domains array - API allows max 100 domains per request
        if (count($domains) > 100) {
            throw new \InvalidArgumentException('Maximum of 100 domains allowed per request');
        }

        if (count($domains) === 0) {
            throw new \InvalidArgumentException('At least one domain must be provided');
        }

        Log::debug('Making OpenPageRank API request', [
            'domains' => $domains,
            'count'   => count($domains),
        ]);

        // Build parameters with domains array
        $originalParams = [
            'domains' => $domains,
        ];

        // Add additional parameters
        $params = array_merge($additionalParams, $originalParams);

        // Use a comma-separated list of domains as attributes if not provided
        if ($attributes === null) {
            $attributes = implode(',', $domains);
        }

        return $this->sendCachedRequest('getPageRank', $params, 'GET', $attributes, $amount);
    }
}
