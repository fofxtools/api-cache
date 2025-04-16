<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;

class DemoApiClient extends BaseApiClient
{
    /**
     * Create a new Demo API client instance
     *
     * @param ApiCacheManager|null $cacheManager Optional cache manager instance
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        Log::debug('Initializing Demo API client');

        $clientName = 'demo';

        parent::__construct(
            $clientName,
            config("api-cache.apis.{$clientName}.base_url"),
            config("api-cache.apis.{$clientName}.api_key"),
            config("api-cache.apis.{$clientName}.version"),
            $cacheManager
        );
    }

    /**
     * Get predictions based on query parameters
     *
     * @param string      $query            The search query
     * @param int         $maxResults       Maximum number of results to return
     * @param array       $additionalParams Additional parameters to include in the request
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @return array API response data
     */
    public function predictions(
        string $query,
        int $maxResults = 10,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        Log::debug('Making predictions request', [
            'client'     => $this->clientName,
            'query'      => $query,
            'maxResults' => $maxResults,
        ]);

        $params = array_merge($additionalParams, [
            'query'       => $query,
            'max_results' => $maxResults,
        ]);

        return $this->sendCachedRequest('predictions', $params, 'GET', $attributes, $amount);
    }

    /**
     * Get reports based on type and source
     *
     * @param string      $reportType       Type of reports to generate
     * @param string      $dataSource       Source of data for the reports
     * @param array       $additionalParams Additional parameters to include in the request
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @return array API response data
     */
    public function reports(
        string $reportType,
        string $dataSource,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        Log::debug('Making reports request', [
            'client'     => $this->clientName,
            'reportType' => $reportType,
            'dataSource' => $dataSource,
        ]);

        $params = array_merge($additionalParams, [
            'report_type' => $reportType,
            'data_source' => $dataSource,
        ]);

        return $this->sendCachedRequest('reports', $params, 'POST', $attributes, $amount);
    }
}
