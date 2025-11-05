<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use FOfX\Helper\ReflectionUtils;
use FOfX\Utility;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

class ZyteApiClient extends BaseApiClient
{
    /**
     * Constructor for ZyteApiClient
     *
     * @param ApiCacheManager|null $cacheManager Optional manager for caching and rate limiting
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        Log::debug('Initializing Zyte API client');

        $clientName = 'zyte';

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
     * Zyte API uses Basic authentication with API key
     *
     * @return array Authentication headers
     */
    public function getAuthHeaders(): array
    {
        if ($this->apiKey === null) {
            return [];
        }

        // Zyte API uses Basic auth with API key + colon, base64 encoded
        $credentials = base64_encode($this->apiKey . ':');

        return [
            'Authorization' => 'Basic ' . $credentials,
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Log HTTP error with Zyte API specific error message extraction
     *
     * @param int         $statusCode HTTP status code
     * @param string|null $message    Error message
     * @param array       $context    Additional context data
     * @param string|null $response   Response body
     */
    public function logHttpError(int $statusCode, ?string $message = null, array $context = [], ?string $response = null): void
    {
        $apiMessage = null;

        // Extract Zyte API error detail from response
        if ($response !== null) {
            $data = json_decode($response, true);
            if ($data && isset($data['detail'])) {
                $apiMessage = $data['detail'];
            }
        }

        $message = $message ?? 'HTTP error';
        $this->logApiError('http_error', $message, array_merge(['status_code' => $statusCode], $context), $response, $apiMessage);
    }

    /**
     * Calculate the number of credits required for a request
     *
     * @param array $requestData The request data to calculate credits for
     *
     * @return int Number of credits required
     */
    public function calculateCredits(array $requestData = []): int
    {
        $credits = 1;

        return $credits;
    }

    /**
     * Save screenshot from a response to file
     *
     * @param int $rowId Database row ID containing the screenshot response
     *
     * @throws \InvalidArgumentException If row doesn't contain screenshot=true
     * @throws \RuntimeException         If screenshot data is not found in response
     *
     * @return string Path to saved screenshot file
     */
    public function saveScreenshot(int $rowId): string
    {
        $tableName = $this->getTableName();

        try {
            // Get the row from database
            $row = DB::table($tableName)->where('id', $rowId)->first();

            if (!$row) {
                throw new \InvalidArgumentException("Row with ID {$rowId} not found");
            }

            // Check if request was for screenshot
            $requestBody = json_decode($row->request_body, true);
            if (!$requestBody || !isset($requestBody['screenshot']) || $requestBody['screenshot'] !== true) {
                throw new \InvalidArgumentException("Row {$rowId} does not contain screenshot=true in request_body");
            }

            // Get file extension from screenshotOptions.format, default to jpeg
            $extension = 'jpeg';
            if (isset($requestBody['screenshotOptions']['format'])) {
                $extension = $requestBody['screenshotOptions']['format'];
            }

            // Get screenshot from response
            $responseBody = json_decode($row->response_body, true);
            if (!$responseBody || !isset($responseBody['screenshot'])) {
                throw new \RuntimeException("Screenshot data not found in response for row {$rowId}");
            }

            // Generate filename and relative path for Storage facade
            $filename     = "screenshot_{$rowId}.{$extension}";
            $relativePath = "{$this->clientName}/screenshots/{$filename}";

            // Decode and save screenshot (base64 encoded) using Storage facade
            $screenshotData = base64_decode($responseBody['screenshot']);
            Storage::disk('local')->put($relativePath, $screenshotData);

            // Get file size
            $fileSize = Storage::disk('local')->size($relativePath);

            // Update processing status - success
            DB::table($tableName)
                ->where('id', $rowId)
                ->update([
                    'processed_at'     => now(),
                    'processed_status' => json_encode([
                        'status'    => 'OK',
                        'error'     => null,
                        'filename'  => $filename,
                        'file_size' => $fileSize,
                        'format'    => $extension,
                    ], JSON_PRETTY_PRINT),
                ]);

            return $relativePath;
        } catch (\Exception $e) {
            // Update processing status - error
            DB::table($tableName)
                ->where('id', $rowId)
                ->update([
                    'processed_at'     => now(),
                    'processed_status' => json_encode([
                        'status'    => 'ERROR',
                        'error'     => $e->getMessage(),
                        'filename'  => null,
                        'file_size' => null,
                        'format'    => null,
                    ], JSON_PRETTY_PRINT),
                ]);

            throw $e;
        }
    }

    /**
     * Extract data from a URL using Zyte API
     *
     * All parameters follow the exact order from zyte-api-reference-request-body-schema-chart-chatgpt.md
     *
     * @param string      $url                         The URL to extract data from (required)
     * @param array|null  $requestHeaders              HTTP request headers (browser only; only supports the Referer header)
     * @param array|null  $tags                        Assign arbitrary key-value pairs to the request that you can use for filtering in the Stats API
     * @param string|null $ipType                      IP type: datacenter, residential
     * @param string|null $httpRequestMethod           HTTP method: GET, POST, PUT, DELETE, OPTIONS, TRACE, PATCH, HEAD
     * @param string|null $httpRequestBody             Base64-encoded request body (≤400000 chars)
     * @param string|null $httpRequestText             UTF-8 text request body (1-400000 chars)
     * @param array|null  $customHttpRequestHeaders    Can only be used in combination with httpResponseBody. To set headers with other outputs, see requestHeaders.
     * @param bool|null   $httpResponseBody            Set to true to get the HTTP response body in the httpResponseBody response field
     * @param bool|null   $httpResponseHeaders         Set to true to get the HTTP response headers in the httpResponseHeaders response field
     * @param bool|null   $browserHtml                 Set to true to get the browser HTML in the browserHtml response field
     * @param bool|null   $screenshot                  Set to true to get a page screenshot in the screenshot response field
     * @param array|null  $screenshotOptions           Options for the screenshot taken when the screenshot request field is true
     * @param bool|null   $article                     Extract article data
     * @param array|null  $articleOptions              Article extraction options
     * @param bool|null   $articleList                 Extract article list data
     * @param array|null  $articleListOptions          Article list extraction options
     * @param bool|null   $articleNavigation           Extract article navigation data
     * @param array|null  $articleNavigationOptions    Article navigation extraction options
     * @param bool|null   $forumThread                 Extract forum thread data
     * @param array|null  $forumThreadOptions          Forum thread extraction options
     * @param bool|null   $jobPosting                  Extract job posting data
     * @param array|null  $jobPostingOptions           Job posting extraction options
     * @param bool|null   $jobPostingNavigation        Extract job posting navigation
     * @param array|null  $jobPostingNavigationOptions Job posting navigation extraction options
     * @param bool|null   $pageContent                 Extract page content data
     * @param array|null  $pageContentOptions          Page content extraction options
     * @param bool|null   $product                     Extract product data
     * @param array|null  $productOptions              Product extraction options
     * @param bool|null   $productList                 Extract product list data
     * @param array|null  $productListOptions          Product list extraction options
     * @param bool|null   $productNavigation           Extract product navigation
     * @param array|null  $productNavigationOptions    Product navigation extraction options
     * @param array|null  $customAttributes            Schema for custom data extraction
     * @param array|null  $customAttributesOptions     Custom attributes configuration
     * @param string|null $geolocation                 ISO 3166-1 alpha-2 country code
     * @param bool|null   $javascript                  Force JavaScript execution enabled/disabled. This field is not compatible with HTTP requests.
     * @param array|null  $actions                     Browser action sequence
     * @param string|null $jobId                       Scrapy Cloud job ID (≤100 chars)
     * @param mixed       $echoData                    Data to echo back in response
     * @param array|null  $viewport                    Browser viewport settings
     * @param bool|null   $followRedirect              Whether to follow HTTP redirects. Only supported in HTTP requests, browser requests always follow redirection.
     * @param array|null  $sessionContext              Server-managed session context
     * @param array|null  $sessionContextParameters    Session initialization parameters
     * @param array|null  $session                     Client-managed session
     * @param array|null  $networkCapture              Network response capture filters
     * @param string|null $device                      Device type: desktop, mobile
     * @param string|null $cookieManagement            Cookie handling: auto, discard
     * @param array|null  $requestCookies              Cookies to send with request
     * @param bool|null   $responseCookies             Get response cookies
     * @param bool|null   $serp                        Extract SERP data (Google only). Currently, you cannot combine this field with any other request fields besides serpOptions and url.
     * @param array|null  $serpOptions                 SERP extraction options
     * @param bool|null   $includeIframes              Include iframe content in browserHtml. Note that iframes are visible in screenshots even if this is set to false.
     * @param array       $additionalParams            Additional parameters to pass to the API
     * @param string|null $attributes                  Optional attributes to store with the cache entry
     * @param string|null $attributes2                 Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3                 Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount                      Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @throws \InvalidArgumentException If validation of required fields fails
     *
     * @return array The API response data
     */
    public function extract(
        string $url,
        ?array $requestHeaders = null,
        ?array $tags = null,
        ?string $ipType = null,
        ?string $httpRequestMethod = null,
        ?string $httpRequestBody = null,
        ?string $httpRequestText = null,
        ?array $customHttpRequestHeaders = null,
        ?bool $httpResponseBody = null,
        ?bool $httpResponseHeaders = null,
        ?bool $browserHtml = null,
        ?bool $screenshot = null,
        ?array $screenshotOptions = null,
        ?bool $article = null,
        ?array $articleOptions = null,
        ?bool $articleList = null,
        ?array $articleListOptions = null,
        ?bool $articleNavigation = null,
        ?array $articleNavigationOptions = null,
        ?bool $forumThread = null,
        ?array $forumThreadOptions = null,
        ?bool $jobPosting = null,
        ?array $jobPostingOptions = null,
        ?bool $jobPostingNavigation = null,
        ?array $jobPostingNavigationOptions = null,
        ?bool $pageContent = null,
        ?array $pageContentOptions = null,
        ?bool $product = null,
        ?array $productOptions = null,
        ?bool $productList = null,
        ?array $productListOptions = null,
        ?bool $productNavigation = null,
        ?array $productNavigationOptions = null,
        ?array $customAttributes = null,
        ?array $customAttributesOptions = null,
        ?string $geolocation = null,
        ?bool $javascript = null,
        ?array $actions = null,
        ?string $jobId = null,
        mixed $echoData = null,
        ?array $viewport = null,
        ?bool $followRedirect = null,
        ?array $sessionContext = null,
        ?array $sessionContextParameters = null,
        ?array $session = null,
        ?array $networkCapture = null,
        ?string $device = null,
        ?string $cookieManagement = null,
        ?array $requestCookies = null,
        ?bool $responseCookies = null,
        ?bool $serp = null,
        ?array $serpOptions = null,
        ?bool $includeIframes = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        Log::debug(
            'Making Zyte API extract request',
            ReflectionUtils::extractArgs(__METHOD__, get_defined_vars())
        );

        // Validate that at least one required field type is set
        // Either one of the four browser interaction fields must be true,
        // OR one of the automatic extraction request fields must be true
        $browserInteractionFields = [
            'browserHtml'         => $browserHtml,
            'httpResponseBody'    => $httpResponseBody,
            'httpResponseHeaders' => $httpResponseHeaders,
            'screenshot'          => $screenshot,
        ];

        $automaticExtractionRequestFields = [
            'article'              => $article,
            'articleList'          => $articleList,
            'articleNavigation'    => $articleNavigation,
            'forumThread'          => $forumThread,
            'jobPosting'           => $jobPosting,
            'jobPostingNavigation' => $jobPostingNavigation,
            'pageContent'          => $pageContent,
            'product'              => $product,
            'productList'          => $productList,
            'productNavigation'    => $productNavigation,
            'serp'                 => $serp,
        ];

        $hasBrowserInteraction  = array_filter($browserInteractionFields, fn ($value) => $value === true);
        $hasAutomaticExtraction = array_filter($automaticExtractionRequestFields, fn ($value) => $value === true);

        if (empty($hasBrowserInteraction) && empty($hasAutomaticExtraction)) {
            throw new \InvalidArgumentException(
                'At least one of the following request fields must be set to true: (' .
                implode(', ', array_keys($browserInteractionFields)) . ') OR one of the automatic extraction fields: (' .
                implode(', ', array_keys($automaticExtractionRequestFields)) . ')'
            );
        }

        // Build request data using buildApiParams to filter nulls and merge additional params
        $requestData = array_merge(['url' => $url], $this->buildApiParams($additionalParams, [], __METHOD__, get_defined_vars()));

        // Calculate credits required for this request if amount is not provided
        if ($amount === null) {
            $credits = $this->calculateCredits($requestData);
        } else {
            $credits = $amount;
        }

        // Use URL as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $url;
        }

        // Pass extract_registrable_domain() as attributes2 if not provided
        if ($attributes2 === null) {
            try {
                $attributes2 = Utility\extract_registrable_domain($url);
            } catch (\Exception $e) {
                Log::warning('Failed to extract registrable domain from URL', [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
                $attributes2 = null;
            }
        }

        return $this->sendCachedRequest('extract', $requestData, 'POST', $attributes, $attributes2, $attributes3, amount: $credits);
    }

    /**
     * Simplified extract method for common use cases
     *
     * This method provides a user-friendly interface for the most commonly used
     * Zyte API features. For full control, use the main extract() method.
     *
     * @param string      $url                 The URL to extract data from
     * @param bool|null   $httpResponseBody    Set to true to get the HTTP response body in the httpResponseBody response field
     * @param bool|null   $httpResponseHeaders Set to true to get the HTTP response headers in the httpResponseHeaders response field
     * @param bool|null   $browserHtml         Set to true to get the browser HTML in the browserHtml response field
     * @param bool|null   $screenshot          Set to true to get a page screenshot in the screenshot response field
     * @param bool|null   $article             Set to true to get article data in the article response field
     * @param bool|null   $product             Set to true to get product data in the product response field
     * @param bool|null   $serp                Set to true to get the data of a search engine results page (SERP) in the serp response field. Currently, you cannot combine this field with any other request fields besides serpOptions and url.
     * @param string|null $geolocation         ISO 3166-1 alpha-2 country code
     * @param bool|null   $javascript          Forces JavaScript execution on a browser request to be enabled (true) or disabled (false). This field is not compatible with HTTP requests.
     * @param bool|null   $followRedirect      Whether to follow HTTP redirection or not. Only supported in HTTP requests, browser requests always follow redirection.
     * @param string|null $ipType              Type of IP address from which the request should be sent: datacenter, residential
     * @param string|null $device              Type of device to emulate during your request: desktop, mobile
     * @param array       $additionalParams    Additional parameters to pass to the API
     * @param string|null $attributes          Optional attributes to store with the cache entry
     * @param string|null $attributes2         Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3         Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount              Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractCommon(
        string $url,
        ?bool $httpResponseBody = null,
        ?bool $httpResponseHeaders = null,
        ?bool $browserHtml = null,
        ?bool $screenshot = null,
        ?bool $article = null,
        ?bool $product = null,
        ?bool $serp = null,
        ?string $geolocation = null,
        ?bool $javascript = null,
        ?bool $followRedirect = null,
        ?string $ipType = null,
        ?string $device = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'common';
        }

        return $this->extract(
            url: $url,
            httpResponseBody: $httpResponseBody,
            httpResponseHeaders: $httpResponseHeaders,
            browserHtml: $browserHtml,
            screenshot: $screenshot,
            article: $article,
            product: $product,
            serp: $serp,
            geolocation: $geolocation,
            javascript: $javascript,
            followRedirect: $followRedirect,
            ipType: $ipType,
            device: $device,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract browser HTML from a URL
     *
     * Gets the browser-rendered HTML after JavaScript execution. This field is not
     * compatible with HTTP requests and always uses browser rendering.
     *
     * @param string      $url              The URL to extract browser HTML from
     * @param array|null  $requestHeaders   HTTP request headers (browser only; only supports the Referer header)
     * @param array|null  $tags             Assign arbitrary key-value pairs to the request that you can use for filtering in the Stats API
     * @param string|null $ipType           IP type: datacenter, residential
     * @param string|null $geolocation      ISO 3166-1 alpha-2 country code
     * @param bool|null   $javascript       Force JavaScript execution enabled/disabled. This field is not compatible with HTTP requests.
     * @param array|null  $actions          Browser action sequence
     * @param array|null  $viewport         Browser viewport settings
     * @param bool|null   $includeIframes   Include iframe content in browserHtml
     * @param array       $additionalParams Additional parameters to pass to the API
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param string|null $attributes2      Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3      Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount           Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractBrowserHtml(
        string $url,
        ?array $requestHeaders = null,
        ?array $tags = null,
        ?string $ipType = null,
        ?string $geolocation = null,
        ?bool $javascript = null,
        ?array $actions = null,
        ?array $viewport = null,
        ?bool $includeIframes = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'browserHtml';
        }

        return $this->extract(
            url: $url,
            requestHeaders: $requestHeaders,
            tags: $tags,
            ipType: $ipType,
            browserHtml: true,
            geolocation: $geolocation,
            javascript: $javascript,
            actions: $actions,
            viewport: $viewport,
            includeIframes: $includeIframes,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract HTTP response body from a URL
     *
     * Gets the HTTP response body without browser rendering. This is for HTTP-only requests.
     *
     * @param string      $url                      The URL to extract data from (required)
     * @param array|null  $tags                     Assign arbitrary key-value pairs to the request that you can use for filtering in the Stats API
     * @param string|null $ipType                   IP type: datacenter, residential
     * @param string|null $httpRequestMethod        HTTP method: GET, POST, PUT, DELETE, OPTIONS, TRACE, PATCH, HEAD
     * @param string|null $httpRequestBody          Base64-encoded request body (≤400000 chars)
     * @param string|null $httpRequestText          UTF-8 text request body (1-400000 chars)
     * @param array|null  $customHttpRequestHeaders Can only be used in combination with httpResponseBody. To set headers with other outputs, see requestHeaders.
     * @param bool|null   $httpResponseHeaders      Set to true to get the HTTP response headers in the httpResponseHeaders response field
     * @param string|null $geolocation              ISO 3166-1 alpha-2 country code
     * @param string|null $jobId                    Scrapy Cloud job ID (≤100 chars)
     * @param mixed       $echoData                 Data to echo back in response
     * @param bool|null   $followRedirect           Whether to follow HTTP redirects. Only supported in HTTP requests, browser requests always follow redirection.
     * @param array|null  $sessionContext           Server-managed session context
     * @param array|null  $sessionContextParameters Session initialization parameters
     * @param array|null  $session                  Client-managed session
     * @param string|null $device                   Device type: desktop, mobile
     * @param string|null $cookieManagement         Cookie handling: auto, discard
     * @param array|null  $requestCookies           Cookies to send with request
     * @param bool|null   $responseCookies          Get response cookies
     * @param array       $additionalParams         Additional parameters to pass to the API
     * @param string|null $attributes               Optional attributes to store with the cache entry
     * @param string|null $attributes2              Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3              Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount                   Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractHttpResponseBody(
        string $url,
        ?array $tags = null,
        ?string $ipType = null,
        ?string $httpRequestMethod = null,
        ?string $httpRequestBody = null,
        ?string $httpRequestText = null,
        ?array $customHttpRequestHeaders = null,
        ?bool $httpResponseHeaders = null,
        ?string $geolocation = null,
        ?string $jobId = null,
        mixed $echoData = null,
        ?bool $followRedirect = null,
        ?array $sessionContext = null,
        ?array $sessionContextParameters = null,
        ?array $session = null,
        ?string $device = null,
        ?string $cookieManagement = null,
        ?array $requestCookies = null,
        ?bool $responseCookies = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'httpResponseBody';
        }

        return $this->extract(
            url: $url,
            tags: $tags,
            ipType: $ipType,
            httpRequestMethod: $httpRequestMethod,
            httpRequestBody: $httpRequestBody,
            httpRequestText: $httpRequestText,
            customHttpRequestHeaders: $customHttpRequestHeaders,
            httpResponseBody: true,
            httpResponseHeaders: $httpResponseHeaders,
            geolocation: $geolocation,
            jobId: $jobId,
            echoData: $echoData,
            followRedirect: $followRedirect,
            sessionContext: $sessionContext,
            sessionContextParameters: $sessionContextParameters,
            session: $session,
            device: $device,
            cookieManagement: $cookieManagement,
            requestCookies: $requestCookies,
            responseCookies: $responseCookies,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract article data from a URL
     *
     * @param string      $url              The URL to extract article data from
     * @param array|null  $articleOptions   Article extraction options
     * @param array       $additionalParams Additional parameters to pass to the API
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param string|null $attributes2      Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3      Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount           Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractArticle(
        string $url,
        ?array $articleOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'article';
        }

        return $this->extract(
            url: $url,
            article: true,
            articleOptions: $articleOptions,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract article list data from a URL
     *
     * @param string      $url                The URL to extract article list data from
     * @param array|null  $articleListOptions Article list extraction options
     * @param array       $additionalParams   Additional parameters to pass to the API
     * @param string|null $attributes         Optional attributes to store with the cache entry
     * @param string|null $attributes2        Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3        Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount             Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractArticleList(
        string $url,
        ?array $articleListOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'articleList';
        }

        return $this->extract(
            url: $url,
            articleList: true,
            articleListOptions: $articleListOptions,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract article navigation data from a URL
     *
     * @param string      $url                      The URL to extract article navigation data from
     * @param array|null  $articleNavigationOptions Article navigation extraction options
     * @param array       $additionalParams         Additional parameters to pass to the API
     * @param string|null $attributes               Optional attributes to store with the cache entry
     * @param string|null $attributes2              Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3              Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount                   Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractArticleNavigation(
        string $url,
        ?array $articleNavigationOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'articleNavigation';
        }

        return $this->extract(
            url: $url,
            articleNavigation: true,
            articleNavigationOptions: $articleNavigationOptions,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract forum thread data from a URL
     *
     * @param string      $url                The URL to extract forum thread data from
     * @param array|null  $forumThreadOptions Forum thread extraction options
     * @param array       $additionalParams   Additional parameters to pass to the API
     * @param string|null $attributes         Optional attributes to store with the cache entry
     * @param string|null $attributes2        Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3        Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount             Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractForumThread(
        string $url,
        ?array $forumThreadOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'forumThread';
        }

        return $this->extract(
            url: $url,
            forumThread: true,
            forumThreadOptions: $forumThreadOptions,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract job posting data from a URL
     *
     * @param string      $url               The URL to extract job posting data from
     * @param array|null  $jobPostingOptions Job posting extraction options
     * @param array       $additionalParams  Additional parameters to pass to the API
     * @param string|null $attributes        Optional attributes to store with the cache entry
     * @param string|null $attributes2       Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3       Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount            Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractJobPosting(
        string $url,
        ?array $jobPostingOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'jobPosting';
        }

        return $this->extract(
            url: $url,
            jobPosting: true,
            jobPostingOptions: $jobPostingOptions,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract job posting navigation data from a URL
     *
     * @param string      $url                         The URL to extract job posting navigation data from
     * @param array|null  $jobPostingNavigationOptions Job posting navigation extraction options
     * @param array       $additionalParams            Additional parameters to pass to the API
     * @param string|null $attributes                  Optional attributes to store with the cache entry
     * @param string|null $attributes2                 Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3                 Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount                      Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractJobPostingNavigation(
        string $url,
        ?array $jobPostingNavigationOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'jobPostingNavigation';
        }

        return $this->extract(
            url: $url,
            jobPostingNavigation: true,
            jobPostingNavigationOptions: $jobPostingNavigationOptions,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract page content data from a URL
     *
     * @param string      $url                The URL to extract page content data from
     * @param array|null  $pageContentOptions Page content extraction options
     * @param array       $additionalParams   Additional parameters to pass to the API
     * @param string|null $attributes         Optional attributes to store with the cache entry
     * @param string|null $attributes2        Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3        Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount             Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractPageContent(
        string $url,
        ?array $pageContentOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'pageContent';
        }

        return $this->extract(
            url: $url,
            pageContent: true,
            pageContentOptions: $pageContentOptions,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract product data from a URL
     *
     * @param string      $url              The URL to extract product data from
     * @param array|null  $productOptions   Product extraction options
     * @param array       $additionalParams Additional parameters to pass to the API
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param string|null $attributes2      Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3      Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount           Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractProduct(
        string $url,
        ?array $productOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'product';
        }

        return $this->extract(
            url: $url,
            product: true,
            productOptions: $productOptions,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract product list data from a URL
     *
     * @param string      $url                The URL to extract product list data from
     * @param array|null  $productListOptions Product list extraction options
     * @param array       $additionalParams   Additional parameters to pass to the API
     * @param string|null $attributes         Optional attributes to store with the cache entry
     * @param string|null $attributes2        Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3        Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount             Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractProductList(
        string $url,
        ?array $productListOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'productList';
        }

        return $this->extract(
            url: $url,
            productList: true,
            productListOptions: $productListOptions,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract product navigation data from a URL
     *
     * @param string      $url                      The URL to extract product navigation data from
     * @param array|null  $productNavigationOptions Product navigation extraction options
     * @param array       $additionalParams         Additional parameters to pass to the API
     * @param string|null $attributes               Optional attributes to store with the cache entry
     * @param string|null $attributes2              Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3              Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount                   Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractProductNavigation(
        string $url,
        ?array $productNavigationOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'productNavigation';
        }

        return $this->extract(
            url: $url,
            productNavigation: true,
            productNavigationOptions: $productNavigationOptions,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract SERP data from a Google search URL
     *
     * @param string      $url              The Google search URL to extract SERP data from
     * @param array|null  $serpOptions      SERP extraction options
     * @param array       $additionalParams Additional parameters to pass to the API
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param string|null $attributes2      Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3      Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount           Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function extractSerp(
        string $url,
        ?array $serpOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'serp';
        }

        return $this->extract(
            url: $url,
            serp: true,
            serpOptions: $serpOptions,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Extract custom attributes from a URL using AI
     *
     * Uses Zyte's Large Language Model (LLM) to extract structured data based on your custom schema.
     * A standard extraction field (like article or product) must be specified to determine which
     * part of the page to analyze, making extraction more accurate and cost-effective.
     *
     * Example:
     * ```php
     * $url = 'https://www.zyte.com/blog/intercept-network-patterns-within-zyte-api/';
     * $customAttributes = [
     *     'summary' => [
     *         'type' => 'string',
     *         'description' => 'A two sentence article summary'
     *     ],
     *     'article_sentiment' => [
     *         'type' => 'string',
     *         'enum' => ['positive', 'negative', 'neutral']
     *     ]
     * ];
     * $response = $client->extractCustomAttributes($url, $customAttributes, 'article');
     * $extractedData = $response['response']->json()['customAttributes']['values'];
     * print_r($extractedData);
     * ```
     *
     * @param string      $url                     The URL to extract custom attributes from
     * @param array       $customAttributes        Schema of custom attributes to extract (OpenAPI JSON syntax)
     * @param string      $extractionType          Required base extraction type: article, product, pageContent, etc.
     * @param array|null  $customAttributesOptions Custom attributes options:
     *                                             - method: "generate" (default, powerful but expensive) or "extract" (cheaper, limited)
     *                                             - maxInputTokens: Limit input tokens to control cost
     *                                             - maxOutputTokens: Limit output tokens to control cost
     * @param array       $additionalParams        Additional parameters to pass to the API
     * @param string|null $attributes              Optional attributes to store with the cache entry
     * @param string|null $attributes2             Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3             Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount                  Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data with customAttributes.values containing extracted data
     *
     * @see https://docs.zyte.com/zyte-api/usage/extract/custom-attributes.html
     */
    public function extractCustomAttributes(
        string $url,
        array $customAttributes,
        string $extractionType = 'article',
        ?array $customAttributesOptions = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Validate extraction type first
        $validTypes = ['article', 'articleList', 'articleNavigation', 'forumThread', 'jobPosting', 'jobPostingNavigation', 'pageContent', 'product', 'productList', 'productNavigation', 'serp'];

        if (!in_array($extractionType, $validTypes)) {
            throw new \InvalidArgumentException("Invalid extraction type: '{$extractionType}'. Must be one of: " . implode(', ', $validTypes));
        }

        // Set attributes3 based on extraction type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'customAttributes-' . $extractionType;
        }

        // Build arguments array with dynamic extraction field
        $args = [
            'url'                     => $url,
            $extractionType           => true,  // Dynamic named parameter
            'customAttributes'        => $customAttributes,
            'customAttributesOptions' => $customAttributesOptions,
            'additionalParams'        => $additionalParams,
            'attributes'              => $attributes,
            'attributes2'             => $attributes2,
            'attributes3'             => $attributes3,
            'amount'                  => $amount,
        ];

        return $this->extract(...$args);
    }

    /**
     * Take a screenshot of a URL
     *
     * Full page screenshots are only available in JPEG format
     *
     * @param string      $url               The URL to take a screenshot of
     * @param array|null  $screenshotOptions Screenshot options:
     *                                       - format: "jpeg" (default) or "png"
     *                                       - fullPage: false (viewport only) or true (full page, JPEG only)
     * @param array|null  $viewport          Viewport settings (width, height)
     * @param array       $additionalParams  Additional parameters to pass to the API
     * @param string|null $attributes        Optional attributes to store with the cache entry
     * @param string|null $attributes2       Optional secondary attributes to store with the cache entry
     * @param string|null $attributes3       Optional tertiary attributes to store with the cache entry
     * @param int|null    $amount            Amount to pass to incrementAttempts, overrides calculated credits
     *
     * @return array The API response data
     */
    public function screenshot(
        string $url,
        ?array $screenshotOptions = null,
        ?array $viewport = null,
        array $additionalParams = [],
        ?string $attributes = null,
        ?string $attributes2 = null,
        ?string $attributes3 = null,
        ?int $amount = null
    ): array {
        // Set attributes3 to method type if not provided
        if ($attributes3 === null) {
            $attributes3 = 'screenshot';
        }

        return $this->extract(
            url: $url,
            screenshot: true,
            screenshotOptions: $screenshotOptions,
            viewport: $viewport,
            additionalParams: $additionalParams,
            attributes: $attributes,
            attributes2: $attributes2,
            attributes3: $attributes3,
            amount: $amount
        );
    }

    /**
     * Parallel version of extract(). Each job mirrors extract() parameters as an associative array.
     *
     * For duplicate URLs, we deduplicate the URL requests but replicate the results. This maintains
     * the expected 1:1 mapping of jobs to results, even though some jobs are not actually sent. This
     * saves API credits.
     *
     * @param array<int, array> $jobs Array of jobs to process
     *
     * @throws \RuntimeException If cache manager is not initialized
     *
     * @return array<int, array> Same shape as sendCachedRequest() per job
     */
    public function extractParallel(array $jobs): array
    {
        // Add class and method name to the log context
        Log::withContext([
            'class'        => __CLASS__,
            'class_method' => __METHOD__,
        ]);

        // Make sure $this->cacheManager is not null
        if ($this->cacheManager === null) {
            throw new \RuntimeException('Cache manager is not initialized');
        }

        $cm         = $this->getCacheManager();
        $clientName = $this->getClientName();
        $version    = $this->getVersion();
        $useCache   = $this->getUseCache();
        $ttl        = config("api-cache.apis.{$clientName}.cache_ttl");

        $endpoint = 'extract';
        $method   = 'POST';

        $results           = [];
        $toSend            = [];
        $cacheKeyToIndices = []; // Track which job indices share the same cache key

        // Prepare per-job data, check cache, collect misses
        foreach ($jobs as $i => $job) {
            $url = $job['url'] ?? null;
            if (!$url) {
                throw new \InvalidArgumentException('extractParallel job missing required url');
            }

            $additionalParams = $job['additionalParams'] ?? [];

            // Build request data using extract() signature for parity (filters nulls, merges additionalParams)
            $vars = [
                'url'                         => $url,
                'requestHeaders'              => $job['requestHeaders'] ?? null,
                'tags'                        => $job['tags'] ?? null,
                'ipType'                      => $job['ipType'] ?? null,
                'httpRequestMethod'           => $job['httpRequestMethod'] ?? null,
                'httpRequestBody'             => $job['httpRequestBody'] ?? null,
                'httpRequestText'             => $job['httpRequestText'] ?? null,
                'customHttpRequestHeaders'    => $job['customHttpRequestHeaders'] ?? null,
                'httpResponseBody'            => $job['httpResponseBody'] ?? null,
                'httpResponseHeaders'         => $job['httpResponseHeaders'] ?? null,
                'browserHtml'                 => $job['browserHtml'] ?? null,
                'screenshot'                  => $job['screenshot'] ?? null,
                'screenshotOptions'           => $job['screenshotOptions'] ?? null,
                'article'                     => $job['article'] ?? null,
                'articleOptions'              => $job['articleOptions'] ?? null,
                'articleList'                 => $job['articleList'] ?? null,
                'articleListOptions'          => $job['articleListOptions'] ?? null,
                'articleNavigation'           => $job['articleNavigation'] ?? null,
                'articleNavigationOptions'    => $job['articleNavigationOptions'] ?? null,
                'forumThread'                 => $job['forumThread'] ?? null,
                'forumThreadOptions'          => $job['forumThreadOptions'] ?? null,
                'jobPosting'                  => $job['jobPosting'] ?? null,
                'jobPostingOptions'           => $job['jobPostingOptions'] ?? null,
                'jobPostingNavigation'        => $job['jobPostingNavigation'] ?? null,
                'jobPostingNavigationOptions' => $job['jobPostingNavigationOptions'] ?? null,
                'pageContent'                 => $job['pageContent'] ?? null,
                'pageContentOptions'          => $job['pageContentOptions'] ?? null,
                'product'                     => $job['product'] ?? null,
                'productOptions'              => $job['productOptions'] ?? null,
                'productList'                 => $job['productList'] ?? null,
                'productListOptions'          => $job['productListOptions'] ?? null,
                'productNavigation'           => $job['productNavigation'] ?? null,
                'productNavigationOptions'    => $job['productNavigationOptions'] ?? null,
                'customAttributes'            => $job['customAttributes'] ?? null,
                'customAttributesOptions'     => $job['customAttributesOptions'] ?? null,
                'geolocation'                 => $job['geolocation'] ?? null,
                'javascript'                  => $job['javascript'] ?? null,
                'actions'                     => $job['actions'] ?? null,
                'jobId'                       => $job['jobId'] ?? null,
                'echoData'                    => $job['echoData'] ?? null,
                'viewport'                    => $job['viewport'] ?? null,
                'followRedirect'              => $job['followRedirect'] ?? null,
                'sessionContext'              => $job['sessionContext'] ?? null,
                'sessionContextParameters'    => $job['sessionContextParameters'] ?? null,
                'session'                     => $job['session'] ?? null,
                'networkCapture'              => $job['networkCapture'] ?? null,
                'device'                      => $job['device'] ?? null,
                'cookieManagement'            => $job['cookieManagement'] ?? null,
                'requestCookies'              => $job['requestCookies'] ?? null,
                'responseCookies'             => $job['responseCookies'] ?? null,
                'serp'                        => $job['serp'] ?? null,
                'serpOptions'                 => $job['serpOptions'] ?? null,
                'includeIframes'              => $job['includeIframes'] ?? null,
            ];

            $params = array_merge(
                ['url' => $url],
                $this->buildApiParams($additionalParams, [], 'FOfX\\ApiCache\\ZyteApiClient::extract', $vars)
            );

            // Credits / amount
            $amount  = $job['amount'] ?? null;
            $credits = $amount === null ? $this->calculateCredits($params) : (int) $amount;

            // Attributes
            $attributes  = $job['attributes'] ?? $url;
            $attributes2 = $job['attributes2'] ?? null;
            if ($attributes2 === null) {
                try {
                    $attributes2 = Utility\extract_registrable_domain($url);
                } catch (\Throwable $e) {
                    $attributes2 = null;
                }
            }
            $attributes3 = $job['attributes3'] ?? null;

            // Trim attributes per BaseApiClient behavior
            $trimmedAttributes  = mb_substr($attributes, 0, 255);
            $trimmedAttributes2 = $attributes2 === null ? null : mb_substr($attributes2, 0, 255);
            $trimmedAttributes3 = $attributes3 === null ? null : mb_substr($attributes3, 0, 255);

            // Cache key and pre-check
            $cacheKey = $cm->generateCacheKey($clientName, $endpoint, $params, $method, $version);

            // Track which indices share this cache key
            $cacheKeyToIndices[$cacheKey][] = $i;

            if (!$useCache) {
                Log::debug('Caching disabled for this request', [
                    'client'   => $clientName,
                    'endpoint' => $endpoint,
                    'method'   => $method,
                ]);
            } else {
                $cached = $cm->getCachedResponse($clientName, $cacheKey);
                if ($cached !== null) {
                    Log::debug('Cache used', [
                        'client'    => $clientName,
                        'endpoint'  => $endpoint,
                        'method'    => $method,
                        'cache_key' => $cacheKey,
                    ]);
                    $results[$i] = $cached;

                    continue;
                } else {
                    Log::debug('Cache not used', [
                        'client'    => $clientName,
                        'endpoint'  => $endpoint,
                        'method'    => $method,
                        'cache_key' => $cacheKey,
                    ]);
                }
            }

            // Only add to toSend if this is the first occurrence of this cache key
            if (count($cacheKeyToIndices[$cacheKey]) === 1) {
                $toSend[$i] = [
                    'params'      => $params,
                    'amount'      => $credits,
                    'attributes'  => $trimmedAttributes,
                    'attributes2' => $trimmedAttributes2,
                    'attributes3' => $trimmedAttributes3,
                    'cacheKey'    => $cacheKey,
                ];
            }
        }

        // Rate limit check for each to-be-sent job
        if (!empty($toSend)) {
            $needed    = array_sum(array_map(fn ($pp) => (int)($pp['amount']), $toSend));
            $remaining = $cm->getRemainingAttempts($clientName);
            if ($remaining < $needed) {
                $availableIn = $cm->getAvailableIn($clientName);
                Log::warning('Rate limit exceeded', [
                    'client'       => $clientName,
                    'available_in' => $availableIn,
                    'needed'       => $needed,
                    'remaining'    => $remaining,
                ]);

                throw new RateLimitException($clientName, $availableIn);
            }

            // Dispatch POST /extract calls in parallel
            $endpointUrl = $this->buildUrl($endpoint);
            $timings     = [];
            $fullUrls    = [];
            $headersUsed = [];
            $bodiesUsed  = [];
            $order       = array_keys($toSend);

            try {
                $responses = Http::pool(function ($pool) use ($toSend, $endpointUrl, &$timings, &$fullUrls, &$headersUsed, &$bodiesUsed) {
                    $out = [];
                    foreach ($toSend as $i => $job) {
                        $pending = $pool
                            ->withHeaders($this->getAuthHeaders())
                            ->withMiddleware(function (callable $handler) use (&$fullUrls, &$headersUsed, &$bodiesUsed, $i) {
                                return function (\Psr\Http\Message\RequestInterface $request, array $options) use ($handler, &$fullUrls, &$headersUsed, &$bodiesUsed, $i) {
                                    $fullUrls[$i]    = (string) $request->getUri();
                                    $headersUsed[$i] = $request->getHeaders();
                                    $bodiesUsed[$i]  = (string) $request->getBody();

                                    return $handler($request, $options);
                                };
                            })
                            ->withOptions([
                                'cookies'  => false,
                                'on_stats' => function ($stats) use (&$timings, $i) {
                                    if (method_exists($stats, 'getTransferTime')) {
                                        $timings[$i] = $stats->getTransferTime();
                                    }
                                },
                            ]);

                        $timeout = $this->getTimeout();
                        if ($timeout !== null) {
                            $pending = $pending->timeout($timeout);
                        }

                        $out[] = $pending->post($endpointUrl, $job['params']);
                    }

                    return $out;
                });

                // Throw explicitly for response errors to suppressed PHPStan errors, "Dead catch - ... is never thrown in the try block."
                foreach ($responses as $k => $response) {
                    $i         = $order[$k];
                    $queuedJob = $toSend[$i];

                    // If this is not a Response object, and if it's a throwable, throw it to be caught by outer catch blocks
                    if (!($response instanceof Response)) {
                        if ($response instanceof \Throwable) {
                            throw $response;
                        }

                        $this->logUnknownError('Unexpected response type in extractParallel', [
                            'url'       => $fullUrls[$i],
                            'method'    => $method,
                            'cache_key' => $queuedJob['cacheKey'],
                        ]);

                        continue;
                    }

                    // Must try/catch to prevent errors from bubbling up
                    try {
                        $response->throw();
                    } catch (RequestException $e) {
                        $status = $e->response->status();
                        $body   = $e->response->body();
                        $this->logHttpError($status, 'HTTP request error: ' . $e->getMessage(), [
                            'url'       => $fullUrls[$i],
                            'method'    => $method,
                            'cache_key' => $queuedJob['cacheKey'],
                        ], $body);
                    }
                }
            } catch (ConnectionException $e) {
                $this->logHttpError(
                    0,
                    'Batch Connection error: ' . $e->getMessage(),
                    [
                        'full_urls'  => $fullUrls,
                        'method'     => $method,
                        'error_type' => 'connection_error',
                        'cache_keys' => array_map(fn ($x) => $x['cacheKey'], $toSend),
                    ],
                    null
                );

                throw $e;
            } catch (RequestException $e) {
                $status = method_exists($e, 'response') ? $e->response->status() : 0;
                $body   = method_exists($e, 'response') ? $e->response->body() : null;
                $this->logHttpError(
                    $status,
                    'Batch HTTP request error: ' . $e->getMessage(),
                    [
                        'full_urls'  => $fullUrls,
                        'method'     => $method,
                        'error_type' => 'request_error',
                        'cache_keys' => array_map(fn ($x) => $x['cacheKey'], $toSend),
                    ],
                    $body
                );

                throw $e;
            } catch (\Throwable $e) {
                $this->logUnknownError('Unknown error in extractParallel', [
                    'full_urls'  => $fullUrls,
                    'method'     => $method,
                    'error_type' => 'unknown_error',
                    'cache_keys' => array_map(fn ($x) => $x['cacheKey'], $toSend),
                ]);

                throw $e;
            }

            // Handle responses: increment attempts and optionally store in cache
            foreach ($responses as $k => $response) {
                // Responses may come back in a different order than we sent them
                // Map response index back to original queued job index
                $i         = $order[$k];
                $queuedJob = $toSend[$i];

                $body         = $response->body();
                $cost         = $this->calculateCost($body);
                $responseTime = isset($timings[$i]) ? (float) $timings[$i] : 0.0;

                $apiResult = [
                    'params'  => $queuedJob['params'],
                    'request' => [
                        'base_url'    => $this->baseUrl,
                        'full_url'    => $fullUrls[$i],
                        'method'      => $method,
                        'attributes'  => $queuedJob['attributes'],
                        'attributes2' => $queuedJob['attributes2'],
                        'attributes3' => $queuedJob['attributes3'],
                        'credits'     => $queuedJob['amount'],
                        'cost'        => $cost,
                        'headers'     => $headersUsed[$i],
                        'body'        => $bodiesUsed[$i],
                    ],
                    'response'             => $response,
                    'response_status_code' => $response->status(),
                    'response_size'        => strlen($body),
                    'response_time'        => $responseTime,
                    'is_cached'            => false,
                ];

                // Rate limit increment
                $cm->incrementAttempts($clientName, $queuedJob['amount']);

                // Cache store logic + logging parity with sendCachedRequest()
                if (!$response->successful()) {
                    // Log the HTTP error with relevant details
                    $this->logHttpError(
                        $response->status(),
                        'API request failed',
                        [
                            'url'       => $apiResult['request']['full_url'],
                            'method'    => $method,
                            'cache_key' => $queuedJob['cacheKey'],
                        ],
                        $response->body()
                    );

                    // If caching is enabled, log the cache failure due to the failed API request
                    if ($useCache) {
                        Log::warning('Failed to store API response in cache', [
                            'client'           => $clientName,
                            'endpoint'         => $endpoint,
                            'version'          => $version,
                            'cache_key'        => $queuedJob['cacheKey'],
                            'status_code'      => $response->status(),
                            'response_headers' => $response->headers(),
                            'response_body'    => $response->body(),
                        ]);
                    }
                } else {
                    // Successful response path
                    // Check if Caching is Disabled
                    if (!$useCache) {
                        Log::debug('Caching disabled for this request', [
                            'client'   => $clientName,
                            'endpoint' => $endpoint,
                            'method'   => $method,
                        ]);
                    } else {
                        // Proceed with caching Logic if the response is successful and caching is enabled
                        if ($this->shouldCache($body)) {
                            $cm->storeResponse(
                                $clientName,
                                $queuedJob['cacheKey'],
                                $queuedJob['params'],
                                $apiResult,
                                $endpoint,
                                $version,
                                $ttl,
                                $queuedJob['attributes'],
                                $queuedJob['attributes2'],
                                $queuedJob['attributes3'],
                                $queuedJob['amount']
                            );
                            Log::debug('Cache stored', [
                                'client'    => $clientName,
                                'endpoint'  => $endpoint,
                                'method'    => $method,
                                'cache_key' => $queuedJob['cacheKey'],
                            ]);
                        } else {
                            $this->logCacheRejected(
                                'Response failed shouldCache() check',
                                [
                                    'url'       => $apiResult['request']['full_url'],
                                    'endpoint'  => $endpoint,
                                    'cache_key' => $queuedJob['cacheKey'],
                                ],
                                $response->body()
                            );
                            Log::debug('Cache not stored due to shouldCache() returning false', [
                                'client'    => $clientName,
                                'endpoint'  => $endpoint,
                                'method'    => $method,
                                'cache_key' => $queuedJob['cacheKey'],
                            ]);
                        }
                    }
                }

                // Store result for the primary index
                $results[$i] = $apiResult;

                // Replicate result to all duplicate indices that share the same cache key
                // This replication block executes once per unique URL. The inner loop runs
                // once for each duplicate index, writing the result to each duplicate.
                $cacheKey   = $queuedJob['cacheKey'];
                $allIndices = $cacheKeyToIndices[$cacheKey] ?? [];
                foreach ($allIndices as $duplicateIndex) {
                    if ($duplicateIndex !== $i) {
                        $results[$duplicateIndex] = $apiResult;
                    }
                }
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Convenience wrapper for browserHtml parallel: sets browserHtml=true and delegates to extractParallel().
     *
     * @param array<int, array> $jobs Array of jobs to process
     *
     * @return array<int, array> Same shape as sendCachedRequest() per job
     */
    public function extractBrowserHtmlParallel(array $jobs): array
    {
        $mapped = array_map(function ($j) {
            $j['browserHtml'] = true;
            if (!isset($j['attributes3'])) {
                $j['attributes3'] = 'browserHtml';
            }

            return $j;
        }, $jobs);

        return $this->extractParallel($mapped);
    }

    /**
     * Convenience wrapper for httpResponseBody parallel: sets httpResponseBody=true and delegates to extractParallel().
     *
     * @param array<int, array> $jobs Array of jobs to process
     *
     * @return array<int, array> Same shape as sendCachedRequest() per job
     */
    public function extractHttpResponseBodyParallel(array $jobs): array
    {
        $mapped = array_map(function ($j) {
            $j['httpResponseBody'] = true;
            if (!isset($j['attributes3'])) {
                $j['attributes3'] = 'httpResponseBody';
            }

            return $j;
        }, $jobs);

        return $this->extractParallel($mapped);
    }
}
