<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use FOfX\Helper\ReflectionUtils;

class DataForSeoApiClient extends BaseApiClient
{
    /**
     * Parameters that should never be forwarded to the API
     * - additionalParams: Used for internal parameter merging
     * - attributes: Used for cache key generation
     * - amount: Used for rate limiting
     */
    public array $excludedArgs = [
        'additionalParams',
        'attributes',
        'amount',
    ];

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
     * Determine if a response should be cached
     *
     * Overrides the parent method to handle DataForSEO specific logic.
     * Returns false if all tasks in the response resulted in errors.
     *
     * @param string|null $responseBody The response body
     *
     * @return bool Whether the response should be cached
     */
    public function shouldCache(?string $responseBody): bool
    {
        if ($responseBody === null) {
            return false;
        }

        try {
            $data = json_decode($responseBody, true);

            // Check if JSON is invalid
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                Log::debug('shouldCache() - Not caching DataForSEO response - invalid JSON', [
                    'client'       => $this->clientName,
                    'error'        => json_last_error_msg(),
                    'responseBody' => substr($responseBody, 0, 100) . (strlen($responseBody) > 100 ? '...' : ''),
                ]);

                return false;
            }

            // Check if this is an error response where all tasks failed
            if (isset($data['tasks_error']) &&
                isset($data['tasks_count']) &&
                $data['tasks_error'] >= 1 &&
                $data['tasks_error'] === $data['tasks_count']) {
                Log::debug('shouldCache() - Not caching DataForSEO response - all tasks have errors', [
                    'client'         => $this->clientName,
                    'tasks_error'    => $data['tasks_error'],
                    'tasks_count'    => $data['tasks_count'],
                    'status_code'    => $data['status_code'] ?? 'unknown',
                    'status_message' => $data['status_message'] ?? 'unknown',
                ]);

                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Error parsing response JSON in shouldCache', [
                'client' => $this->clientName,
                'error'  => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build API parameters from method arguments
     *
     * @param array $additionalParams Additional parameters to merge
     *
     * @return array Parameters ready for API request
     */
    public function buildApiParams(array $additionalParams = []): array
    {
        // Get caller's arguments
        $args = ReflectionUtils::extractBoundArgsFromBacktrace(2);

        // Remove excluded arguments
        foreach ($this->excludedArgs as $skip) {
            unset($args[$skip]);
        }

        // Convert to snake_case and drop nulls
        $params = [];
        foreach ($args as $name => $value) {
            if ($value !== null) {
                $params[Str::snake($name)] = $value;
            }
        }

        // Merge with additional params (original params take precedence)
        return array_merge($additionalParams, $params);
    }

    /**
     * Extract endpoint from DataForSEO response array
     *
     * This method extracts the endpoint path from a DataForSEO response by processing
     * the path segments and filtering out version identifiers and UUIDs.
     *
     * @param array $responseArray The DataForSEO response array containing task data
     *
     * @return string|null The extracted endpoint path, or null if not found
     */
    public function extractEndpoint(array $responseArray): ?string
    {
        $path = $responseArray['tasks'][0]['path'] ?? null;

        if (!is_array($path) || empty($path)) {
            return null;
        }

        $segments = $path;

        // Filter out any 'vD' segments with v followed by digits
        $segments = array_filter($segments, function ($segment) {
            return !preg_match('/^v\d+$/i', $segment);
        });

        // Filter out any UUID segments using Helper\is_valid_uuid
        $segments = array_filter($segments, function ($segment) {
            return !\FOfX\Helper\is_valid_uuid($segment);
        });

        return implode('/', array_values($segments));
    }

    /**
     * Extract parameters from DataForSEO response array
     *
     * This method extracts and normalizes the parameters from a DataForSEO response
     * task data section.
     *
     * @param array $responseArray The DataForSEO response array containing task data
     *
     * @return array|null The extracted and normalized parameters, or null if not found
     */
    public function extractParams(array $responseArray): ?array
    {
        $params = $responseArray['tasks'][0]['data'] ?? null;
        if (!is_array($params) || empty($params)) {
            return null;
        }

        return \FOfX\ApiCache\normalize_params($params);
    }

    /**
     * Resolve endpoint for a DataForSEO task
     *
     * This method attempts to determine the endpoint for a DataForSEO task by:
     * - Checking GET parameters
     * - Extracting from the response array (if provided)
     * - Looking up in the database
     *
     * @param string     $taskId        The DataForSEO task ID
     * @param array|null $responseArray The DataForSEO response array (optional)
     *
     * @throws \RuntimeException When endpoint cannot be determined
     *
     * @return string The resolved endpoint
     */
    public function resolveEndpoint(string $taskId, ?array $responseArray = null): string
    {
        // Try the GET parameter first
        $endpoint = $_GET['endpoint'] ?? null;
        if ($endpoint) {
            Log::debug('Resolved endpoint from GET parameter', ['endpoint' => $endpoint]);

            return $endpoint;
        }

        // Try to extract from the response array (only if provided)
        if ($responseArray !== null) {
            $endpoint = $this->extractEndpoint($responseArray);
            if ($endpoint) {
                Log::debug('Resolved endpoint from response array', ['endpoint' => $endpoint]);

                return $endpoint;
            }
        }

        // Try database lookup
        $endpoint = \Illuminate\Support\Facades\DB::table('api_cache_dataforseo_responses')
                      ->where('attributes', $taskId)
                      ->value('endpoint');

        if ($endpoint) {
            Log::debug('Resolved endpoint from database', ['endpoint' => $endpoint]);

            return $endpoint;
        }

        $jsonData = $responseArray ? json_encode($responseArray) : "Task ID: {$taskId}";

        throw new \RuntimeException('Cannot determine endpoint for task: ' . $jsonData);
    }

    /**
     * Log response data to a file for debugging purposes
     *
     * @param string $filename  The base filename (without extension)
     * @param string $idMessage The log message identifier
     * @param mixed  $data      The data to log
     */
    public function logResponse(string $filename, string $idMessage, mixed $data): void
    {
        $logFile  = __DIR__ . "/../storage/logs/{$filename}.log";
        $logEntry = PHP_EOL . date('Y-m-d H:i:s') . ': ' . $idMessage . PHP_EOL . '---------' . PHP_EOL . print_r($data, true) . PHP_EOL . '---------';

        @file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

    /**
     * Throw an error with logging
     *
     * @param int         $httpCode  The HTTP status code
     * @param string      $message   The error message
     * @param string      $errorType The error type for logging
     * @param string|null $response  Optional response data
     *
     * @throws \RuntimeException Always throws to indicate the error
     */
    public function throwErrorWithLogging(int $httpCode, string $message, string $errorType, ?string $response = null): never
    {
        $context = [
            'http_code'   => $httpCode,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address'  => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        // Use client's error logging infrastructure
        $this->logApiError($errorType, $message, $context, $response);

        throw new \RuntimeException("API error ({$httpCode}): {$message}");
    }

    /**
     * Validate HTTP method
     *
     * @param string      $expectedMethod The expected HTTP method (default: 'POST')
     * @param string|null $errorType      Optional error type for logging
     *
     * @throws \RuntimeException If the method does not match expected
     */
    public function validateHttpMethod(string $expectedMethod = 'POST', ?string $errorType = null): void
    {
        $actualMethod = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($actualMethod !== $expectedMethod) {
            $this->throwErrorWithLogging(405, 'Method not allowed', $errorType ?? 'webhook_invalid_method');
        }
    }

    /**
     * Validate client IP against whitelist
     *
     * @param string|null $errorType Optional error type for logging
     *
     * @throws \RuntimeException If IP is not whitelisted
     */
    public function validateIpWhitelist(?string $errorType = null): void
    {
        $allowedIps = config('api-cache.apis.dataforseo.whitelisted_ips');

        if (empty($allowedIps)) {
            return; // No whitelist = allow all
        }

        // Multi-header IP check for Cloudflare/proxy environments
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
                 ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                 ?? $_SERVER['REMOTE_ADDR']
                 ?? '';

        if (!in_array($clientIp, $allowedIps, true)) {
            $this->throwErrorWithLogging(403, "IP not whitelisted: $clientIp", $errorType ?? 'webhook_ip_not_whitelisted');
        }
    }

    /**
     * Get the results of a previously submitted task using DataForSEO's Task GET endpoints
     *
     * This generic method works with any DataForSEO Task GET endpoint, including:
     * - serp/google/organic/task_get/regular
     * - serp/google/organic/task_get/advanced
     * - serp/youtube/organic/task_get/advanced
     * - merchant/amazon/products/task_get/advanced
     * - Any other endpoint that follows the format endpoint/task_get/type/{id}
     *
     * @param string      $endpointPath The endpoint path (e.g., 'serp/google/organic/task_get/regular')
     * @param string      $id           The task ID to retrieve
     * @param string|null $attributes   Optional attributes to store with cache entry
     * @param int         $amount       Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException If the endpoint path is invalid or task ID is empty
     *
     * @return array The API response data
     */
    public function taskGet(
        string $endpointPath,
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate endpointPath format
        if (!preg_match('#^[a-z0-9_/]+/task_get/[a-z]+$#i', $endpointPath)) {
            throw new \InvalidArgumentException('Invalid endpoint path format. Expected format: path/to/task_get/type');
        }

        // Validate task ID
        if (empty($id)) {
            throw new \InvalidArgumentException('Task ID cannot be empty');
        }

        // Add the caller method, if any, to the extracted arguments
        $callerMethod = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? null;
        $args         = ['caller_method' => $callerMethod] + ReflectionUtils::extractArgs(__METHOD__, get_defined_vars());

        Log::debug('Making DataForSEO task_get request', $args);

        // Pass the task ID as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $id;
        }

        // Build the full endpoint URL with ID
        $fullEndpoint = "{$endpointPath}/{$id}";

        // Make the API request to the task_get endpoint
        return $this->sendCachedRequest(
            $fullEndpoint,
            [],
            'GET',
            $attributes,
            $amount
        );
    }

    /**
     * Process postback response from DataForSEO webhook
     *
     * @param string|null $logFilename Optional filename for response logging
     * @param string|null $errorType   Optional error type for logging
     * @param string|null $rawData     Optional raw data for testing
     *
     * @throws \RuntimeException If response is invalid
     *
     * @return array Array containing [responseArray, task, taskId, cacheKey, cost, jsonData, endpoint, method]
     */
    public function processPostbackResponse(?string $logFilename = null, ?string $errorType = null, ?string $rawData = null): array
    {
        $rawData = $rawData ?? file_get_contents('php://input');

        if (empty($rawData)) {
            $this->throwErrorWithLogging(400, 'Empty POST data', $errorType ?? 'postback_empty_response');
        }

        // Handle DataForSEO gzip compression
        $decompressed = gzdecode($rawData);
        $jsonData     = $decompressed !== false ? $decompressed : $rawData;

        if (!json_validate($jsonData)) {
            $this->throwErrorWithLogging(400, 'Invalid JSON', $errorType ?? 'postback_invalid_json', $jsonData);
        }

        $responseArray = json_decode($jsonData, true);
        $statusCode    = $responseArray['status_code'] ?? 0;

        if ($statusCode !== 20000) {
            $this->throwErrorWithLogging(400, 'DataForSEO error response', $errorType ?? 'postback_api_error', $jsonData);
        }

        // Log response if filename provided
        if ($logFilename !== null) {
            $this->logResponse($logFilename, 'result', $responseArray);
        }

        $task     = $responseArray['tasks'][0] ?? null;
        $taskId   = $task['id'] ?? null;
        $cost     = $responseArray['cost'] ?? null;
        $cacheKey = $task['data']['tag'] ?? null;
        $params   = $this->extractParams($responseArray);
        $endpoint = $this->resolveEndpoint($taskId, $responseArray);
        $method   = 'POST';

        if (!$task) {
            $this->throwErrorWithLogging(400, 'No task data in response', $errorType ?? 'postback_no_task_data', $jsonData);
        }

        if (!$taskId) {
            $this->throwErrorWithLogging(400, 'Missing task ID', $errorType ?? 'postback_missing_task_id', $jsonData);
        }

        if (!$cacheKey) {
            $cacheKey = $this->cacheManager->generateCacheKey(
                $this->clientName,
                $endpoint,
                $params,
                $method,
                $this->version
            );
        }

        return [$responseArray, $task, $taskId, $cacheKey, $cost, $jsonData, $endpoint, $method];
    }

    /**
     * Process pingback response from DataForSEO webhook
     *
     * @param string|null $logFilename Optional filename for response logging
     * @param string|null $errorType   Optional error type for logging
     *
     * @throws \RuntimeException If response is invalid
     *
     * @return array Array containing [responseArray, task, taskId, cacheKey, cost, jsonData, storageEndpoint, method]
     */
    public function processPingbackResponse(?string $logFilename = null, ?string $errorType = null): array
    {
        $taskId          = $_GET['id'] ?? null;
        $tag             = $_GET['tag'] ?? null;
        $taskGetEndpoint = $_GET['endpoint'] ?? null;

        if (!$taskId) {
            $this->throwErrorWithLogging(400, 'Missing task ID in pingback', $errorType ?? 'pingback_missing_task_id');
        }

        if (!$taskGetEndpoint) {
            $this->throwErrorWithLogging(400, 'Missing endpoint in pingback', $errorType ?? 'pingback_missing_endpoint');
        }

        // If tag is literal '$tag', don't use it as attributes
        if ($tag === '$tag') {
            $attributes = null;
        } else {
            $attributes = $tag;
        }

        // Make the taskGet call
        $taskGetResult = $this->taskGet($taskGetEndpoint, $taskId, $attributes);

        // Extract the response array from the response body
        $jsonData = $taskGetResult['response']->body();

        if (!json_validate($jsonData)) {
            $this->throwErrorWithLogging(400, 'Invalid JSON', $errorType ?? 'pingback_invalid_json', $jsonData);
        }

        $responseArray = json_decode($jsonData, true);
        $statusCode    = $responseArray['status_code'] ?? 0;
        $task          = $responseArray['tasks'][0] ?? null;
        $taskId        = $task['id'] ?? null;
        $cost          = $taskGetResult['request']['cost'] ?? null;
        $tag           = $task['data']['tag'] ?? null;
        $params        = $this->extractParams($responseArray);
        $method        = 'GET';

        if ($statusCode !== 20000) {
            $this->throwErrorWithLogging(400, 'DataForSEO error response', $errorType ?? 'pingback_api_error', $jsonData);
        }

        // Use resolveEndpoint with response array for storage purposes
        $storageEndpoint = $this->resolveEndpoint($taskId, $responseArray);

        // Log pingback if filename provided
        if ($logFilename !== null) {
            $this->logResponse($logFilename, 'get', $_GET);
            $this->logResponse($logFilename, 'result', $responseArray);
        }

        if (!$task) {
            $this->throwErrorWithLogging(400, 'No task data in response', $errorType ?? 'pingback_no_task_data', $jsonData);
        }

        if (!$taskId) {
            $this->throwErrorWithLogging(400, 'Missing task ID', $errorType ?? 'pingback_missing_task_id', $jsonData);
        }

        if (!$tag) {
            $cacheKey = $this->cacheManager->generateCacheKey(
                $this->clientName,
                $storageEndpoint,
                $params,
                $method,
                $this->version
            );
        } else {
            $cacheKey = $tag;
        }

        return [$responseArray, $task, $taskId, $cacheKey, $cost, $jsonData, $storageEndpoint, $method];
    }

    /**
     * Store webhook response in cache
     *
     * @param array      $responseArray   The DataForSEO response array
     * @param string     $cacheKey        The cache key for storage
     * @param string     $endpoint        The API endpoint
     * @param float|null $cost            The API call cost
     * @param string     $taskId          The task ID
     * @param string     $rawResponseData The raw response data
     * @param string     $httpMethod      The HTTP method used (default: 'POST')
     */
    public function storeInCache(
        array $responseArray,
        string $cacheKey,
        string $endpoint,
        ?float $cost,
        string $taskId,
        string $rawResponseData,
        string $httpMethod = 'POST'
    ): void {
        $manager = app(\FOfX\ApiCache\ApiCacheManager::class);

        $response = new \Illuminate\Http\Client\Response(
            new \GuzzleHttp\Psr7\Response(
                200,
                ['Content-Type' => 'application/json'],
                $rawResponseData
            )
        );

        $params = $this->extractParams($responseArray);

        $apiResult = [
            'params'  => [],
            'request' => [
                'method'     => $httpMethod,
                'base_url'   => null,
                'full_url'   => null,
                'headers'    => null, // We don't have original outbound headers
                'body'       => null, // We don't have original request body in webhook context
                'attributes' => $taskId,
                'cost'       => $cost,
            ],
            'response'             => $response,
            'response_status_code' => 200,
            'response_size'        => strlen($rawResponseData),
            'response_time'        => null,
            'is_cached'            => false,
        ];

        $manager->storeResponse(
            $this->getClientName(),
            $cacheKey,
            $params,
            $apiResult,
            $endpoint,
            attributes: $taskId
        );
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

        Log::debug(
            'Making DataForSEO Google Organic SERP live request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        $params = $this->buildApiParams($additionalParams);

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

        Log::debug(
            'Making DataForSEO Google Organic SERP live advanced request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        $params = $this->buildApiParams($additionalParams);

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

        Log::debug(
            'Making DataForSEO Google Autocomplete SERP live request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        $params = $this->buildApiParams($additionalParams);

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

    /**
     * Get Amazon Bulk Search Volume data using DataForSEO Labs API
     *
     * @param array       $keywords         Target keywords array (max 1000)
     * @param string|null $locationName     Location name (e.g., "United States")
     * @param int|null    $locationCode     Location code (e.g., 2840)
     * @param string|null $languageName     Language name (e.g., "English")
     * @param string|null $languageCode     Language code (e.g., "en")
     * @param string|null $tag              User-defined task identifier
     * @param array       $additionalParams Additional parameters
     * @param string|null $attributes       Optional attributes to store with cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function labsAmazonBulkSearchVolumeLive(
        array $keywords,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $languageName = null,
        ?string $languageCode = 'en',
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Check if keywords array is not empty
        if (empty($keywords)) {
            throw new \InvalidArgumentException('Keywords array cannot be empty');
        }

        // Check if keywords array length is within limits
        if (count($keywords) > 1000) {
            throw new \InvalidArgumentException('Maximum number of keywords is 1000');
        }

        // Validate that at least one language parameter is provided
        if ($languageName === null && $languageCode === null) {
            throw new \InvalidArgumentException('Either languageName or languageCode must be provided');
        }

        // Validate that at least one location parameter is provided
        if ($locationName === null && $locationCode === null) {
            throw new \InvalidArgumentException('Either locationName or locationCode must be provided');
        }

        // Extract args but without keywords, and add keywords_count
        $args = ['keywords_count' => count($keywords)] + ReflectionUtils::extractArgs(__METHOD__, get_defined_vars(), ['keywords']);

        Log::debug('Making DataForSEO Labs Amazon Bulk Search Volume request', $args);

        $params = $this->buildApiParams($additionalParams);

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Use concatenation of keywords as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = implode(',', $keywords);
        }

        // Make the API request to the labs endpoint
        return $this->sendCachedRequest(
            'dataforseo_labs/amazon/bulk_search_volume/live',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get Amazon Related Keywords data using DataForSEO Labs API
     *
     * Pricing: $0.01 per task + $0.0001 per returned keyword
     *
     * Cost breakdown by depth level (if max possible keywords returned):
     * - Depth 0: $0.0101 (1 keyword)
     * - Depth 1: $0.0106 (6 keywords)
     * - Depth 2: $0.0142 (42 keywords)
     * - Depth 3: $0.0402 (258 keywords)
     * - Depth 4: $0.1702 (1554 keywords)
     *
     * @param string      $keyword            Seed keyword
     * @param string|null $locationName       Location name (e.g., "United States")
     * @param int|null    $locationCode       Location code (e.g., 2840)
     * @param string|null $languageName       Language name (e.g., "English")
     * @param string|null $languageCode       Language code (e.g., "en")
     * @param int|null    $depth              Keyword search depth level (0-4)
     * @param bool|null   $includeSeedKeyword Include data for the seed keyword
     * @param bool|null   $ignoreSynonyms     Ignore highly similar keywords
     * @param int|null    $limit              Maximum number of returned keywords (max 1000)
     * @param int|null    $offset             Offset in the results array
     * @param string|null $tag                User-defined task identifier
     * @param array       $additionalParams   Additional parameters
     * @param string|null $attributes         Optional attributes to store with cache entry
     * @param int         $amount             Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function labsAmazonRelatedKeywordsLive(
        string $keyword,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $languageName = null,
        ?string $languageCode = 'en',
        ?int $depth = 2,
        ?bool $includeSeedKeyword = null,
        ?bool $ignoreSynonyms = null,
        ?int $limit = null,
        ?int $offset = null,
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate that keyword is not empty
        if (empty($keyword)) {
            throw new \InvalidArgumentException('Keyword cannot be empty');
        }

        // Validate that at least one language parameter is provided
        if ($languageName === null && $languageCode === null) {
            throw new \InvalidArgumentException('Either languageName or languageCode must be provided');
        }

        // Validate that at least one location parameter is provided
        if ($locationName === null && $locationCode === null) {
            throw new \InvalidArgumentException('Either locationName or locationCode must be provided');
        }

        // Validate depth parameter is within allowed range
        if ($depth !== null && ($depth < 0 || $depth > 4)) {
            throw new \InvalidArgumentException('Depth must be between 0 and 4');
        }

        // Validate limit parameter is within allowed range
        if ($limit !== null && ($limit < 1 || $limit > 1000)) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000');
        }

        Log::debug(
            'Making DataForSEO Labs Amazon Related Keywords request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        $params = $this->buildApiParams($additionalParams);

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the query as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $keyword;
        }

        // Make the API request to the labs endpoint
        return $this->sendCachedRequest(
            'dataforseo_labs/amazon/related_keywords/live',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get Amazon Ranked Keywords data using DataForSEO Labs API
     *
     * Returns keywords that the specified product (ASIN) ranks for on Amazon.
     *
     * @param string      $asin             Amazon product ID (ASIN)
     * @param string|null $locationName     Location name (e.g., "United States")
     * @param int|null    $locationCode     Location code (e.g., 2840)
     * @param string|null $languageName     Language name (e.g., "English")
     * @param string|null $languageCode     Language code (e.g., "en")
     * @param int|null    $limit            Maximum number of returned keywords (max 1000)
     * @param bool|null   $ignoreSynonyms   Ignore highly similar keywords
     * @param array|null  $filters          Array of results filtering parameters
     * @param array|null  $orderBy          Results sorting rules
     * @param int|null    $offset           Offset in the results array
     * @param string|null $tag              User-defined task identifier
     * @param array       $additionalParams Additional parameters
     * @param string|null $attributes       Optional attributes to store with cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function labsAmazonRankedKeywordsLive(
        string $asin,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $languageName = null,
        ?string $languageCode = 'en',
        ?int $limit = null,
        ?bool $ignoreSynonyms = null,
        ?array $filters = null,
        ?array $orderBy = null,
        ?int $offset = null,
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate that ASIN is not empty
        if (empty($asin)) {
            throw new \InvalidArgumentException('ASIN cannot be empty');
        }

        // Validate that at least one language parameter is provided
        if ($languageName === null && $languageCode === null) {
            throw new \InvalidArgumentException('Either languageName or languageCode must be provided');
        }

        // Validate that at least one location parameter is provided
        if ($locationName === null && $locationCode === null) {
            throw new \InvalidArgumentException('Either locationName or locationCode must be provided');
        }

        // Validate limit parameter is within allowed range
        if ($limit !== null && ($limit < 1 || $limit > 1000)) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000');
        }

        Log::debug(
            'Making DataForSEO Labs Amazon Ranked Keywords request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        $params = $this->buildApiParams($additionalParams);

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the ASIN as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $asin;
        }

        // Make the API request to the labs endpoint
        return $this->sendCachedRequest(
            'dataforseo_labs/amazon/ranked_keywords/live',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get OnPage data from DataForSEO's Instant Pages API
     *
     * @param string      $url                      Target page URL (required)
     * @param string|null $customUserAgent          Custom user agent for crawling a website
     * @param string|null $browserPreset            Preset for browser screen parameters ("desktop", "mobile", "tablet")
     * @param int|null    $browserScreenWidth       Browser screen width in pixels (min: 240, max: 9999)
     * @param int|null    $browserScreenHeight      Browser screen height in pixels (min: 240, max: 9999)
     * @param float|null  $browserScreenScaleFactor Browser screen scale factor (min: 0.5, max: 3)
     * @param bool|null   $storeRawHtml             Store HTML of a crawled page
     * @param string|null $acceptLanguage           Language header for accessing the website
     * @param bool|null   $loadResources            Load image, stylesheets, scripts, and broken resources
     * @param bool|null   $enableJavascript         Load javascript on a page
     * @param bool|null   $enableBrowserRendering   Emulate browser rendering to measure Core Web Vitals
     * @param bool|null   $disableCookiePopup       Disable the cookie popup
     * @param bool|null   $returnDespiteTimeout     Return data on pages despite the timeout error
     * @param bool|null   $enableXhr                Enable XMLHttpRequest on a page
     * @param string|null $customJs                 Custom javascript
     * @param bool|null   $validateMicromarkup      Enable microdata validation
     * @param bool|null   $checkSpell               Check spelling
     * @param array|null  $checksThreshold          Custom threshold values for checks
     * @param bool|null   $switchPool               Switch proxy pool
     * @param string|null $ipPoolForScan            Proxy pool location ('us', 'de')
     * @param array       $additionalParams         Additional parameters
     * @param string|null $attributes               Optional attributes to store with cache entry
     * @param int         $amount                   Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function onPageInstantPages(
        string $url,
        ?string $customUserAgent = null,
        ?string $browserPreset = null,
        ?int $browserScreenWidth = null,
        ?int $browserScreenHeight = null,
        ?float $browserScreenScaleFactor = null,
        ?bool $storeRawHtml = null,
        ?string $acceptLanguage = null,
        ?bool $loadResources = null,
        ?bool $enableJavascript = null,
        ?bool $enableBrowserRendering = null,
        ?bool $disableCookiePopup = null,
        ?bool $returnDespiteTimeout = null,
        ?bool $enableXhr = null,
        ?string $customJs = null,
        ?bool $validateMicromarkup = null,
        ?bool $checkSpell = null,
        ?array $checksThreshold = null,
        ?bool $switchPool = null,
        ?string $ipPoolForScan = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        Log::debug(
            'Making DataForSEO OnPage Instant Pages Live request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        $params = $this->buildApiParams($additionalParams);

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the URL as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $url;
        }

        // Make the API request to the onpage/instant_pages endpoint
        return $this->sendCachedRequest(
            'on_page/instant_pages',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get raw HTML from DataForSEO's OnPage API
     *
     * @param string      $id               ID of the task (required)
     * @param string|null $url              Page URL (required if not set in Instant Pages task)
     * @param array       $additionalParams Additional parameters
     * @param string|null $attributes       Optional attributes to store with cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function onPageRawHtml(
        string $id,
        ?string $url = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        Log::debug(
            'Making DataForSEO OnPage Raw HTML request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        $params = $this->buildApiParams($additionalParams);

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the ID as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $id;
        }

        // Make the API request to the onpage/raw_html endpoint
        return $this->sendCachedRequest(
            'on_page/raw_html',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Create a Google Organic SERP task using DataForSEO's Task POST endpoint
     *
     * @param string      $keyword                      The search query
     * @param string|null $url                          Direct URL of the search query
     * @param int|null    $priority                     Task priority (1 - normal, 2 - high)
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
     * @param bool|null   $groupOrganicResults          Group related results
     * @param bool|null   $calculateRectangles          Calculate pixel rankings for SERP elements
     * @param int|null    $browserScreenWidth           Browser screen width for pixel rankings
     * @param int|null    $browserScreenHeight          Browser screen height for pixel rankings
     * @param int|null    $browserScreenResolutionRatio Browser screen resolution ratio
     * @param int|null    $peopleAlsoAskClickDepth      Clicks on the people_also_ask element
     * @param bool|null   $loadAsyncAiOverview          Load asynchronous AI overview
     * @param bool|null   $expandAiOverview             Expand AI overview
     * @param string|null $searchParam                  Additional parameters for search query
     * @param array|null  $removeFromUrl                Parameters to remove from URLs
     * @param string|null $tag                          User-defined task identifier
     * @param string|null $postbackUrl                  Return URL for sending task results
     * @param string|null $postbackData                 Postback URL datatype
     * @param string|null $pingbackUrl                  Notification URL of a completed task
     * @param array       $additionalParams             Additional parameters
     * @param string|null $attributes                   Optional attributes to store with cache entry
     * @param int         $amount                       Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function serpGoogleOrganicTaskPost(
        string $keyword,
        ?string $url = null,
        ?int $priority = null,
        ?int $depth = null,
        ?int $maxCrawlPages = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en',
        ?string $seDomain = null,
        ?string $device = null,
        ?string $os = null,
        ?bool $groupOrganicResults = null,
        ?bool $calculateRectangles = null,
        ?int $browserScreenWidth = null,
        ?int $browserScreenHeight = null,
        ?int $browserScreenResolutionRatio = null,
        ?int $peopleAlsoAskClickDepth = null,
        ?bool $loadAsyncAiOverview = null,
        ?bool $expandAiOverview = null,
        ?string $searchParam = null,
        ?array $removeFromUrl = null,
        ?string $tag = null,
        ?string $postbackUrl = null,
        ?string $postbackData = null,
        ?string $pingbackUrl = null,
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
        if ($depth !== null && $depth > 700) {
            throw new \InvalidArgumentException('Depth must be less than or equal to 700');
        }

        // Validate that peopleAlsoAskClickDepth is between 1 and 4 if provided
        if ($peopleAlsoAskClickDepth !== null && ($peopleAlsoAskClickDepth < 1 || $peopleAlsoAskClickDepth > 4)) {
            throw new \InvalidArgumentException('peopleAlsoAskClickDepth must be between 1 and 4');
        }

        // Validate that priority is either 1 or 2 if provided
        if ($priority !== null && !in_array($priority, [1, 2])) {
            throw new \InvalidArgumentException('Priority must be either 1 (normal) or 2 (high)');
        }

        // Validate that postbackData is provided if postbackUrl is specified
        if ($postbackUrl !== null && $postbackData === null) {
            throw new \InvalidArgumentException('postbackData is required when postbackUrl is specified');
        }

        Log::debug(
            'Creating DataForSEO Google Organic SERP task',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        $params = $this->buildApiParams($additionalParams);

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the query as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $keyword;
        }

        // Make the API request to the task post endpoint
        return $this->sendCachedRequest(
            'serp/google/organic/task_post',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get Google Organic SERP Regular results for a specific task
     *
     * @param string      $id         The task ID
     * @param string|null $attributes Optional attributes to store with cache entry
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function serpGoogleOrganicTaskGetRegular(
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        return $this->taskGet('serp/google/organic/task_get/regular', $id, $attributes, $amount);
    }

    /**
     * Get Google Organic SERP Advanced results for a specific task
     *
     * @param string      $id         The task ID
     * @param string|null $attributes Optional attributes to store with cache entry
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function serpGoogleOrganicTaskGetAdvanced(
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        return $this->taskGet('serp/google/organic/task_get/advanced', $id, $attributes, $amount);
    }

    /**
     * Get Google Organic SERP HTML results for a specific task
     *
     * @param string      $id         The task ID
     * @param string|null $attributes Optional attributes to store with cache entry
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function serpGoogleOrganicTaskGetHtml(
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        return $this->taskGet('serp/google/organic/task_get/html', $id, $attributes, $amount);
    }
}
