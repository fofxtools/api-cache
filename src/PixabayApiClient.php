<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

    /**
     * Process unprocessed image responses and insert them into the pixabay_images table.
     *
     * @param int|null $limit Maximum number of rows to process. Null for unlimited.
     *
     * @return array{processed: int, duplicates: int} Statistics about the processing.
     */
    public function processResponses(?int $limit = 1): array
    {
        $tableName       = $this->getTableName();
        $imagesTableName = 'api_cache_' . $this->clientName . '_images';

        // Get the cache keys of unprocessed responses for images endpoint only
        $query = DB::table($tableName)
            ->whereNull('processed_at')
            ->where('endpoint', 'api')
            ->where('response_status_code', 200)
            ->select('id', 'key');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $responses = $query->get();

        Log::info('Processing ' . $responses->count() . ' Pixabay responses');

        $processed  = 0;
        $duplicates = 0;

        foreach ($responses as $response) {
            // Use cache manager to get the response body given the cache key
            $cachedResponse = $this->cacheManager->getCachedResponse($this->clientName, $response->key);
            $responseBody   = $cachedResponse['response']->body();

            try {
                $data = json_decode($responseBody, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Failed to decode response body: ' . json_last_error_msg());
                }

                $hasValidHits = isset($data['hits']) && is_array($data['hits']) && !empty($data['hits']);

                if ($hasValidHits) {
                    // Process images
                    $images = [];
                    foreach ($data['hits'] as $image) {
                        $images[] = [
                            'id'              => $image['id'],
                            'pageURL'         => $image['pageURL'] ?? null,
                            'type'            => $image['type'] ?? null,
                            'tags'            => $image['tags'] ?? null,
                            'previewURL'      => $image['previewURL'] ?? null,
                            'previewWidth'    => $image['previewWidth'] ?? null,
                            'previewHeight'   => $image['previewHeight'] ?? null,
                            'webformatURL'    => $image['webformatURL'] ?? null,
                            'webformatWidth'  => $image['webformatWidth'] ?? null,
                            'webformatHeight' => $image['webformatHeight'] ?? null,
                            'largeImageURL'   => $image['largeImageURL'] ?? null,
                            'fullHDURL'       => $image['fullHDURL'] ?? null,
                            'imageURL'        => $image['imageURL'] ?? null,
                            'vectorURL'       => $image['vectorURL'] ?? null,
                            'imageWidth'      => $image['imageWidth'] ?? null,
                            'imageHeight'     => $image['imageHeight'] ?? null,
                            'imageSize'       => $image['imageSize'] ?? null,
                            'views'           => $image['views'] ?? null,
                            'downloads'       => $image['downloads'] ?? null,
                            'collections'     => $image['collections'] ?? null,
                            'likes'           => $image['likes'] ?? null,
                            'comments'        => $image['comments'] ?? null,
                            'user_id'         => $image['user_id'] ?? null,
                            'user'            => $image['user'] ?? null,
                            'userImageURL'    => $image['userImageURL'] ?? null,
                            'updated_at'      => now(),
                        ];
                    }

                    if (!empty($images)) {
                        foreach (array_chunk($images, 100) as $chunk) {
                            try {
                                // Attempt bulk insert
                                $insertedCount = DB::table($imagesTableName)->insertOrIgnore($chunk);
                                $processed += $insertedCount;
                                $duplicates += count($chunk) - $insertedCount;
                            } catch (\Exception $e) {
                                Log::warning('insertOrIgnore() failed, falling back to updateOrInsert()', [
                                    'error' => $e->getMessage(),
                                ]);

                                // Fallback: Use updateOrInsert() in bulk
                                foreach ($chunk as $image) {
                                    DB::table($imagesTableName)->updateOrInsert(['id' => $image['id']], $image);
                                }
                                $processed += count($chunk);
                            }
                        }
                    }
                }

                // Mark response as processed and update status
                DB::table($tableName)
                    ->where('id', $response->id)
                    ->update([
                        'processed_at'     => now(),
                        'processed_status' => json_encode([
                            'status'     => 'OK',
                            'error'      => null,
                            'processed'  => $processed,
                            'duplicates' => $duplicates,
                        ]),
                    ]);
            } catch (\Exception $e) {
                Log::error('Failed to process Pixabay response', [
                    'response_id' => $response->id,
                    'error'       => $e->getMessage(),
                ]);

                // Update status with error but keep actual counts
                DB::table($tableName)
                    ->where('id', $response->id)
                    ->update([
                        'processed_at'     => now(),
                        'processed_status' => json_encode([
                            'status'     => 'ERROR',
                            'error'      => $e->getMessage(),
                            'processed'  => $processed,
                            'duplicates' => $duplicates,
                        ]),
                    ]);
            }
        }

        Log::info('Pixabay response processing completed', [
            'processed'           => $processed,
            'duplicates'          => $duplicates,
            'responses_processed' => $responses->count(),
        ]);

        return [
            'processed'  => $processed,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Download image(s) by ID and type
     *
     * @param int|null    $id    Image ID to download, or null for next undownloaded image
     * @param string      $type  Image type to download ('preview', 'webformat', 'largeImage', 'all')
     * @param string|null $proxy Optional proxy URL
     *
     * @throws \InvalidArgumentException
     *
     * @return array
     */
    public function downloadImage(?int $id = null, string $type = 'all', ?string $proxy = null): array
    {
        // Validate image type
        $validTypes = ['preview', 'webformat', 'largeImage', 'all'];
        if (!in_array($type, $validTypes)) {
            throw new \InvalidArgumentException('Invalid image type. Must be one of: ' . implode(', ', $validTypes));
        }

        $imagesTableName = 'api_cache_' . $this->clientName . '_images';

        // Get image record
        $query = DB::table($imagesTableName);
        if ($id) {
            $query->where('id', $id);
        } else {
            // Find first image with missing downloads for requested type
            $query->where(function ($q) use ($type) {
                if ($type === 'all') {
                    $q->whereNull('file_contents_preview')
                      ->orWhereNull('file_contents_webformat')
                      ->orWhereNull('file_contents_largeImage');
                } else {
                    $q->whereNull("file_contents_$type");
                }
            });
        }

        $image = $query->first();

        if ($id && !$image) {
            throw new \InvalidArgumentException("Image not found with ID: $id");
        }

        if (!$image) {
            return [
                'success'    => false,
                'message'    => 'No undownloaded images found',
                'downloaded' => [],
            ];
        }

        // Prepare types to download
        $typesToDownload = $type === 'all'
            ? ['preview', 'webformat', 'largeImage']
            : [$type];

        // Download images
        $downloaded = [];
        $errors     = [];

        foreach ($typesToDownload as $imageType) {
            // Skip if already downloaded
            if (!empty($image->{"file_contents_$imageType"})) {
                continue;
            }

            try {
                // Get URL from image record
                $url = $image->{"{$imageType}URL"};
                if (!$url) {
                    $errors[] = "No URL found for type: $imageType";

                    continue;
                }

                // Download using Laravel HTTP client
                $response = Http::timeout(10)
                    ->withOptions([
                        'verify' => false,
                        'proxy'  => $proxy ?: null,
                    ])
                    ->get($url);

                if (!$response->successful()) {
                    $errors[] = "Failed to download $imageType: HTTP {$response->status()}";

                    continue;
                }

                // Update database
                DB::table($imagesTableName)
                    ->where('id', $image->id)
                    ->update([
                        "file_contents_$imageType" => $response->body(),
                        "filesize_$imageType"      => strlen($response->body()),
                    ]);

                // Add to downloaded array with ID as key
                if (!isset($downloaded[$image->id])) {
                    $downloaded[$image->id] = [];
                }
                $downloaded[$image->id][] = $imageType;
            } catch (\Exception $e) {
                $errors[] = "Error downloading $imageType: " . $e->getMessage();
            }
        }

        // Count of all downloaded images and types
        $downloadedCount = count(array_merge(...array_values($downloaded)));

        // Return result
        return [
            'success' => empty($errors),
            'message' => empty($errors)
                ? "Successfully downloaded $downloadedCount images"
                : 'Errors: ' . implode(', ', $errors),
            'downloaded' => $downloaded,
        ];
    }
}
