<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use FOfX\Helper\ReflectionUtils;
use FOfX\Helper;

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
     * @param array       $additionalParams       Additional parameters to merge from the result
     * @param array       $additionalExcludedArgs Additional argument names to exclude from the result
     * @param string|null $callable               The method name to use for argument extraction (optional)
     * @param array       $vars                   Named/positional args to the method (optional)
     * @param bool|null   $boundOnly              Whether to only extract bound arguments (optional)
     *
     * @throws \InvalidArgumentException If callable and vars are not provided together
     *
     * @return array Parameters ready for API request
     */
    public function buildApiParams(
        array $additionalParams = [],
        array $additionalExcludedArgs = [],
        ?string $callable = null,
        array $vars = [],
        ?bool $boundOnly = null
    ): array {
        // Validate that both callable and vars are provided together
        $hasCallable = $callable !== null;
        $hasVars     = !empty($vars);

        if (!$hasCallable && $hasVars) {
            throw new \InvalidArgumentException('If vars are provided, callable must also be provided.');
        }

        if ($hasCallable) {
            // Mode 1: Callable + vars provided
            $args = ReflectionUtils::extractArgs(
                $callable,
                $vars,
                $additionalExcludedArgs,
                $boundOnly ?? true
            );
        } elseif ($boundOnly === null) {
            // Mode 2: Default mode (extra arguments not passed)
            // Get calling method's arguments from backtrace
            $args = ReflectionUtils::extractBoundArgsFromBacktrace(2);

            // Merge default excluded args with any additional ones passed
            $allExcludedArgs = array_merge($this->excludedArgs, $additionalExcludedArgs);

            // Remove excluded arguments
            foreach ($allExcludedArgs as $skip) {
                unset($args[$skip]);
            }
        } else {
            // Mode 3: Fallback to backtrace (boundOnly explicitly set)
            $args = ReflectionUtils::extractArgsFromBacktrace(
                depth: 1,
                excludeParams: $additionalExcludedArgs,
                boundOnly: $boundOnly
            );
        }

        // Convert to snake_case and drop nulls
        $finalParams = [];
        foreach ($args as $key => $value) {
            if ($value !== null) {
                $finalParams[Str::snake($key)] = $value;
            }
        }

        // Merge with additional params (original params take precedence)
        return array_merge($additionalParams, $finalParams);
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
            return !Helper\is_valid_uuid($segment);
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
        // Use full path as-is if absolute, otherwise treat as relative to storage/logs
        $logFile = Helper\is_absolute_path($filename)
            ? $filename
            : __DIR__ . "/../storage/logs/{$filename}.log";

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

    /**
     * Google Organic SERP Standard method with caching and optional task creation
     *
     * The 'tag' is used as the bridge to the cache key.
     *
     * This method implements the DataForSEO Standard method workflow:
     * - Check cache for existing SERP data based on search parameters
     * - If cached, return cached SERP data immediately
     * - If not cached and task creation enabled, create task with webhooks
     * - Webhook will cache actual SERP data when received
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
     * @param string      $type                         Result type: 'regular', 'advanced', 'html'
     * @param bool        $usePostback                  Enable postback webhook
     * @param bool        $usePingback                  Enable pingback webhook
     * @param bool        $postTaskIfNotCached          Create task if not cached (default: false)
     * @param array       $additionalParams             Additional parameters
     * @param string|null $attributes                   Optional attributes to store with cache entry
     * @param int         $amount                       Amount to pass to incrementAttempts
     *
     * @return array|null Cached SERP data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function serpGoogleOrganicStandard(
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
        string $type = 'regular',
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        // Normalize and validate type parameter
        $type = strtolower($type);
        if (!in_array($type, ['regular', 'advanced', 'html'])) {
            throw new \InvalidArgumentException('type must be one of: regular, advanced, html');
        }

        // Generate cache key based only on search parameters (exclude webhook and control params)
        $searchParams = $this->buildApiParams($additionalParams, [
            'type',
            'usePostback',
            'usePingback',
            'postTaskIfNotCached',
        ]);

        // Type is always part of the endpoint
        $endpoint = "serp/google/organic/task_get/{$type}";

        $cacheKey = $this->cacheManager->generateCacheKey(
            $this->clientName,
            $endpoint,
            $searchParams,
            'POST',
            $this->version
        );

        // Check cache first - return cached SERP data if available
        $cached = $this->cacheManager->getCachedResponse($this->clientName, $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // If not cached and task creation disabled, return null
        if (!$postTaskIfNotCached) {
            return $cached;
        }

        // Create task with webhook URLs from config
        $postbackUrl = $usePostback ? config('api-cache.apis.dataforseo.postback_url') : null;
        $pingbackUrl = $usePingback ? config('api-cache.apis.dataforseo.pingback_url') : null;

        // Create task with our cache key as tag for webhook caching bridge
        return $this->serpGoogleOrganicTaskPost(
            $keyword,
            $url,
            $priority,
            $depth,
            $maxCrawlPages,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            $device,
            $os,
            $groupOrganicResults,
            $calculateRectangles,
            $browserScreenWidth,
            $browserScreenHeight,
            $browserScreenResolutionRatio,
            $peopleAlsoAskClickDepth,
            $loadAsyncAiOverview,
            $expandAiOverview,
            $searchParam,
            $removeFromUrl,
            $cacheKey,
            $postbackUrl,
            $type,
            $pingbackUrl,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Google Organic SERP Standard Regular method wrapper
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
     * @param bool        $usePostback                  Enable postback webhook (default: false)
     * @param bool        $usePingback                  Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached          Create task if not cached (default: false)
     * @param array       $additionalParams             Additional parameters
     * @param string|null $attributes                   Optional attributes to store with cache entry
     * @param int         $amount                       Amount to pass to incrementAttempts
     *
     * @return array|null Cached SERP data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function serpGoogleOrganicStandardRegular(
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
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        return $this->serpGoogleOrganicStandard(
            $keyword,
            $url,
            $priority,
            $depth,
            $maxCrawlPages,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            $device,
            $os,
            $groupOrganicResults,
            $calculateRectangles,
            $browserScreenWidth,
            $browserScreenHeight,
            $browserScreenResolutionRatio,
            $peopleAlsoAskClickDepth,
            $loadAsyncAiOverview,
            $expandAiOverview,
            $searchParam,
            $removeFromUrl,
            'regular',
            $usePostback,
            $usePingback,
            $postTaskIfNotCached,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Google Organic SERP Standard Advanced method wrapper
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
     * @param bool        $usePostback                  Enable postback webhook (default: false)
     * @param bool        $usePingback                  Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached          Create task if not cached (default: false)
     * @param array       $additionalParams             Additional parameters
     * @param string|null $attributes                   Optional attributes to store with cache entry
     * @param int         $amount                       Amount to pass to incrementAttempts
     *
     * @return array|null Cached SERP data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function serpGoogleOrganicStandardAdvanced(
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
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        return $this->serpGoogleOrganicStandard(
            $keyword,
            $url,
            $priority,
            $depth,
            $maxCrawlPages,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            $device,
            $os,
            $groupOrganicResults,
            $calculateRectangles,
            $browserScreenWidth,
            $browserScreenHeight,
            $browserScreenResolutionRatio,
            $peopleAlsoAskClickDepth,
            $loadAsyncAiOverview,
            $expandAiOverview,
            $searchParam,
            $removeFromUrl,
            'advanced',
            $usePostback,
            $usePingback,
            $postTaskIfNotCached,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Google Organic SERP Standard HTML method wrapper
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
     * @param bool        $usePostback                  Enable postback webhook (default: false)
     * @param bool        $usePingback                  Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached          Create task if not cached (default: false)
     * @param array       $additionalParams             Additional parameters
     * @param string|null $attributes                   Optional attributes to store with cache entry
     * @param int         $amount                       Amount to pass to incrementAttempts
     *
     * @return array|null Cached SERP data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function serpGoogleOrganicStandardHtml(
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
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        return $this->serpGoogleOrganicStandard(
            $keyword,
            $url,
            $priority,
            $depth,
            $maxCrawlPages,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            $device,
            $os,
            $groupOrganicResults,
            $calculateRectangles,
            $browserScreenWidth,
            $browserScreenHeight,
            $browserScreenResolutionRatio,
            $peopleAlsoAskClickDepth,
            $loadAsyncAiOverview,
            $expandAiOverview,
            $searchParam,
            $removeFromUrl,
            'html',
            $usePostback,
            $usePingback,
            $postTaskIfNotCached,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Create a Google Autocomplete SERP task using DataForSEO's Task POST endpoint
     *
     * @param string      $keyword          The search query (max 700 characters)
     * @param int|null    $priority         Task priority (1 - normal, 2 - high)
     * @param string|null $locationName     Location name (e.g., "London,England,United Kingdom")
     * @param int|null    $locationCode     Location code (e.g., 2840)
     * @param string|null $languageName     Language name (e.g., "English")
     * @param string|null $languageCode     Language code (e.g., "en")
     * @param int|null    $cursorPointer    Search bar cursor pointer position (default: keyword length)
     * @param string|null $client           Search client for autocomplete (chrome, chrome-omni, gws-wiz, etc.)
     * @param string|null $tag              User-defined task identifier
     * @param string|null $postbackUrl      Return URL for sending task results
     * @param string|null $postbackData     Postback URL datatype (required if postbackUrl is set)
     * @param string|null $pingbackUrl      Notification URL of a completed task
     * @param array       $additionalParams Additional parameters
     * @param string|null $attributes       Optional attributes to store with cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function serpGoogleAutocompleteTaskPost(
        string $keyword,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $languageName = null,
        ?string $languageCode = 'en',
        ?int $cursorPointer = null,
        ?string $client = null,
        ?string $tag = null,
        ?string $postbackUrl = null,
        ?string $postbackData = null,
        ?string $pingbackUrl = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate that keyword is not empty and not too long
        if (empty($keyword)) {
            throw new \InvalidArgumentException('Keyword cannot be empty');
        }

        if (strlen($keyword) > 700) {
            throw new \InvalidArgumentException('Keyword must be 700 characters or less');
        }

        // Validate that at least one language parameter is provided
        if ($languageName === null && $languageCode === null) {
            throw new \InvalidArgumentException('Either languageName or languageCode must be provided');
        }

        // Validate that at least one location parameter is provided
        if ($locationName === null && $locationCode === null) {
            throw new \InvalidArgumentException('Either locationName or locationCode must be provided');
        }

        // Validate that priority is either 1 or 2 if provided
        if ($priority !== null && !in_array($priority, [1, 2])) {
            throw new \InvalidArgumentException('Priority must be either 1 (normal) or 2 (high)');
        }

        // Validate that cursorPointer is within valid range if provided
        if ($cursorPointer !== null && ($cursorPointer < 0 || $cursorPointer > strlen($keyword))) {
            throw new \InvalidArgumentException('cursorPointer must be between 0 and keyword length');
        }

        // Validate that postbackData is provided if postbackUrl is specified
        if ($postbackUrl !== null && $postbackData === null) {
            throw new \InvalidArgumentException('postbackData is required when postbackUrl is specified');
        }

        Log::debug(
            'Creating DataForSEO Google Autocomplete SERP task',
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
            'serp/google/autocomplete/task_post',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get Google Autocomplete SERP Advanced results for a specific task
     *
     * @param string      $id         The task ID
     * @param string|null $attributes Optional attributes to store with cache entry
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function serpGoogleAutocompleteTaskGetAdvanced(
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        return $this->taskGet('serp/google/autocomplete/task_get/advanced', $id, $attributes, $amount);
    }

    /**
     * Google Autocomplete SERP Standard Advanced method with caching and optional task creation
     *
     * The 'tag' is used as the bridge to the cache key.
     *
     * This method implements the DataForSEO Standard method workflow:
     * - Check cache for existing autocomplete data based on search parameters
     * - If cached, return cached autocomplete data immediately
     * - If not cached and task creation enabled, create task with webhooks
     * - Webhook will cache actual autocomplete data when received
     *
     * @param string      $keyword             The search query (max 700 characters)
     * @param int|null    $priority            Task priority (1 - normal, 2 - high)
     * @param string|null $locationName        Location name (e.g., "London,England,United Kingdom")
     * @param int|null    $locationCode        Location code (e.g., 2840)
     * @param string|null $languageName        Language name (e.g., "English")
     * @param string|null $languageCode        Language code (e.g., "en")
     * @param int|null    $cursorPointer       Search bar cursor pointer position (default: keyword length)
     * @param string|null $client              Search client for autocomplete (chrome, chrome-omni, gws-wiz, etc.)
     * @param bool        $usePostback         Enable postback webhook (default: false)
     * @param bool        $usePingback         Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached Create task if not cached (default: false)
     * @param array       $additionalParams    Additional parameters
     * @param string|null $attributes          Optional attributes to store with cache entry
     * @param int         $amount              Amount to pass to incrementAttempts
     *
     * @return array|null Cached autocomplete data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function serpGoogleAutocompleteStandardAdvanced(
        string $keyword,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $languageName = null,
        ?string $languageCode = 'en',
        ?int $cursorPointer = null,
        ?string $client = null,
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        // Generate cache key based only on search parameters (exclude webhook and control params)
        $searchParams = $this->buildApiParams($additionalParams, [
            'usePostback',
            'usePingback',
            'postTaskIfNotCached',
        ]);

        $endpoint = 'serp/google/autocomplete/task_get/advanced';

        $cacheKey = $this->cacheManager->generateCacheKey(
            $this->clientName,
            $endpoint,
            $searchParams,
            'POST',
            $this->version
        );

        // Check cache first - return cached autocomplete data if available
        $cached = $this->cacheManager->getCachedResponse($this->clientName, $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // If not cached and task creation disabled, return null
        if (!$postTaskIfNotCached) {
            return $cached;
        }

        // Create task with webhook URLs from config
        $postbackUrl = $usePostback ? config('api-cache.apis.dataforseo.postback_url') : null;
        $pingbackUrl = $usePingback ? config('api-cache.apis.dataforseo.pingback_url') : null;

        // Create task with our cache key as tag for webhook caching bridge
        return $this->serpGoogleAutocompleteTaskPost(
            $keyword,
            $priority,
            $locationName,
            $locationCode,
            $languageName,
            $languageCode,
            $cursorPointer,
            $client,
            $cacheKey,
            $postbackUrl,
            'advanced',
            $pingbackUrl,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Create a Merchant Amazon Products task using DataForSEO's Task POST endpoint
     *
     * @param string      $keyword            The search query (max 700 characters)
     * @param string|null $url                Direct URL of the search query
     * @param int|null    $priority           Task priority (1 - normal, 2 - high)
     * @param string|null $locationName       Location name (e.g., "HA1,England,United Kingdom")
     * @param int|null    $locationCode       Location code (e.g., 9045969)
     * @param string|null $locationCoordinate Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName       Language name (e.g., "English (United Kingdom)")
     * @param string|null $languageCode       Language code (e.g., "en_US")
     * @param string|null $seDomain           Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param int|null    $depth              Number of results to retrieve (default: 100, max: 700)
     * @param int|null    $maxCrawlPages      Page crawl limit (max: 7)
     * @param string|null $department         Amazon product department
     * @param string|null $searchParam        Additional parameters of the search query
     * @param int|null    $priceMin           Minimum product price
     * @param int|null    $priceMax           Maximum product price
     * @param string|null $sortBy             Results sorting rules (relevance, price_low_to_high, etc.)
     * @param string|null $tag                User-defined task identifier
     * @param string|null $postbackUrl        Return URL for sending task results
     * @param string|null $postbackData       Postback URL datatype (required if postbackUrl is set)
     * @param string|null $pingbackUrl        Notification URL of a completed task
     * @param array       $additionalParams   Additional parameters
     * @param string|null $attributes         Optional attributes to store with cache entry
     * @param int         $amount             Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function merchantAmazonProductsTaskPost(
        string $keyword,
        ?string $url = null,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        ?int $depth = 100,
        ?int $maxCrawlPages = null,
        ?string $department = null,
        ?string $searchParam = null,
        ?int $priceMin = null,
        ?int $priceMax = null,
        ?string $sortBy = null,
        ?string $tag = null,
        ?string $postbackUrl = null,
        ?string $postbackData = null,
        ?string $pingbackUrl = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate that keyword is not empty
        if (empty($keyword)) {
            throw new \InvalidArgumentException('Keyword cannot be empty');
        }

        // Validate that keyword is not longer than 700 characters
        if (strlen($keyword) > 700) {
            throw new \InvalidArgumentException('Keyword must be 700 characters or less');
        }

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

        // Validate that maxCrawlPages is less than or equal to 7
        if ($maxCrawlPages !== null && $maxCrawlPages > 7) {
            throw new \InvalidArgumentException('max_crawl_pages must be less than or equal to 7');
        }

        // Validate that priority is either 1 or 2 if provided
        if ($priority !== null && !in_array($priority, [1, 2])) {
            throw new \InvalidArgumentException('Priority must be either 1 (normal) or 2 (high)');
        }

        // Validate that department is a valid Amazon department if provided
        $validDepartments = [
            'Arts & Crafts', 'Automotive', 'Baby', 'Beauty & Personal Care', 'Books', 'Computers',
            'Digital Music', 'Electronics', 'Kindle Store', 'Prime Video', "Women's Fashion",
            "Men's Fashion", "Girls' Fashion", "Boys' Fashion", 'Deals', 'Health & Household',
            'Home & Kitchen', 'Industrial & Scientific', 'Luggage', 'Movies & TV',
            'Music, CDs & Vinyl', 'Pet Supplies', 'Software', 'Sports & Outdoors',
            'Tools & Home Improvement', 'Toys & Games', 'Video Games',
        ];
        if ($department !== null && !in_array($department, $validDepartments)) {
            throw new \InvalidArgumentException('department must be a valid Amazon department');
        }

        // Validate that sortBy is a valid sorting rule if provided
        $validSortBy = ['relevance', 'price_low_to_high', 'price_high_to_low', 'featured', 'avg_customer_review', 'newest_arrival'];
        if ($sortBy !== null && !in_array($sortBy, $validSortBy)) {
            throw new \InvalidArgumentException('sort_by must be one of: ' . implode(', ', $validSortBy));
        }

        // Validate that postbackData is provided if postbackUrl is specified
        if ($postbackUrl !== null && $postbackData === null) {
            throw new \InvalidArgumentException('postbackData is required when postbackUrl is specified');
        }

        // Validate that postbackData is valid if provided
        $validPostbackData = ['advanced', 'html'];
        if ($postbackData !== null && !in_array($postbackData, $validPostbackData)) {
            throw new \InvalidArgumentException('postback_data must be one of: ' . implode(', ', $validPostbackData));
        }

        Log::debug(
            'Creating DataForSEO Merchant Amazon Products task',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Use the method name and its defined variables to build the API parameters
        // Extra arguments needed for testing when passing parameters with the splat operator
        // e.g. $this->client->merchantAmazonProductsTaskPost(...$parameters);
        $params = $this->buildApiParams(
            $additionalParams,
            [],
            __METHOD__,
            get_defined_vars()
        );

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the query as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $keyword;
        }

        // Make the API request to the task post endpoint
        return $this->sendCachedRequest(
            'merchant/amazon/products/task_post',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get merchant Amazon products task result (advanced format)
     *
     * @param string      $id         Task ID to retrieve
     * @param string|null $attributes Optional attributes to store with cache entry
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @return array Task result data
     */
    public function merchantAmazonProductsTaskGetAdvanced(
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        return $this->taskGet('merchant/amazon/products/task_get/advanced', $id, $attributes, $amount);
    }

    /**
     * Get merchant Amazon products task result (HTML format)
     *
     * @param string      $id         Task ID to retrieve
     * @param string|null $attributes Optional attributes to store with cache entry
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @return array Task result data
     */
    public function merchantAmazonProductsTaskGetHtml(
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        return $this->taskGet('merchant/amazon/products/task_get/html', $id, $attributes, $amount);
    }

    /**
     * Merchant Amazon Products Standard method with caching and optional task creation
     *
     * The 'tag' is used as the bridge to the cache key.
     *
     * This method implements the DataForSEO Standard method workflow:
     * - Check cache for existing product data based on search parameters
     * - If cached, return cached product data immediately
     * - If not cached and task creation enabled, create task with webhooks
     * - Webhook will cache actual product data when received
     *
     * @param string      $keyword             The search query (max 700 characters)
     * @param string|null $url                 Direct URL of the search query
     * @param int|null    $priority            Task priority (1 - normal, 2 - high)
     * @param string|null $locationName        Location name (e.g., "HA1,England,United Kingdom")
     * @param int|null    $locationCode        Location code (e.g., 9045969)
     * @param string|null $locationCoordinate  Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName        Language name (e.g., "English (United Kingdom)")
     * @param string|null $languageCode        Language code (e.g., "en_US")
     * @param string|null $seDomain            Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param int|null    $depth               Number of results to retrieve (default: 100, max: 700)
     * @param int|null    $maxCrawlPages       Page crawl limit (max: 7)
     * @param string|null $department          Amazon product department
     * @param string|null $searchParam         Additional parameters of the search query
     * @param int|null    $priceMin            Minimum product price
     * @param int|null    $priceMax            Maximum product price
     * @param string|null $sortBy              Results sorting rules (relevance, price_low_to_high, etc.)
     * @param string      $type                Task get type (advanced or html)
     * @param bool        $usePostback         Enable postback webhook (default: false)
     * @param bool        $usePingback         Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached Create task if not cached (default: false)
     * @param array       $additionalParams    Additional parameters
     * @param string|null $attributes          Optional attributes to store with cache entry
     * @param int         $amount              Amount to pass to incrementAttempts
     *
     * @return array|null Cached product data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function merchantAmazonProductsStandard(
        string $keyword,
        ?string $url = null,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        ?int $depth = 100,
        ?int $maxCrawlPages = null,
        ?string $department = null,
        ?string $searchParam = null,
        ?int $priceMin = null,
        ?int $priceMax = null,
        ?string $sortBy = null,
        string $type = 'advanced',
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        // Normalize and validate type parameter
        $type = strtolower($type);
        if (!in_array($type, ['advanced', 'html'])) {
            throw new \InvalidArgumentException('type must be one of: advanced, html');
        }

        // Generate cache key based only on search parameters (exclude webhook and control params)
        $searchParams = $this->buildApiParams($additionalParams, [
            'type',
            'usePostback',
            'usePingback',
            'postTaskIfNotCached',
        ]);

        // Type is always part of the endpoint
        $endpoint = "merchant/amazon/products/task_get/{$type}";

        $cacheKey = $this->cacheManager->generateCacheKey(
            $this->clientName,
            $endpoint,
            $searchParams,
            'POST',
            $this->version
        );

        // Check cache first - return cached product data if available
        $cached = $this->cacheManager->getCachedResponse($this->clientName, $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // If not cached and task creation disabled, return null
        if (!$postTaskIfNotCached) {
            return $cached;
        }

        // Create task with webhook URLs from config
        $postbackUrl = $usePostback ? config('api-cache.apis.dataforseo.postback_url') : null;
        $pingbackUrl = $usePingback ? config('api-cache.apis.dataforseo.pingback_url') : null;

        // Create task with our cache key as tag for webhook caching bridge
        return $this->merchantAmazonProductsTaskPost(
            $keyword,
            $url,
            $priority,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            $depth,
            $maxCrawlPages,
            $department,
            $searchParam,
            $priceMin,
            $priceMax,
            $sortBy,
            $cacheKey,
            $postbackUrl,
            $type,
            $pingbackUrl,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Merchant Amazon Products Standard Advanced method wrapper
     *
     * @param string      $keyword             The search query (max 700 characters)
     * @param string|null $url                 Direct URL of the search query
     * @param int|null    $priority            Task priority (1 - normal, 2 - high)
     * @param string|null $locationName        Location name (e.g., "HA1,England,United Kingdom")
     * @param int|null    $locationCode        Location code (e.g., 9045969)
     * @param string|null $locationCoordinate  Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName        Language name (e.g., "English (United Kingdom)")
     * @param string|null $languageCode        Language code (e.g., "en_US")
     * @param string|null $seDomain            Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param int|null    $depth               Number of results to retrieve (default: 100, max: 700)
     * @param int|null    $maxCrawlPages       Page crawl limit (max: 7)
     * @param string|null $department          Amazon product department
     * @param string|null $searchParam         Additional parameters of the search query
     * @param int|null    $priceMin            Minimum product price
     * @param int|null    $priceMax            Maximum product price
     * @param string|null $sortBy              Results sorting rules (relevance, price_low_to_high, etc.)
     * @param bool        $usePostback         Enable postback webhook (default: false)
     * @param bool        $usePingback         Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached Create task if not cached (default: false)
     * @param array       $additionalParams    Additional parameters
     * @param string|null $attributes          Optional attributes to store with cache entry
     * @param int         $amount              Amount to pass to incrementAttempts
     *
     * @return array|null Cached product data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function merchantAmazonProductsStandardAdvanced(
        string $keyword,
        ?string $url = null,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        ?int $depth = 100,
        ?int $maxCrawlPages = null,
        ?string $department = null,
        ?string $searchParam = null,
        ?int $priceMin = null,
        ?int $priceMax = null,
        ?string $sortBy = null,
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        return $this->merchantAmazonProductsStandard(
            $keyword,
            $url,
            $priority,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            $depth,
            $maxCrawlPages,
            $department,
            $searchParam,
            $priceMin,
            $priceMax,
            $sortBy,
            'advanced',
            $usePostback,
            $usePingback,
            $postTaskIfNotCached,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Merchant Amazon Products Standard HTML method wrapper
     *
     * @param string      $keyword             The search query (max 700 characters)
     * @param string|null $url                 Direct URL of the search query
     * @param int|null    $priority            Task priority (1 - normal, 2 - high)
     * @param string|null $locationName        Location name (e.g., "HA1,England,United Kingdom")
     * @param int|null    $locationCode        Location code (e.g., 9045969)
     * @param string|null $locationCoordinate  Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName        Language name (e.g., "English (United Kingdom)")
     * @param string|null $languageCode        Language code (e.g., "en_US")
     * @param string|null $seDomain            Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param int|null    $depth               Number of results to retrieve (default: 100, max: 700)
     * @param int|null    $maxCrawlPages       Page crawl limit (max: 7)
     * @param string|null $department          Amazon product department
     * @param string|null $searchParam         Additional parameters of the search query
     * @param int|null    $priceMin            Minimum product price
     * @param int|null    $priceMax            Maximum product price
     * @param string|null $sortBy              Results sorting rules (relevance, price_low_to_high, etc.)
     * @param bool        $usePostback         Enable postback webhook (default: false)
     * @param bool        $usePingback         Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached Create task if not cached (default: false)
     * @param array       $additionalParams    Additional parameters
     * @param string|null $attributes          Optional attributes to store with cache entry
     * @param int         $amount              Amount to pass to incrementAttempts
     *
     * @return array|null Cached product data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function merchantAmazonProductsStandardHtml(
        string $keyword,
        ?string $url = null,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        ?int $depth = 100,
        ?int $maxCrawlPages = null,
        ?string $department = null,
        ?string $searchParam = null,
        ?int $priceMin = null,
        ?int $priceMax = null,
        ?string $sortBy = null,
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        return $this->merchantAmazonProductsStandard(
            $keyword,
            $url,
            $priority,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            $depth,
            $maxCrawlPages,
            $department,
            $searchParam,
            $priceMin,
            $priceMax,
            $sortBy,
            'html',
            $usePostback,
            $usePingback,
            $postTaskIfNotCached,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Create a Merchant Amazon ASIN task using DataForSEO's Task POST endpoint
     *
     * @param string      $asin                 Amazon product ID (ASIN)
     * @param int|null    $priority             Task priority (1 - normal, 2 - high)
     * @param string|null $locationName         Location name (e.g., "HA1,England,United Kingdom")
     * @param int|null    $locationCode         Location code (e.g., 9045969)
     * @param string|null $locationCoordinate   Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName         Language name (e.g., "English (United Kingdom)")
     * @param string|null $languageCode         Language code (e.g., "en_US")
     * @param string|null $seDomain             Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param bool|null   $loadMoreLocalReviews Load more local reviews (double cost)
     * @param string|null $localReviewsSort     Sort local reviews ("helpful" or "recent")
     * @param string|null $tag                  User-defined task identifier
     * @param string|null $postbackUrl          Return URL for sending task results
     * @param string|null $postbackData         Postback URL datatype (required if postbackUrl is set)
     * @param string|null $pingbackUrl          Notification URL of a completed task
     * @param array       $additionalParams     Additional parameters
     * @param string|null $attributes           Optional attributes to store with cache entry
     * @param int         $amount               Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function merchantAmazonAsinTaskPost(
        string $asin,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        ?bool $loadMoreLocalReviews = null,
        ?string $localReviewsSort = null,
        ?string $tag = null,
        ?string $postbackUrl = null,
        ?string $postbackData = null,
        ?string $pingbackUrl = null,
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
        if ($locationName === null && $locationCode === null && $locationCoordinate === null) {
            throw new \InvalidArgumentException('Either locationName, locationCode, or locationCoordinate must be provided');
        }

        // Validate that priority is either 1 or 2 if provided
        if ($priority !== null && !in_array($priority, [1, 2])) {
            throw new \InvalidArgumentException('Priority must be either 1 (normal) or 2 (high)');
        }

        // Validate localReviewsSort if provided
        if ($localReviewsSort !== null && !in_array($localReviewsSort, ['helpful', 'recent'])) {
            throw new \InvalidArgumentException('localReviewsSort must be either "helpful" or "recent"');
        }

        // Validate that postbackData is provided if postbackUrl is specified
        if ($postbackUrl !== null && $postbackData === null) {
            throw new \InvalidArgumentException('postbackData is required when postbackUrl is specified');
        }

        // Validate that postbackData is valid if provided
        $validPostbackData = ['advanced', 'html'];
        if ($postbackData !== null && !in_array($postbackData, $validPostbackData)) {
            throw new \InvalidArgumentException('postback_data must be one of: ' . implode(', ', $validPostbackData));
        }

        Log::debug(
            'Creating DataForSEO Merchant Amazon ASIN task',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Extra arguments needed for testing when passing parameters with the splat operator
        $params = $this->buildApiParams($additionalParams, [], __METHOD__, get_defined_vars());

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the ASIN as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $asin;
        }

        // Make the API request to the task post endpoint
        return $this->sendCachedRequest(
            'merchant/amazon/asin/task_post',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get merchant Amazon ASIN task result (advanced format)
     *
     * @param string      $id         Task ID to retrieve
     * @param string|null $attributes Optional attributes to store with cache entry
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @return array Task result data
     */
    public function merchantAmazonAsinTaskGetAdvanced(
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        return $this->taskGet('merchant/amazon/asin/task_get/advanced', $id, $attributes, $amount);
    }

    /**
     * Get merchant Amazon ASIN task result (HTML format)
     *
     * @param string      $id         Task ID to retrieve
     * @param string|null $attributes Optional attributes to store with cache entry
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @return array Task result data
     */
    public function merchantAmazonAsinTaskGetHtml(
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        return $this->taskGet('merchant/amazon/asin/task_get/html', $id, $attributes, $amount);
    }

    /**
     * Merchant Amazon ASIN Standard method with caching and optional task creation
     *
     * The 'tag' is used as the bridge to the cache key.
     *
     * This method implements the DataForSEO Standard method workflow:
     * - Check cache for existing ASIN data based on search parameters
     * - If cached, return cached ASIN data immediately
     * - If not cached and task creation enabled, create task with webhooks
     * - Webhook will cache actual ASIN data when received
     *
     * @param string      $asin                 Amazon product ID (ASIN)
     * @param int|null    $priority             Task priority (1 - normal, 2 - high)
     * @param string|null $locationName         Location name (e.g., "HA1,England,United Kingdom")
     * @param int|null    $locationCode         Location code (e.g., 9045969)
     * @param string|null $locationCoordinate   Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName         Language name (e.g., "English (United Kingdom)")
     * @param string|null $languageCode         Language code (e.g., "en_US")
     * @param string|null $seDomain             Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param bool|null   $loadMoreLocalReviews Load more local reviews (double cost)
     * @param string|null $localReviewsSort     Sort local reviews ("helpful" or "recent")
     * @param string      $type                 Task get type (advanced or html)
     * @param bool        $usePostback          Enable postback webhook (default: false)
     * @param bool        $usePingback          Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached  Create task if not cached (default: false)
     * @param array       $additionalParams     Additional parameters
     * @param string|null $attributes           Optional attributes to store with cache entry
     * @param int         $amount               Amount to pass to incrementAttempts
     *
     * @return array|null Cached ASIN data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function merchantAmazonAsinStandard(
        string $asin,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        ?bool $loadMoreLocalReviews = null,
        ?string $localReviewsSort = null,
        string $type = 'advanced',
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        // Normalize and validate type parameter
        $type = strtolower($type);
        if (!in_array($type, ['advanced', 'html'])) {
            throw new \InvalidArgumentException('type must be one of: advanced, html');
        }

        // Generate cache key based only on search parameters (exclude webhook and control params)
        $searchParams = $this->buildApiParams($additionalParams, [
            'type',
            'usePostback',
            'usePingback',
            'postTaskIfNotCached',
        ]);

        // Type is always part of the endpoint
        $endpoint = "merchant/amazon/asin/task_get/{$type}";

        $cacheKey = $this->cacheManager->generateCacheKey(
            $this->clientName,
            $endpoint,
            $searchParams,
            'POST',
            $this->version
        );

        // Check cache first - return cached ASIN data if available
        $cached = $this->cacheManager->getCachedResponse($this->clientName, $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // If not cached and task creation disabled, return null
        if (!$postTaskIfNotCached) {
            return $cached;
        }

        // Create task with webhook URLs from config
        $postbackUrl = $usePostback ? config('api-cache.apis.dataforseo.postback_url') : null;
        $pingbackUrl = $usePingback ? config('api-cache.apis.dataforseo.pingback_url') : null;

        // Create task with our cache key as tag for webhook caching bridge
        return $this->merchantAmazonAsinTaskPost(
            $asin,
            $priority,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            $loadMoreLocalReviews,
            $localReviewsSort,
            $cacheKey,
            $postbackUrl,
            $type,
            $pingbackUrl,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Merchant Amazon ASIN Standard Advanced method wrapper
     *
     * @param string      $asin                 Amazon product ID (ASIN)
     * @param int|null    $priority             Task priority (1 - normal, 2 - high)
     * @param string|null $locationName         Location name (e.g., "HA1,England,United Kingdom")
     * @param int|null    $locationCode         Location code (e.g., 9045969)
     * @param string|null $locationCoordinate   Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName         Language name (e.g., "English (United Kingdom)")
     * @param string|null $languageCode         Language code (e.g., "en_US")
     * @param string|null $seDomain             Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param bool|null   $loadMoreLocalReviews Load more local reviews (double cost)
     * @param string|null $localReviewsSort     Sort local reviews ("helpful" or "recent")
     * @param bool        $usePostback          Enable postback webhook (default: false)
     * @param bool        $usePingback          Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached  Create task if not cached (default: false)
     * @param array       $additionalParams     Additional parameters
     * @param string|null $attributes           Optional attributes to store with cache entry
     * @param int         $amount               Amount to pass to incrementAttempts
     *
     * @return array|null Cached ASIN data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function merchantAmazonAsinStandardAdvanced(
        string $asin,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        ?bool $loadMoreLocalReviews = null,
        ?string $localReviewsSort = null,
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        return $this->merchantAmazonAsinStandard(
            $asin,
            $priority,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            $loadMoreLocalReviews,
            $localReviewsSort,
            'advanced',
            $usePostback,
            $usePingback,
            $postTaskIfNotCached,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Merchant Amazon ASIN Standard HTML method wrapper
     *
     * @param string      $asin                 Amazon product ID (ASIN)
     * @param int|null    $priority             Task priority (1 - normal, 2 - high)
     * @param string|null $locationName         Location name (e.g., "HA1,England,United Kingdom")
     * @param int|null    $locationCode         Location code (e.g., 9045969)
     * @param string|null $locationCoordinate   Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName         Language name (e.g., "English (United Kingdom)")
     * @param string|null $languageCode         Language code (e.g., "en_US")
     * @param string|null $seDomain             Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param bool|null   $loadMoreLocalReviews Load more local reviews (double cost)
     * @param string|null $localReviewsSort     Sort local reviews ("helpful" or "recent")
     * @param bool        $usePostback          Enable postback webhook (default: false)
     * @param bool        $usePingback          Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached  Create task if not cached (default: false)
     * @param array       $additionalParams     Additional parameters
     * @param string|null $attributes           Optional attributes to store with cache entry
     * @param int         $amount               Amount to pass to incrementAttempts
     *
     * @return array|null Cached ASIN data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function merchantAmazonAsinStandardHtml(
        string $asin,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        ?bool $loadMoreLocalReviews = null,
        ?string $localReviewsSort = null,
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        return $this->merchantAmazonAsinStandard(
            $asin,
            $priority,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            $loadMoreLocalReviews,
            $localReviewsSort,
            'html',
            $usePostback,
            $usePingback,
            $postTaskIfNotCached,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Create a Merchant Amazon Sellers task using DataForSEO's Task POST endpoint
     *
     * @param string      $asin               Amazon product ID (ASIN)
     * @param int|null    $priority           Task priority (1 - normal, 2 - high)
     * @param string|null $locationName       Location name (e.g., "London,England,United Kingdom")
     * @param int|null    $locationCode       Location code (e.g., 2840)
     * @param string|null $locationCoordinate Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName       Language name (e.g., "English")
     * @param string|null $languageCode       Language code (e.g., "en_US")
     * @param string|null $seDomain           Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param string|null $tag                User-defined task identifier (max 255 characters)
     * @param string|null $postbackUrl        Return URL for sending task results
     * @param string|null $postbackData       Postback URL datatype (required if postbackUrl is set)
     * @param string|null $pingbackUrl        Notification URL of a completed task
     * @param array       $additionalParams   Additional parameters
     * @param string|null $attributes         Optional attributes to store with cache entry
     * @param int         $amount             Amount to pass to incrementAttempts
     *
     * @return array The API response data
     */
    public function merchantAmazonSellersTaskPost(
        string $asin,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        ?string $tag = null,
        ?string $postbackUrl = null,
        ?string $postbackData = null,
        ?string $pingbackUrl = null,
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
        if ($locationName === null && $locationCode === null && $locationCoordinate === null) {
            throw new \InvalidArgumentException('Either locationName, locationCode, or locationCoordinate must be provided');
        }

        // Validate that priority is either 1 or 2 if provided
        if ($priority !== null && !in_array($priority, [1, 2])) {
            throw new \InvalidArgumentException('Priority must be either 1 (normal) or 2 (high)');
        }

        // Validate that tag is within character limit if provided
        if ($tag !== null && strlen($tag) > 255) {
            throw new \InvalidArgumentException('Tag must be 255 characters or less');
        }

        // Validate that postbackData is provided if postbackUrl is specified
        if ($postbackUrl !== null && $postbackData === null) {
            throw new \InvalidArgumentException('postbackData is required when postbackUrl is specified');
        }

        // Validate that postbackData is valid if provided
        $validPostbackData = ['advanced', 'html'];
        if ($postbackData !== null && !in_array($postbackData, $validPostbackData)) {
            throw new \InvalidArgumentException('postback_data must be one of: ' . implode(', ', $validPostbackData));
        }

        Log::debug(
            'Creating DataForSEO Merchant Amazon Sellers task',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Extra arguments needed for testing when passing parameters with the splat operator
        $params = $this->buildApiParams($additionalParams, [], __METHOD__, get_defined_vars());

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the ASIN as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $asin;
        }

        // Make the API request to the task post endpoint
        return $this->sendCachedRequest(
            'merchant/amazon/sellers/task_post',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get merchant Amazon sellers task result (advanced format)
     *
     * @param string      $id         Task ID to retrieve
     * @param string|null $attributes Optional attributes to store with cache entry
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @return array Task result data
     */
    public function merchantAmazonSellersTaskGetAdvanced(
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        return $this->taskGet('merchant/amazon/sellers/task_get/advanced', $id, $attributes, $amount);
    }

    /**
     * Get merchant Amazon sellers task result (HTML format)
     *
     * @param string      $id         Task ID to retrieve
     * @param string|null $attributes Optional attributes to store with cache entry
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @return array Task result data
     */
    public function merchantAmazonSellersTaskGetHtml(
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        return $this->taskGet('merchant/amazon/sellers/task_get/html', $id, $attributes, $amount);
    }

    /**
     * Merchant Amazon Sellers Standard method with caching and optional task creation
     *
     * The 'tag' is used as the bridge to the cache key.
     *
     * This method implements the DataForSEO Standard method workflow:
     * - Check cache for existing sellers data based on search parameters
     * - If cached, return cached sellers data immediately
     * - If not cached and task creation enabled, create task with webhooks
     * - Webhook will cache actual sellers data when received
     *
     * @param string      $asin                Amazon product ID (ASIN)
     * @param int|null    $priority            Task priority (1 - normal, 2 - high)
     * @param string|null $locationName        Location name (e.g., "London,England,United Kingdom")
     * @param int|null    $locationCode        Location code (e.g., 2840)
     * @param string|null $locationCoordinate  Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName        Language name (e.g., "English")
     * @param string|null $languageCode        Language code (e.g., "en_US")
     * @param string|null $seDomain            Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param string      $type                Task get type (advanced or html)
     * @param bool        $usePostback         Enable postback webhook (default: false)
     * @param bool        $usePingback         Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached Create task if not cached (default: false)
     * @param array       $additionalParams    Additional parameters
     * @param string|null $attributes          Optional attributes to store with cache entry
     * @param int         $amount              Amount to pass to incrementAttempts
     *
     * @return array|null Cached sellers data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function merchantAmazonSellersStandard(
        string $asin,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        string $type = 'advanced',
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        // Normalize and validate type parameter
        $type = strtolower($type);
        if (!in_array($type, ['advanced', 'html'])) {
            throw new \InvalidArgumentException('type must be one of: advanced, html');
        }

        // Generate cache key based only on search parameters (exclude webhook and control params)
        $searchParams = $this->buildApiParams($additionalParams, [
            'type',
            'usePostback',
            'usePingback',
            'postTaskIfNotCached',
        ]);

        // Type is always part of the endpoint
        $endpoint = "merchant/amazon/sellers/task_get/{$type}";

        $cacheKey = $this->cacheManager->generateCacheKey(
            $this->clientName,
            $endpoint,
            $searchParams,
            'POST',
            $this->version
        );

        // Check cache first - return cached sellers data if available
        $cached = $this->cacheManager->getCachedResponse($this->clientName, $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // If not cached and task creation disabled, return null
        if (!$postTaskIfNotCached) {
            return $cached;
        }

        // Create task with webhook URLs from config
        $postbackUrl = $usePostback ? config('api-cache.apis.dataforseo.postback_url') : null;
        $pingbackUrl = $usePingback ? config('api-cache.apis.dataforseo.pingback_url') : null;

        // Create task with our cache key as tag for webhook caching bridge
        return $this->merchantAmazonSellersTaskPost(
            $asin,
            $priority,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            $cacheKey,
            $postbackUrl,
            $type,
            $pingbackUrl,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Merchant Amazon Sellers Standard Advanced method wrapper
     *
     * @param string      $asin                Amazon product ID (ASIN)
     * @param int|null    $priority            Task priority (1 - normal, 2 - high)
     * @param string|null $locationName        Location name (e.g., "London,England,United Kingdom")
     * @param int|null    $locationCode        Location code (e.g., 2840)
     * @param string|null $locationCoordinate  Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName        Language name (e.g., "English")
     * @param string|null $languageCode        Language code (e.g., "en_US")
     * @param string|null $seDomain            Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param bool        $usePostback         Enable postback webhook (default: false)
     * @param bool        $usePingback         Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached Create task if not cached (default: false)
     * @param array       $additionalParams    Additional parameters
     * @param string|null $attributes          Optional attributes to store with cache entry
     * @param int         $amount              Amount to pass to incrementAttempts
     *
     * @return array|null Cached sellers data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function merchantAmazonSellersStandardAdvanced(
        string $asin,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        return $this->merchantAmazonSellersStandard(
            $asin,
            $priority,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            'advanced',
            $usePostback,
            $usePingback,
            $postTaskIfNotCached,
            $additionalParams,
            $attributes,
            $amount
        );
    }

    /**
     * Merchant Amazon Sellers Standard HTML method wrapper
     *
     * @param string      $asin                Amazon product ID (ASIN)
     * @param int|null    $priority            Task priority (1 - normal, 2 - high)
     * @param string|null $locationName        Location name (e.g., "London,England,United Kingdom")
     * @param int|null    $locationCode        Location code (e.g., 2840)
     * @param string|null $locationCoordinate  Location coordinates in format "latitude,longitude,radius"
     * @param string|null $languageName        Language name (e.g., "English")
     * @param string|null $languageCode        Language code (e.g., "en_US")
     * @param string|null $seDomain            Search engine domain (e.g., "amazon.com", "amazon.co.uk")
     * @param bool        $usePostback         Enable postback webhook (default: false)
     * @param bool        $usePingback         Enable pingback webhook (default: false)
     * @param bool        $postTaskIfNotCached Create task if not cached (default: false)
     * @param array       $additionalParams    Additional parameters
     * @param string|null $attributes          Optional attributes to store with cache entry
     * @param int         $amount              Amount to pass to incrementAttempts
     *
     * @return array|null Cached sellers data if available, task creation response if posting, null if not cached and posting disabled
     */
    public function merchantAmazonSellersStandardHtml(
        string $asin,
        ?int $priority = null,
        ?string $locationName = null,
        ?int $locationCode = 2840,
        ?string $locationCoordinate = null,
        ?string $languageName = null,
        ?string $languageCode = 'en_US',
        ?string $seDomain = null,
        bool $usePostback = false,
        bool $usePingback = false,
        bool $postTaskIfNotCached = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): ?array {
        return $this->merchantAmazonSellersStandard(
            $asin,
            $priority,
            $locationName,
            $locationCode,
            $locationCoordinate,
            $languageName,
            $languageCode,
            $seDomain,
            'html',
            $usePostback,
            $usePingback,
            $postTaskIfNotCached,
            $additionalParams,
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
     * Create an OnPage task using DataForSEO's Task POST endpoint
     *
     * @param string      $target                   Target domain (required, without https:// and www.)
     * @param int         $maxCrawlPages            Number of pages to crawl (required)
     * @param string|null $startUrl                 First URL to crawl (absolute URL)
     * @param bool|null   $forceSitewideChecks      Enable sitewide checks when crawling single page
     * @param array|null  $priorityUrls             URLs to crawl bypassing queue (max 20, absolute URLs)
     * @param int|null    $maxCrawlDepth            Crawl depth level
     * @param int|null    $crawlDelay               Delay between hits in ms (default: 2000)
     * @param bool|null   $storeRawHtml             Store HTML of crawled pages (default: false)
     * @param bool|null   $enableContentParsing     Parse content on crawled pages (default: false)
     * @param bool|null   $supportCookies           Support cookies when crawling (default: false)
     * @param string|null $acceptLanguage           Language header for accessing website
     * @param string|null $customRobotsTxt          Custom robots.txt settings
     * @param string|null $robotsTxtMergeMode       Merge mode: 'merge' or 'override' (default: 'merge')
     * @param string|null $customUserAgent          Custom user agent
     * @param string|null $browserPreset            Browser preset: 'desktop', 'mobile', 'tablet'
     * @param int|null    $browserScreenWidth       Browser screen width (240-9999 pixels)
     * @param int|null    $browserScreenHeight      Browser screen height (240-9999 pixels)
     * @param float|null  $browserScreenScaleFactor Browser screen scale factor (0.5-3)
     * @param bool|null   $respectSitemap           Follow sitemap order when crawling (default: false)
     * @param string|null $customSitemap            Custom sitemap URL
     * @param bool|null   $crawlSitemapOnly         Crawl only pages in sitemap (default: false)
     * @param bool|null   $loadResources            Load images, stylesheets, scripts (default: false)
     * @param bool|null   $enableWwwRedirectCheck   Check www redirection (default: false)
     * @param bool|null   $enableJavascript         Load JavaScript on pages (default: false)
     * @param bool|null   $enableXhr                Enable XMLHttpRequest (default: false)
     * @param bool|null   $enableBrowserRendering   Emulate browser for Core Web Vitals (default: false)
     * @param bool|null   $disableCookiePopup       Disable cookie consent popup (default: false)
     * @param string|null $customJs                 Custom JavaScript (max 2000 chars, 700ms execution)
     * @param bool|null   $validateMicromarkup      Enable microdata validation (default: false)
     * @param bool|null   $allowSubdomains          Include subdomains (default: false)
     * @param array|null  $allowedSubdomains        Specific subdomains to crawl
     * @param array|null  $disallowedSubdomains     Subdomains to exclude
     * @param bool|null   $checkSpell               Check spelling using Hunspell (default: false)
     * @param string|null $checkSpellLanguage       Spell check language code
     * @param array|null  $checkSpellExceptions     Words to exclude from spell check (max 1000, 100 chars each)
     * @param bool|null   $calculateKeywordDensity  Calculate keyword density (default: false)
     * @param array|null  $checksThreshold          Custom threshold values for checks
     * @param array|null  $disableSitewideChecks    Prevent certain sitewide checks
     * @param array|null  $disablePageChecks        Prevent certain page checks
     * @param bool|null   $switchPool               Use additional proxy pools (default: false)
     * @param bool|null   $returnDespiteTimeout     Return data despite timeout (default: false)
     * @param string|null $tag                      User-defined task identifier (max 255 chars)
     * @param string|null $pingbackUrl              Notification URL for task completion
     * @param array       $additionalParams         Additional parameters
     * @param string|null $attributes               Optional attributes to store with cache entry
     * @param int         $amount                   Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException If required fields are missing or invalid
     *
     * @return array The API response data
     */
    public function onPageTaskPost(
        string $target,
        int $maxCrawlPages,
        ?string $startUrl = null,
        ?bool $forceSitewideChecks = null,
        ?array $priorityUrls = null,
        ?int $maxCrawlDepth = null,
        ?int $crawlDelay = null,
        ?bool $storeRawHtml = null,
        ?bool $enableContentParsing = null,
        ?bool $supportCookies = null,
        ?string $acceptLanguage = null,
        ?string $customRobotsTxt = null,
        ?string $robotsTxtMergeMode = null,
        ?string $customUserAgent = null,
        ?string $browserPreset = null,
        ?int $browserScreenWidth = null,
        ?int $browserScreenHeight = null,
        ?float $browserScreenScaleFactor = null,
        ?bool $respectSitemap = null,
        ?string $customSitemap = null,
        ?bool $crawlSitemapOnly = null,
        ?bool $loadResources = null,
        ?bool $enableWwwRedirectCheck = null,
        ?bool $enableJavascript = null,
        ?bool $enableXhr = null,
        ?bool $enableBrowserRendering = null,
        ?bool $disableCookiePopup = null,
        ?string $customJs = null,
        ?bool $validateMicromarkup = null,
        ?bool $allowSubdomains = null,
        ?array $allowedSubdomains = null,
        ?array $disallowedSubdomains = null,
        ?bool $checkSpell = null,
        ?string $checkSpellLanguage = null,
        ?array $checkSpellExceptions = null,
        ?bool $calculateKeywordDensity = null,
        ?array $checksThreshold = null,
        ?array $disableSitewideChecks = null,
        ?array $disablePageChecks = null,
        ?bool $switchPool = null,
        ?bool $returnDespiteTimeout = null,
        ?string $tag = null,
        ?string $pingbackUrl = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate required fields
        if (empty($target)) {
            throw new \InvalidArgumentException('Target domain cannot be empty');
        }

        if ($maxCrawlPages <= 0) {
            throw new \InvalidArgumentException('max_crawl_pages must be a positive integer');
        }

        // Validate robots.txt merge mode
        if ($robotsTxtMergeMode !== null && !in_array($robotsTxtMergeMode, ['merge', 'override'])) {
            throw new \InvalidArgumentException('robots_txt_merge_mode must be either "merge" or "override"');
        }

        // Validate browser preset
        if ($browserPreset !== null && !in_array($browserPreset, ['desktop', 'mobile', 'tablet'])) {
            throw new \InvalidArgumentException('browser_preset must be one of: desktop, mobile, tablet');
        }

        // Validate browser screen dimensions
        if ($browserScreenWidth !== null && ($browserScreenWidth < 240 || $browserScreenWidth > 9999)) {
            throw new \InvalidArgumentException('browser_screen_width must be between 240 and 9999 pixels');
        }

        if ($browserScreenHeight !== null && ($browserScreenHeight < 240 || $browserScreenHeight > 9999)) {
            throw new \InvalidArgumentException('browser_screen_height must be between 240 and 9999 pixels');
        }

        if ($browserScreenScaleFactor !== null && ($browserScreenScaleFactor < 0.5 || $browserScreenScaleFactor > 3)) {
            throw new \InvalidArgumentException('browser_screen_scale_factor must be between 0.5 and 3');
        }

        // Validate priority URLs
        if ($priorityUrls !== null && count($priorityUrls) > 20) {
            throw new \InvalidArgumentException('priority_urls can contain maximum 20 URLs');
        }

        // Validate XHR dependency
        if ($enableXhr === true && $enableJavascript !== true) {
            throw new \InvalidArgumentException('enable_javascript must be set to true when enable_xhr is true');
        }

        // Validate browser rendering dependencies
        if ($enableBrowserRendering === true && ($enableJavascript !== true || $loadResources !== true)) {
            throw new \InvalidArgumentException('enable_javascript and load_resources must be set to true when enable_browser_rendering is true');
        }

        // Validate custom JS length
        if ($customJs !== null && strlen($customJs) > 2000) {
            throw new \InvalidArgumentException('custom_js must be 2000 characters or less');
        }

        // Validate spell check exceptions
        if ($checkSpellExceptions !== null) {
            if (count($checkSpellExceptions) > 1000) {
                throw new \InvalidArgumentException('check_spell_exceptions can contain maximum 1000 words');
            }
            foreach ($checkSpellExceptions as $word) {
                if (strlen($word) > 100) {
                    throw new \InvalidArgumentException('Each word in check_spell_exceptions must be 100 characters or less');
                }
            }
        }

        // Validate tag length
        if ($tag !== null && strlen($tag) > 255) {
            throw new \InvalidArgumentException('Tag must be 255 characters or less');
        }

        // Validate allowed/disallowed subdomains logic
        if ($allowedSubdomains !== null && $allowSubdomains !== false) {
            throw new \InvalidArgumentException('allow_subdomains must be set to false when using allowed_subdomains');
        }

        if ($disallowedSubdomains !== null && $allowSubdomains !== true) {
            throw new \InvalidArgumentException('allow_subdomains must be set to true when using disallowed_subdomains');
        }

        // Validate sitemap dependencies
        if ($customSitemap !== null && $respectSitemap !== true) {
            throw new \InvalidArgumentException('respect_sitemap must be set to true when using custom_sitemap');
        }

        if ($crawlSitemapOnly === true && $respectSitemap !== true) {
            throw new \InvalidArgumentException('respect_sitemap must be set to true when crawl_sitemap_only is true');
        }

        // Validate supported spell check languages
        $supportedSpellLanguages = [
            'hy', 'eu', 'bg', 'ca', 'hr', 'cs', 'da', 'nl', 'en', 'eo', 'et', 'fo', 'fa', 'fr', 'fy', 'gl', 'ka',
            'de', 'el', 'he', 'hu', 'is', 'ia', 'ga', 'it', 'rw', 'la', 'lv', 'lt', 'mk', 'mn', 'ne', 'nb', 'nn',
            'pl', 'pt', 'ro', 'gd', 'sr', 'sk', 'sl', 'es', 'sv', 'tr', 'tk', 'uk', 'vi',
        ];
        if ($checkSpellLanguage !== null && !in_array($checkSpellLanguage, $supportedSpellLanguages)) {
            throw new \InvalidArgumentException('check_spell_language must be a supported language code');
        }

        Log::debug(
            'Creating DataForSEO OnPage task',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Extra arguments needed for testing when passing parameters with the splat operator
        $params = $this->buildApiParams($additionalParams, [], __METHOD__, get_defined_vars());

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the target domain as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $target;
        }

        // Make the API request to the task post endpoint
        return $this->sendCachedRequest(
            'on_page/task_post',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get OnPage task summary using DataForSEO's Summary endpoint
     *
     * @param string      $id         The task ID from the Task POST response
     * @param string|null $attributes Optional attributes to store with cache entry
     * @param int         $amount     Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException If the task ID is empty
     *
     * @return array The API response data
     */
    public function onPageSummary(
        string $id,
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate task ID
        if (empty($id)) {
            throw new \InvalidArgumentException('Task ID cannot be empty');
        }

        Log::debug(
            'Making DataForSEO OnPage Summary request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Pass the task ID as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $id;
        }

        // Make the API request to the summary endpoint with the task ID in the URL
        return $this->sendCachedRequest(
            "on_page/summary/{$id}",
            [],
            'GET',
            $attributes,
            $amount
        );
    }

    /**
     * Get OnPage pages using DataForSEO's Pages endpoint
     *
     * @param string      $id               The task ID from the Task POST response
     * @param int|null    $limit            Maximum number of returned pages (default: 100, max: 1000)
     * @param int|null    $offset           Offset in the results array (default: 0)
     * @param array|null  $filters          Array of results filtering parameters
     * @param array|null  $orderBy          Results sorting rules
     * @param string|null $searchAfterToken Token for subsequent requests
     * @param string|null $tag              User-defined task identifier (max 255 chars)
     * @param array       $additionalParams Additional API parameters
     * @param string|null $attributes       Optional attributes to store with cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @return array The API response data
     */
    public function onPagePagesPost(
        string $id,
        ?int $limit = null,
        ?int $offset = null,
        ?array $filters = null,
        ?array $orderBy = null,
        ?string $searchAfterToken = null,
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate required task ID
        if (empty($id)) {
            throw new \InvalidArgumentException('Task ID cannot be empty');
        }

        // Validate limit
        if ($limit !== null && ($limit < 1 || $limit > 1000)) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000');
        }

        // Validate offset
        if ($offset !== null && $offset < 0) {
            throw new \InvalidArgumentException('Offset must be greater than or equal to 0');
        }

        // Validate filters array
        if ($filters !== null && count($filters) > 8) {
            throw new \InvalidArgumentException('Maximum 8 filters are allowed');
        }

        // Validate order by array (max 3 sorting rules)
        if ($orderBy !== null && count($orderBy) > 3) {
            throw new \InvalidArgumentException('Maximum 3 sorting rules are allowed');
        }

        // Validate tag length
        if ($tag !== null && strlen($tag) > 255) {
            throw new \InvalidArgumentException('Tag must be 255 characters or less');
        }

        Log::debug(
            'Making DataForSEO OnPage Pages request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Build API parameters, excluding additionalParams and other framework-specific args
        $params = $this->buildApiParams($additionalParams, [], __METHOD__, get_defined_vars());

        $tasks = [$params];

        // Pass the task ID as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $id;
        }

        // Make the API request to the pages endpoint
        return $this->sendCachedRequest(
            'on_page/pages',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get OnPage resources using DataForSEO's Resources endpoint
     *
     * @param string      $id                   The task ID from the Task POST response
     * @param string|null $url                  Page URL to get resources for a specific page
     * @param int|null    $limit                Maximum number of returned resources (default: 100, max: 1000)
     * @param int|null    $offset               Offset in the results array (default: 0)
     * @param array|null  $filters              Array of results filtering parameters
     * @param array|null  $relevantPagesFilters Filter resources by relevant pages
     * @param array|null  $orderBy              Results sorting rules
     * @param string|null $searchAfterToken     Token for subsequent requests
     * @param string|null $tag                  User-defined task identifier (max 255 chars)
     * @param array       $additionalParams     Additional API parameters
     * @param string|null $attributes           Optional attributes to store with cache entry
     * @param int         $amount               Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @return array The API response data
     */
    public function onPageResourcesPost(
        string $id,
        ?string $url = null,
        ?int $limit = null,
        ?int $offset = null,
        ?array $filters = null,
        ?array $relevantPagesFilters = null,
        ?array $orderBy = null,
        ?string $searchAfterToken = null,
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate required task ID
        if (empty($id)) {
            throw new \InvalidArgumentException('Task ID cannot be empty');
        }

        // Validate limit
        if ($limit !== null && ($limit < 1 || $limit > 1000)) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000');
        }

        // Validate offset
        if ($offset !== null && $offset < 0) {
            throw new \InvalidArgumentException('Offset must be greater than or equal to 0');
        }

        // Validate filters array
        if ($filters !== null && count($filters) > 8) {
            throw new \InvalidArgumentException('Maximum 8 filters are allowed');
        }

        // Validate relevant pages filters array
        if ($relevantPagesFilters !== null && count($relevantPagesFilters) > 8) {
            throw new \InvalidArgumentException('Maximum 8 relevant pages filters are allowed');
        }

        // Validate order by array (max 3 sorting rules)
        if ($orderBy !== null && count($orderBy) > 3) {
            throw new \InvalidArgumentException('Maximum 3 sorting rules are allowed');
        }

        // Validate tag length
        if ($tag !== null && strlen($tag) > 255) {
            throw new \InvalidArgumentException('Tag must be 255 characters or less');
        }

        Log::debug(
            'Making DataForSEO OnPage Resources request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Build API parameters, excluding additionalParams and other framework-specific args
        $params = $this->buildApiParams($additionalParams, [], __METHOD__, get_defined_vars());

        $tasks = [$params];

        // Pass the task ID as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $id;
        }

        // Make the API request to the resources endpoint with the task ID in the URL
        return $this->sendCachedRequest(
            'on_page/resources',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get OnPage waterfall using DataForSEO's Waterfall endpoint
     *
     * @param string      $id               The task ID from the Task POST response
     * @param string      $url              Page URL to receive timing for
     * @param string|null $tag              User-defined task identifier (max 255 chars)
     * @param array       $additionalParams Additional API parameters
     * @param string|null $attributes       Optional attributes to store with cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @return array The API response data
     */
    public function onPageWaterfallPost(
        string $id,
        string $url,
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate required task ID
        if (empty($id)) {
            throw new \InvalidArgumentException('Task ID cannot be empty');
        }

        // Validate required URL
        if (empty($url)) {
            throw new \InvalidArgumentException('URL cannot be empty');
        }

        // Validate tag length
        if ($tag !== null && strlen($tag) > 255) {
            throw new \InvalidArgumentException('Tag must be 255 characters or less');
        }

        Log::debug(
            'Making DataForSEO OnPage Waterfall request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Build API parameters, excluding additionalParams and other framework-specific args
        $params = $this->buildApiParams($additionalParams, [], __METHOD__, get_defined_vars());

        $tasks = [$params];

        // Pass the task ID as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $id;
        }

        // Make the API request to the waterfall endpoint
        return $this->sendCachedRequest(
            'on_page/waterfall',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get OnPage keyword density using DataForSEO's Keyword Density endpoint
     *
     * @param string      $id               The task ID from the Task POST response
     * @param int         $keywordLength    Number of words for a keyword (1-5)
     * @param string|null $url              Page URL (optional - if not specified, results for whole website)
     * @param int|null    $limit            Maximum number of returned keywords (default: 100, max: 1000)
     * @param array|null  $filters          Array of results filtering parameters (max 8 filters)
     * @param array|null  $orderBy          Results sorting rules (max 3 sorting rules)
     * @param string|null $tag              User-defined task identifier (max 255 chars)
     * @param array       $additionalParams Additional API parameters
     * @param string|null $attributes       Optional attributes to store with cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @return array The API response data
     */
    public function onPageKeywordDensityPost(
        string $id,
        int $keywordLength,
        ?string $url = null,
        ?int $limit = null,
        ?array $filters = null,
        ?array $orderBy = null,
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate required task ID
        if (empty($id)) {
            throw new \InvalidArgumentException('Task ID cannot be empty');
        }

        // Validate required keyword length
        if (!in_array($keywordLength, [1, 2, 3, 4, 5], true)) {
            throw new \InvalidArgumentException('Keyword length must be 1, 2, 3, 4, or 5');
        }

        // Validate limit
        if ($limit !== null && ($limit < 1 || $limit > 1000)) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000');
        }

        // Validate filters array
        if ($filters !== null && count($filters) > 8) {
            throw new \InvalidArgumentException('Maximum 8 filters are allowed');
        }

        // Validate order by array (max 3 sorting rules)
        if ($orderBy !== null && count($orderBy) > 3) {
            throw new \InvalidArgumentException('Maximum 3 sorting rules are allowed');
        }

        // Validate tag length
        if ($tag !== null && strlen($tag) > 255) {
            throw new \InvalidArgumentException('Tag must be 255 characters or less');
        }

        Log::debug(
            'Making DataForSEO OnPage Keyword Density request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Build API parameters, excluding additionalParams and other framework-specific args
        $params = $this->buildApiParams($additionalParams, [], __METHOD__, get_defined_vars());

        $tasks = [$params];

        // Pass the task ID as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $id;
        }

        // Make the API request to the keyword_density endpoint
        return $this->sendCachedRequest(
            'on_page/keyword_density',
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
    public function onPageRawHtmlPost(
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
     * Get content parsing from DataForSEO's OnPage API
     *
     * @param string      $url              URL of the content to parse
     * @param string      $id               ID of the task (enable_content_parsing must be set to true in POST)
     * @param bool|null   $markdownView     Return page content as markdown (default: false)
     * @param array       $additionalParams Additional parameters
     * @param string|null $attributes       Optional attributes to store with cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @return array The API response data
     */
    public function onPageContentParsingPost(
        string $url,
        string $id,
        ?bool $markdownView = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate required URL
        if (empty($url)) {
            throw new \InvalidArgumentException('URL cannot be empty');
        }

        // Validate required task ID
        if (empty($id)) {
            throw new \InvalidArgumentException('Task ID cannot be empty');
        }

        Log::debug(
            'Making DataForSEO OnPage Content Parsing request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Build API parameters, excluding additionalParams and other framework-specific args
        $params = $this->buildApiParams($additionalParams, [], __METHOD__, get_defined_vars());

        $tasks = [$params];

        // Pass the task ID as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $id;
        }

        // Make the API request to the content_parsing endpoint
        return $this->sendCachedRequest(
            'on_page/content_parsing',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get backlinks summary data using DataForSEO's Backlinks Summary Live API
     *
     * @param string      $target                   Domain, subdomain or webpage to get data for (required)
     * @param bool|null   $includeSubdomains        Include subdomains in search (default: true)
     * @param bool|null   $includeIndirectLinks     Include indirect links (default: true)
     * @param bool|null   $excludeInternalBacklinks Exclude internal backlinks from subdomains (default: true)
     * @param int|null    $internalListLimit        Maximum elements within internal arrays (default: 10, max: 1000)
     * @param string|null $backlinksStatusType      Type of backlinks to return ('all', 'live', 'lost', default: 'live')
     * @param array|null  $backlinksFilters         Filter the backlinks of your target
     * @param string|null $rankScale                Scale for rank values ('one_hundred', 'one_thousand', default: 'one_thousand')
     * @param string|null $tag                      User-defined task identifier (max 255 characters)
     * @param array       $additionalParams         Additional parameters
     * @param string|null $attributes               Optional attributes to store with cache entry
     * @param int         $amount                   Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @return array The API response data
     */
    public function backlinksSummaryLive(
        string $target,
        ?bool $includeSubdomains = true,
        ?bool $includeIndirectLinks = true,
        ?bool $excludeInternalBacklinks = true,
        ?int $internalListLimit = 10,
        ?string $backlinksStatusType = 'live',
        ?array $backlinksFilters = null,
        ?string $rankScale = 'one_thousand',
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate required target parameter
        if (empty($target)) {
            throw new \InvalidArgumentException('Target cannot be empty');
        }

        // Validate internal_list_limit parameter
        if ($internalListLimit !== null && ($internalListLimit < 1 || $internalListLimit > 1000)) {
            throw new \InvalidArgumentException('internal_list_limit must be between 1 and 1000');
        }

        // Validate backlinks_status_type parameter
        if ($backlinksStatusType !== null && !in_array($backlinksStatusType, ['all', 'live', 'lost'])) {
            throw new \InvalidArgumentException('backlinks_status_type must be one of: all, live, lost');
        }

        // Validate rank_scale parameter
        if ($rankScale !== null && !in_array($rankScale, ['one_hundred', 'one_thousand'])) {
            throw new \InvalidArgumentException('rank_scale must be one of: one_hundred, one_thousand');
        }

        // Validate tag parameter length
        if ($tag !== null && strlen($tag) > 255) {
            throw new \InvalidArgumentException('Tag must be 255 characters or less');
        }

        Log::debug(
            'Making DataForSEO Backlinks Summary Live request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Build API parameters, excluding additionalParams and other framework-specific args
        $params = $this->buildApiParams($additionalParams, [], __METHOD__, get_defined_vars());

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the target as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $target;
        }

        // Make the API request to the backlinks summary live endpoint
        return $this->sendCachedRequest(
            'backlinks/summary/live',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }

    /**
     * Get backlinks history data using DataForSEO's Backlinks History Live API
     *
     * @param string      $target           Domain to get data for (required, without https:// and www.)
     * @param string|null $dateFrom         Starting date of the time range (format: yyyy-mm-dd, minimum: 2019-01-01)
     * @param string|null $dateTo           Ending date of the time range (format: yyyy-mm-dd, defaults to today)
     * @param string|null $rankScale        Scale for rank values ('one_hundred', 'one_thousand', default: 'one_thousand')
     * @param string|null $tag              User-defined task identifier (max 255 characters)
     * @param array       $additionalParams Additional parameters
     * @param string|null $attributes       Optional attributes to store with cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @return array The API response data
     */
    public function backlinksHistoryLive(
        string $target,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $rankScale = 'one_thousand',
        ?string $tag = null,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        // Validate required target parameter
        if (empty($target)) {
            throw new \InvalidArgumentException('Target cannot be empty');
        }

        // Validate date_from parameter
        if ($dateFrom !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
                throw new \InvalidArgumentException('date_from must be in yyyy-mm-dd format');
            }
            if ($dateFrom < '2019-01-01') {
                throw new \InvalidArgumentException('date_from must be 2019-01-01 or later');
            }
        }

        // Validate date_to parameter
        if ($dateTo !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            throw new \InvalidArgumentException('date_to must be in yyyy-mm-dd format');
        }

        // Validate rank_scale parameter
        if ($rankScale !== null && !in_array($rankScale, ['one_hundred', 'one_thousand'])) {
            throw new \InvalidArgumentException('rank_scale must be one of: one_hundred, one_thousand');
        }

        // Validate tag parameter length
        if ($tag !== null && strlen($tag) > 255) {
            throw new \InvalidArgumentException('Tag must be 255 characters or less');
        }

        Log::debug(
            'Making DataForSEO Backlinks History Live request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Build API parameters, excluding additionalParams and other framework-specific args
        $params = $this->buildApiParams($additionalParams, [], __METHOD__, get_defined_vars());

        // DataForSEO API requires an array of tasks
        $tasks = [$params];

        // Pass the target as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $target;
        }

        // Make the API request to the backlinks history live endpoint
        return $this->sendCachedRequest(
            'backlinks/history/live',
            $tasks,
            'POST',
            $attributes,
            $amount
        );
    }
}
