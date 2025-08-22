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

        // Array of original parameters
        $originalParams = [
            'query'       => $query,
            'max_results' => $maxResults,
        ];

        // Original parameters are passed after $additionalParams. This allows them to override $additionalParams in case of a conflict.
        $params = array_merge($additionalParams, $originalParams);

        // Pass the query as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $query;
        }

        return $this->sendCachedRequest('predictions', $params, 'GET', $attributes, amount: $amount);
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

        // Array of original parameters
        $originalParams = [
            'report_type' => $reportType,
            'data_source' => $dataSource,
        ];

        // Original parameters are passed after $additionalParams. This allows them to override $additionalParams in case of a conflict.
        $params = array_merge($additionalParams, $originalParams);

        // Pass the report type and data source as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $reportType . ':' . $dataSource;
        }

        return $this->sendCachedRequest('reports', $params, 'POST', $attributes, amount: $amount);
    }
}
