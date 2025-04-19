<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;

/**
 * YouTube API Client for API Cache Library
 *
 * Handles YouTube Data API v3 requests and caching.
 */
class YouTubeApiClient extends BaseApiClient
{
    /**
     * Constructor for YouTubeApiClient
     *
     * @param ApiCacheManager|null $cacheManager Optional cache manager instance
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        Log::debug('Initializing YouTube API client');

        $clientName = 'youtube';

        parent::__construct(
            $clientName,
            config("api-cache.apis.{$clientName}.base_url"),
            config("api-cache.apis.{$clientName}.api_key"),
            config("api-cache.apis.{$clientName}.version"),
            $cacheManager
        );
    }

    /**
     * Override parent method since YouTube API uses API key as a query parameter, not as a header.
     *
     * @return array
     */
    public function getAuthHeaders(): array
    {
        return [
            'Accept' => 'application/json',
        ];
    }

    /**
     * Override parent method since YouTube API uses API key as a query parameter, not as a header.
     *
     * @return array
     */
    public function getAuthParams(): array
    {
        return [
            'key' => $this->apiKey,
        ];
    }

    /**
     * Search for YouTube resources (videos, channels, playlists)
     *
     * @param string      $q                Search term
     * @param string      $part             Comma-separated list of resource parts (default: 'snippet')
     * @param string      $type             Resource type (default: 'video')
     * @param int         $maxResults       Max results (default: 10)
     * @param string      $order            Order results by (date, rating, relevance, title, videoCount, viewCount)
     * @param string|null $safeSearch       Safe search setting (moderate, none, strict)
     * @param string|null $pageToken        Page token for pagination (optional)
     * @param string|null $publishedAfter   Restrict to resources published after this date/time (RFC 3339)
     * @param string|null $publishedBefore  Restrict to resources published before this date/time (RFC 3339)
     * @param array       $additionalParams Additional query params
     * @param string|null $attributes       Optional cache attributes
     * @param int         $amount           Rate limit amount
     *
     * @return array
     */
    public function search(
        string $q,
        string $part = 'snippet',
        string $type = 'video',
        int $maxResults = 10,
        string $order = 'relevance',
        ?string $safeSearch = null,
        ?string $pageToken = null,
        ?string $publishedAfter = null,
        ?string $publishedBefore = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        Log::debug('YouTubeApiClient: search', [
            'q'               => $q,
            'part'            => $part,
            'type'            => $type,
            'maxResults'      => $maxResults,
            'order'           => $order,
            'safeSearch'      => $safeSearch,
            'pageToken'       => $pageToken,
            'publishedAfter'  => $publishedAfter,
            'publishedBefore' => $publishedBefore,
        ]);

        $params = [
            'q'          => $q,
            'part'       => $part,
            'type'       => $type,
            'maxResults' => $maxResults,
            'order'      => $order,
        ];

        if ($safeSearch !== null) {
            $params['safeSearch'] = $safeSearch;
        }

        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        if ($publishedAfter !== null) {
            $params['publishedAfter'] = $publishedAfter;
        }

        if ($publishedBefore !== null) {
            $params['publishedBefore'] = $publishedBefore;
        }

        $params = array_merge($params, $this->getAuthParams(), $additionalParams);

        if ($attributes === null) {
            $attributes = $q;
        }

        return $this->sendCachedRequest('search', $params, 'GET', $attributes, $amount);
    }

    /**
     * Get details for YouTube videos
     *
     * @param string|null $id               Comma-separated list of YouTube video IDs (optional if chart is provided)
     * @param string|null $chart            Chart to retrieve, mutually exclusive with ID (e.g., 'mostPopular')
     * @param string      $part             Comma-separated list of resource parts (default: from config)
     * @param string|null $pageToken        Page token for pagination (optional)
     * @param int         $maxResults       Max results to return (default: set by YouTube API)
     * @param string|null $regionCode       ISO 3166-1 alpha-2 country code (optional)
     * @param array       $additionalParams Additional query params
     * @param string|null $attributes       Optional cache attributes
     * @param int         $amount           Rate limit amount
     *
     * @throws \InvalidArgumentException If both id and chart are empty or if both are provided
     *
     * @return array
     */
    public function videos(
        ?string $id = null,
        ?string $chart = null,
        ?string $part = null,
        ?string $pageToken = null,
        ?int $maxResults = null,
        ?string $regionCode = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // id and chart are mutually exclusive parameters
        if (empty($id) && $chart === null) {
            throw new \InvalidArgumentException('Either id or chart must be provided');
        }

        if (!empty($id) && $chart !== null) {
            throw new \InvalidArgumentException('id and chart cannot be used together');
        }

        $part = $part ?? config('api-cache.apis.youtube.video_parts');

        Log::debug('YouTubeApiClient: videos', [
            'id'         => $id,
            'chart'      => $chart,
            'part'       => $part,
            'pageToken'  => $pageToken,
            'maxResults' => $maxResults,
            'regionCode' => $regionCode,
        ]);

        $params = [
            'part' => $part,
        ];

        if (!empty($id)) {
            $params['id'] = $id;
        }

        if ($chart !== null) {
            $params['chart'] = $chart;
        }

        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        if ($maxResults !== null) {
            $params['maxResults'] = $maxResults;
        }

        if ($regionCode !== null) {
            $params['regionCode'] = $regionCode;
        }

        $params = array_merge($params, $this->getAuthParams(), $additionalParams);

        if ($attributes === null) {
            $attributes = !empty($id) ? $id : "chart:{$chart}";
        }

        return $this->sendCachedRequest('videos', $params, 'GET', $attributes, $amount);
    }
}
