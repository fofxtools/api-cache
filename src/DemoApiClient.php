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

        parent::__construct(
            'demo',
            config('api-cache.apis.demo.base_url'),
            config('api-cache.apis.demo.api_key'),
            'v1',
            $cacheManager
        );
    }

    /**
     * Builds the full URL for an endpoint
     *
     * @param string $endpoint The API endpoint (with or without leading slash)
     *
     * @return string The complete URL
     */
    public function buildUrl(string $endpoint): string
    {
        // Add demo-api-server.php and version to the path
        $url = $this->baseUrl . '/demo-api-server.php/' . $this->version . '/' . ltrim($endpoint, '/');

        Log::debug('Built URL for demo API request', [
            'client'   => $this->clientName,
            'endpoint' => $endpoint,
            'url'      => $url,
        ]);

        return $url;
    }

    /**
     * Get predictions based on query parameters
     *
     * @param string $query            The search query
     * @param int    $maxResults       Maximum number of results to return
     * @param array  $additionalParams Additional parameters to include in the request
     *
     * @return array API response data
     */
    public function prediction(
        string $query,
        int $maxResults = 10,
        array $additionalParams = []
    ): array {
        Log::debug('Making prediction request', [
            'client'     => $this->clientName,
            'query'      => $query,
            'maxResults' => $maxResults,
        ]);

        $params = array_merge($additionalParams, [
            'query'       => $query,
            'max_results' => $maxResults,
        ]);

        return $this->sendCachedRequest('predictions', $params, 'GET');
    }

    /**
     * Get report based on type and source
     *
     * @param string $reportType       Type of report to generate
     * @param string $dataSource       Source of data for the report
     * @param array  $additionalParams Additional parameters to include in the request
     *
     * @return array API response data
     */
    public function report(
        string $reportType,
        string $dataSource,
        array $additionalParams = []
    ): array {
        Log::debug('Making report request', [
            'client'     => $this->clientName,
            'reportType' => $reportType,
            'dataSource' => $dataSource,
        ]);

        $params = array_merge($additionalParams, [
            'report_type' => $reportType,
            'data_source' => $dataSource,
        ]);

        return $this->sendCachedRequest('reports', $params, 'POST');
    }
}
