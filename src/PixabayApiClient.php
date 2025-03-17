<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;

class PixabayApiClient extends BaseApiClient
{
    /**
     * Constructor for PixabayApiClient
     *
     * @param ApiCacheManager|null $cacheManager Optional manager for caching and rate limiting
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        Log::debug('Initializing Pixabay API client');

        $clientName = 'pixabay';

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
     * Pixabay API doesn't use Bearer token authentication
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
     * Pixabay API uses query parameter authentication with 'key' parameter
     *
     * @return array Authentication parameters
     */
    public function getAuthParams(): array
    {
        return [
            'key' => $this->apiKey,
        ];
    }

    /**
     * Search for images on Pixabay
     *
     * @param string|null $query         A URL encoded search term. If omitted (null), all images are returned. Max 100 chars.
     * @param string      $lang          Language code (cs, da, de, en, es, fr, id, it, hu, nl, no, pl, pt, ro, sk, fi, sv, tr, vi, th, bg, ru, el, ja, ko, zh)
     * @param string|null $id            Retrieve individual images by ID
     * @param string      $imageType     Filter results by image type (all, photo, illustration, vector)
     * @param string      $orientation   Filter results by orientation (all, horizontal, vertical)
     * @param string|null $category      Filter results by category (backgrounds, fashion, nature, science, education, feelings, health, people, religion, places, animals, industry, computer, food, sports, transportation, travel, buildings, business, music)
     * @param int         $minWidth      Minimum image width in pixels
     * @param int         $minHeight     Minimum image height in pixels
     * @param string|null $colors        Filter by color properties (grayscale, transparent, red, orange, yellow, green, turquoise, blue, lilac, pink, white, gray, black, brown)
     * @param bool        $editorsChoice Select images that have received an Editor's Choice award
     * @param bool        $safeSearch    A flag indicating that only images suitable for all ages should be returned
     * @param string      $order         How the results should be ordered (popular, latest)
     * @param int         $page          Returned search results are paginated
     * @param int         $perPage       Number of results per page (3 - 200)
     * @param string|null $callback      JSONP callback function name
     * @param bool        $pretty        Indent JSON output. This option should not be used in production.
     *
     * @return array The API response data
     */
    public function searchImages(
        ?string $query = null,
        string $lang = 'en',
        ?string $id = null,
        string $imageType = 'all',
        string $orientation = 'all',
        ?string $category = null,
        int $minWidth = 0,
        int $minHeight = 0,
        ?string $colors = null,
        bool $editorsChoice = false,
        bool $safeSearch = false,
        string $order = 'popular',
        int $page = 1,
        int $perPage = 20,
        ?string $callback = null,
        bool $pretty = false
    ): array {
        Log::debug('Making Pixabay image search request', [
            'query'          => $query,
            'lang'           => $lang,
            'id'             => $id,
            'image_type'     => $imageType,
            'orientation'    => $orientation,
            'category'       => $category,
            'min_width'      => $minWidth,
            'min_height'     => $minHeight,
            'colors'         => $colors,
            'editors_choice' => $editorsChoice,
            'safesearch'     => $safeSearch,
            'order'          => $order,
            'page'           => $page,
            'per_page'       => $perPage,
            'callback'       => $callback,
            'pretty'         => $pretty,
        ]);

        $params = [
            'q'              => $query,
            'lang'           => $lang,
            'id'             => $id,
            'image_type'     => $imageType,
            'orientation'    => $orientation,
            'category'       => $category,
            'min_width'      => $minWidth,
            'min_height'     => $minHeight,
            'colors'         => $colors,
            'editors_choice' => $editorsChoice,
            'safesearch'     => $safeSearch,
            'order'          => $order,
            'page'           => $page,
            'per_page'       => $perPage,
            'callback'       => $callback,
            'pretty'         => $pretty,
        ];

        return $this->sendCachedRequest('api', $params, 'GET');
    }

    /**
     * Search for videos on Pixabay
     *
     * @param string|null $query         A URL encoded search term. If omitted (null), all videos are returned. Max 100 chars.
     * @param string      $lang          Language code (cs, da, de, en, es, fr, id, it, hu, nl, no, pl, pt, ro, sk, fi, sv, tr, vi, th, bg, ru, el, ja, ko, zh)
     * @param string|null $id            Retrieve individual videos by ID
     * @param string      $videoType     Filter results by video type (all, film, animation)
     * @param string|null $category      Filter results by category (backgrounds, fashion, nature, science, education, feelings, health, people, religion, places, animals, industry, computer, food, sports, transportation, travel, buildings, business, music)
     * @param int         $minWidth      Minimum video width in pixels
     * @param int         $minHeight     Minimum video height in pixels
     * @param bool        $editorsChoice Select videos that have received an Editor's Choice award
     * @param bool        $safeSearch    A flag indicating that only videos suitable for all ages should be returned
     * @param string      $order         How the results should be ordered (popular, latest)
     * @param int         $page          Returned search results are paginated
     * @param int         $perPage       Number of results per page (3 - 200)
     * @param string|null $callback      JSONP callback function name
     * @param bool        $pretty        Indent JSON output. This option should not be used in production.
     *
     * @return array The API response data
     */
    public function searchVideos(
        ?string $query = null,
        string $lang = 'en',
        ?string $id = null,
        string $videoType = 'all',
        ?string $category = null,
        int $minWidth = 0,
        int $minHeight = 0,
        bool $editorsChoice = false,
        bool $safeSearch = false,
        string $order = 'popular',
        int $page = 1,
        int $perPage = 20,
        ?string $callback = null,
        bool $pretty = false
    ): array {
        Log::debug('Making Pixabay video search request', [
            'query'          => $query,
            'lang'           => $lang,
            'id'             => $id,
            'video_type'     => $videoType,
            'category'       => $category,
            'min_width'      => $minWidth,
            'min_height'     => $minHeight,
            'editors_choice' => $editorsChoice,
            'safesearch'     => $safeSearch,
            'order'          => $order,
            'page'           => $page,
            'per_page'       => $perPage,
            'callback'       => $callback,
            'pretty'         => $pretty,
        ]);

        $params = [
            'q'              => $query,
            'lang'           => $lang,
            'id'             => $id,
            'video_type'     => $videoType,
            'category'       => $category,
            'min_width'      => $minWidth,
            'min_height'     => $minHeight,
            'editors_choice' => $editorsChoice,
            'safesearch'     => $safeSearch,
            'order'          => $order,
            'page'           => $page,
            'per_page'       => $perPage,
            'callback'       => $callback,
            'pretty'         => $pretty,
        ];

        return $this->sendCachedRequest('api/videos', $params, 'GET');
    }
}
