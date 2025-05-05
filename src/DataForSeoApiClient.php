<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;

class DataForSeoApiClient extends BaseApiClient
{
    /**
     * Constructor for DataForSeoApiClient
     *
     * @param ApiCacheManager|null $cacheManager Optional manager for caching and rate limiting
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        Log::debug('Initializing DataForSEO API client');

        $clientName = 'dataforseo';

        parent::__construct(
            $clientName,
            config("api-cache.apis.{$clientName}.base_url"),
            config("api-cache.apis.{$clientName}.api_key"),
            config("api-cache.apis.{$clientName}.version"),
            $cacheManager
        );
    }

    /**
     * Override parent method since DataForSEO API uses Basic Auth
     *
     * @return array Authentication headers
     */
    public function getAuthHeaders(): array
    {
        $login    = config("api-cache.apis.{$this->clientName}.DATAFORSEO_LOGIN");
        $password = config("api-cache.apis.{$this->clientName}.DATAFORSEO_PASSWORD");

        $credentials = base64_encode("{$login}:{$password}");

        return [
            'Authorization' => 'Basic ' . $credentials,
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Calculate the dollar cost of a DataForSEO API response
     *
     * Extracts the cost value from the response JSON, which is provided at the top level
     * of the API response.
     *
     * @param string|null $response The API response string (JSON)
     *
     * @return float|null The calculated cost, or null if not available
     */
    public function calculateCost(?string $response): ?float
    {
        $cost   = null;
        $source = null;

        if ($response !== null) {
            try {
                $data = json_decode($response, true);

                // Check if the response has a cost field
                if (isset($data['cost']) && is_numeric($data['cost'])) {
                    $cost   = (float) $data['cost'];
                    $source = 'response_body';
                }
            } catch (\Exception $e) {
                Log::error('Failed to calculate cost from DataForSEO response', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        } else {
            $source = 'null_response';
        }

        Log::debug('Calculated cost from DataForSEO response', [
            'cost'   => $cost,
            'source' => $source,
        ]);

        return $cost;
    }

    /**
     * Get Google Organic SERP results using DataForSEO's Live API with Regular endpoints
     *
     * @param string      $keyword             The search query
     * @param string|null $locationName        Location name (e.g., "United States")
     * @param int|null    $locationCode        Location code (e.g., 2840)
     * @param string|null $locationCoordinate  Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName        Language name (e.g., "English")
     * @param string|null $languageCode        Language code (e.g., "en")
     * @param string|null $device              Device type: "desktop" or "mobile"
     * @param string|null $os                  Operating system (windows, macos, android, ios)
     * @param string|null $seDomain            Search engine domain
     * @param int|null    $depth               Number of results in SERP (max 700)
     * @param string|null $target              Target domain, subdomain, or webpage to get results for
     * @param bool|null   $groupOrganicResults Group related results
     * @param int|null    $maxCrawlPages       Page crawl limit
     * @param string|null $searchParam         Additional parameters for search query
     * @param string|null $tag                 User-defined task identifier
     * @param array       $additionalParams    Additional parameters
     * @param string|null $attributes          Optional attributes to store with cache entry
     * @param int         $amount              Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function serpGoogleOrganicLiveRegular(
        string $keyword,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en',
        ?string $device = 'desktop',
        ?string $os = null,
        ?string $seDomain = null,
        ?int $depth = 100,
        ?string $target = null,
        ?bool $groupOrganicResults = true,
        ?int $maxCrawlPages = null,
        ?string $searchParam = null,
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate that at least one language parameter is provided
        if ($languageName === null && $languageCode === null) {
            throw new \InvalidArgumentException('Either languageName or languageCode must be provided');
        }

        // Validate that at least one location parameter is provided
        if ($locationName === null && $locationCode === null && $locationCoordinate === null) {
            throw new \InvalidArgumentException('Either locationName, locationCode, or locationCoordinate must be provided');
        }

        // Validate that depth is less than or equal to 700
        if ($depth > 700) {
            throw new \InvalidArgumentException('Depth must be less than or equal to 700');
        }

        Log::debug('Making DataForSEO Google Organic SERP live request', [
            'keyword'               => $keyword,
            'location_name'         => $locationName,
            'location_code'         => $locationCode,
            'location_coordinate'   => $locationCoordinate,
            'language_name'         => $languageName,
            'language_code'         => $languageCode,
            'device'                => $device,
            'os'                    => $os,
            'se_domain'             => $seDomain,
            'depth'                 => $depth,
            'target'                => $target,
            'group_organic_results' => $groupOrganicResults,
            'max_crawl_pages'       => $maxCrawlPages,
            'search_param'          => $searchParam,
            'tag'                   => $tag,
        ]);

        $originalParams = ['keyword' => $keyword];

        // Add optional parameters only if they're provided
        if ($locationName !== null) {
            $originalParams['location_name'] = $locationName;
        }

        if ($locationCode !== null) {
            $originalParams['location_code'] = $locationCode;
        }

        if ($locationCoordinate !== null) {
            $originalParams['location_coordinate'] = $locationCoordinate;
        }

        if ($languageName !== null) {
            $originalParams['language_name'] = $languageName;
        }

        if ($languageCode !== null) {
            $originalParams['language_code'] = $languageCode;
        }

        if ($device !== null) {
            $originalParams['device'] = $device;
        }

        if ($os !== null) {
            $originalParams['os'] = $os;
        }

        if ($seDomain !== null) {
            $originalParams['se_domain'] = $seDomain;
        }

        if ($depth !== null) {
            $originalParams['depth'] = $depth;
        }

        if ($target !== null) {
            $originalParams['target'] = $target;
        }

        if ($groupOrganicResults !== null) {
            $originalParams['group_organic_results'] = $groupOrganicResults;
        }

        if ($maxCrawlPages !== null) {
            $originalParams['max_crawl_pages'] = $maxCrawlPages;
        }

        if ($searchParam !== null) {
            $originalParams['search_param'] = $searchParam;
        }

        if ($tag !== null) {
            $originalParams['tag'] = $tag;
        }

        $params = array_merge($additionalParams, $originalParams);

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the query as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $keyword;
        }

        // Make the API request to the live endpoint
        return $this->sendCachedRequest(
            'serp/google/organic/live/regular',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get Google Organic SERP results using DataForSEO's Live API with Advanced endpoints
     *
     * @param string      $keyword                      The search query
     * @param string|null $url                          Direct URL of the search query
     * @param int|null    $depth                        Number of results in SERP (max 700)
     * @param int|null    $maxCrawlPages                Page crawl limit
     * @param string|null $locationName                 Location name (e.g., "United States")
     * @param int|null    $locationCode                 Location code (e.g., 2840)
     * @param string|null $locationCoordinate           Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName                 Language name (e.g., "English")
     * @param string|null $languageCode                 Language code (e.g., "en")
     * @param string|null $seDomain                     Search engine domain
     * @param string|null $device                       Device type: "desktop" or "mobile"
     * @param string|null $os                           Operating system (windows, macos, android, ios)
     * @param string|null $target                       Target domain, subdomain, or webpage to get results for
     * @param bool|null   $groupOrganicResults          Group related results
     * @param bool|null   $calculateRectangles          Calculate pixel rankings for SERP elements
     * @param int|null    $browserScreenWidth           Browser screen width for pixel rankings
     * @param int|null    $browserScreenHeight          Browser screen height for pixel rankings
     * @param int|null    $browserScreenResolutionRatio Browser screen resolution ratio
     * @param int|null    $peopleAlsoAskClickDepth      Clicks on the people_also_ask element
     * @param bool|null   $loadAsyncAiOverview          Load asynchronous AI overview
     * @param string|null $searchParam                  Additional parameters for search query
     * @param array|null  $removeFromUrl                Parameters to remove from URLs
     * @param string|null $tag                          User-defined task identifier
     * @param array       $additionalParams             Additional parameters
     * @param string|null $attributes                   Optional attributes to store with cache entry
     * @param int         $amount                       Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function serpGoogleOrganicLiveAdvanced(
        string $keyword,
        ?string $url = null,
        ?int $depth = 100,
        ?int $maxCrawlPages = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en',
        ?string $seDomain = null,
        ?string $device = 'desktop',
        ?string $os = null,
        ?string $target = null,
        ?bool $groupOrganicResults = true,
        ?bool $calculateRectangles = false,
        ?int $browserScreenWidth = null,
        ?int $browserScreenHeight = null,
        ?int $browserScreenResolutionRatio = null,
        ?int $peopleAlsoAskClickDepth = null,
        ?bool $loadAsyncAiOverview = false,
        ?string $searchParam = null,
        ?array $removeFromUrl = null,
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate that at least one language parameter is provided
        if ($languageName === null && $languageCode === null) {
            throw new \InvalidArgumentException('Either languageName or languageCode must be provided');
        }

        // Validate that at least one location parameter is provided, unless url is provided
        if ($url === null && $locationName === null && $locationCode === null && $locationCoordinate === null) {
            throw new \InvalidArgumentException('Either locationName, locationCode, or locationCoordinate must be provided when url is not specified');
        }

        // Validate that depth is less than or equal to 700
        if ($depth > 700) {
            throw new \InvalidArgumentException('Depth must be less than or equal to 700');
        }

        // Validate that peopleAlsoAskClickDepth is between 1 and 4 if provided
        if ($peopleAlsoAskClickDepth !== null && ($peopleAlsoAskClickDepth < 1 || $peopleAlsoAskClickDepth > 4)) {
            throw new \InvalidArgumentException('peopleAlsoAskClickDepth must be between 1 and 4');
        }

        Log::debug('Making DataForSEO Google Organic SERP live advanced request', [
            'keyword'                         => $keyword,
            'url'                             => $url,
            'depth'                           => $depth,
            'max_crawl_pages'                 => $maxCrawlPages,
            'location_name'                   => $locationName,
            'location_code'                   => $locationCode,
            'location_coordinate'             => $locationCoordinate,
            'language_name'                   => $languageName,
            'language_code'                   => $languageCode,
            'se_domain'                       => $seDomain,
            'device'                          => $device,
            'os'                              => $os,
            'target'                          => $target,
            'group_organic_results'           => $groupOrganicResults,
            'calculate_rectangles'            => $calculateRectangles,
            'browser_screen_width'            => $browserScreenWidth,
            'browser_screen_height'           => $browserScreenHeight,
            'browser_screen_resolution_ratio' => $browserScreenResolutionRatio,
            'people_also_ask_click_depth'     => $peopleAlsoAskClickDepth,
            'load_async_ai_overview'          => $loadAsyncAiOverview,
            'search_param'                    => $searchParam,
            'remove_from_url'                 => $removeFromUrl,
            'tag'                             => $tag,
        ]);

        $originalParams = ['keyword' => $keyword];

        // Add optional parameters only if they're provided
        if ($url !== null) {
            $originalParams['url'] = $url;
        }

        if ($depth !== null) {
            $originalParams['depth'] = $depth;
        }

        if ($maxCrawlPages !== null) {
            $originalParams['max_crawl_pages'] = $maxCrawlPages;
        }

        if ($locationName !== null) {
            $originalParams['location_name'] = $locationName;
        }

        if ($locationCode !== null) {
            $originalParams['location_code'] = $locationCode;
        }

        if ($locationCoordinate !== null) {
            $originalParams['location_coordinate'] = $locationCoordinate;
        }

        if ($languageName !== null) {
            $originalParams['language_name'] = $languageName;
        }

        if ($languageCode !== null) {
            $originalParams['language_code'] = $languageCode;
        }

        if ($seDomain !== null) {
            $originalParams['se_domain'] = $seDomain;
        }

        if ($device !== null) {
            $originalParams['device'] = $device;
        }

        if ($os !== null) {
            $originalParams['os'] = $os;
        }

        if ($target !== null) {
            $originalParams['target'] = $target;
        }

        if ($groupOrganicResults !== null) {
            $originalParams['group_organic_results'] = $groupOrganicResults;
        }

        if ($calculateRectangles !== null) {
            $originalParams['calculate_rectangles'] = $calculateRectangles;
        }

        if ($browserScreenWidth !== null) {
            $originalParams['browser_screen_width'] = $browserScreenWidth;
        }

        if ($browserScreenHeight !== null) {
            $originalParams['browser_screen_height'] = $browserScreenHeight;
        }

        if ($browserScreenResolutionRatio !== null) {
            $originalParams['browser_screen_resolution_ratio'] = $browserScreenResolutionRatio;
        }

        if ($peopleAlsoAskClickDepth !== null) {
            $originalParams['people_also_ask_click_depth'] = $peopleAlsoAskClickDepth;
        }

        if ($loadAsyncAiOverview !== null) {
            $originalParams['load_async_ai_overview'] = $loadAsyncAiOverview;
        }

        if ($searchParam !== null) {
            $originalParams['search_param'] = $searchParam;
        }

        if ($removeFromUrl !== null) {
            $originalParams['remove_from_url'] = $removeFromUrl;
        }

        if ($tag !== null) {
            $originalParams['tag'] = $tag;
        }

        $params = array_merge($additionalParams, $originalParams);

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the query as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $keyword;
        }

        // Make the API request to the live advanced endpoint
        return $this->sendCachedRequest(
            'serp/google/organic/live/advanced',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get Google Autocomplete suggestions using DataForSEO's Live API with Advanced endpoints
     *
     * @param string      $keyword          The search query
     * @param string|null $locationName     Location name (e.g., "United States")
     * @param int|null    $locationCode     Location code (e.g., 2840)
     * @param string|null $languageName     Language name (e.g., "English")
     * @param string|null $languageCode     Language code (e.g., "en")
     * @param int|null    $cursorPointer    The position of cursor pointer within the keyword
     * @param string|null $client           Search client for autocomplete (e.g., "gws-wiz-serp")
     * @param string|null $tag              User-defined task identifier
     * @param array       $additionalParams Additional parameters
     * @param string|null $attributes       Optional attributes to store with cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function serpGoogleAutocompleteLiveAdvanced(
        string $keyword,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $languageName = null,
        ?string $languageCode = 'en',
        ?int $cursorPointer = null,
        ?string $client = null,
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate that at least one language parameter is provided
        if ($languageName === null && $languageCode === null) {
            throw new \InvalidArgumentException('Either languageName or languageCode must be provided');
        }

        // Validate that at least one location parameter is provided
        if ($locationName === null && $locationCode === null) {
            throw new \InvalidArgumentException('Either locationName or locationCode must be provided');
        }

        Log::debug('Making DataForSEO Google Autocomplete SERP live request', [
            'keyword'        => $keyword,
            'location_name'  => $locationName,
            'location_code'  => $locationCode,
            'language_name'  => $languageName,
            'language_code'  => $languageCode,
            'cursor_pointer' => $cursorPointer,
            'client'         => $client,
            'tag'            => $tag,
        ]);

        $originalParams = ['keyword' => $keyword];

        // Add optional parameters only if they're provided
        if ($locationName !== null) {
            $originalParams['location_name'] = $locationName;
        }

        if ($locationCode !== null) {
            $originalParams['location_code'] = $locationCode;
        }

        if ($languageName !== null) {
            $originalParams['language_name'] = $languageName;
        }

        if ($languageCode !== null) {
            $originalParams['language_code'] = $languageCode;
        }

        if ($cursorPointer !== null) {
            $originalParams['cursor_pointer'] = $cursorPointer;
        }

        if ($client !== null) {
            $originalParams['client'] = $client;
        }

        if ($tag !== null) {
            $originalParams['tag'] = $tag;
        }

        $params = array_merge($additionalParams, $originalParams);

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the query as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $keyword;
        }

        // Make the API request to the live endpoint
        return $this->sendCachedRequest(
            'serp/google/autocomplete/live/advanced',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }
}
