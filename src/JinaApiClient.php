<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;

class JinaApiClient extends BaseApiClient
{
    protected ?string $pathSuffix = null;

    /**
     * Constructor for JinaApiClient
     *
     * @param ApiCacheManager|null $cacheManager Optional manager for caching and rate limiting
     */
    public function __construct(?ApiCacheManager $cacheManager = null)
    {
        Log::debug('Initializing Jina API client');

        $clientName = 'jina';

        parent::__construct(
            $clientName,
            config("api-cache.apis.{$clientName}.base_url"),
            config("api-cache.apis.{$clientName}.api_key"),
            config("api-cache.apis.{$clientName}.version"),
            $cacheManager
        );
    }

    /**
     * Get the current path suffix
     *
     * @return string|null The current path suffix
     */
    public function getPathSuffix(): ?string
    {
        return $this->pathSuffix;
    }

    /**
     * Set the path suffix
     *
     * @param string|null $pathSuffix The path suffix to set
     *
     * @return self
     */
    public function setPathSuffix(?string $pathSuffix): self
    {
        $this->pathSuffix = $pathSuffix;

        return $this;
    }

    /**
     * Overrides BaseApiClient::buildUrl() to handle Jina's specific URL structure, e.g.:
     * - Reader: r.jina.ai
     * - Search: s.jina.ai
     * - Deepsearch: deepsearch.jina.ai/v1/chat/completions
     *
     * @param string      $endpoint   The API endpoint (deepsearch, r, s)
     * @param string|null $pathSuffix Optional path suffix to append to the URL
     *
     * @return string The complete URL
     */
    public function buildUrl(string $endpoint, ?string $pathSuffix = null): string
    {
        // Use the provided path suffix if set, otherwise use the class property
        $suffix = $pathSuffix ?? $this->pathSuffix;
        $suffix = $suffix ?? '';

        // Use $suffix as the endpoint for the parent buildUrl method
        return parent::buildUrl($suffix);
    }

    /**
     * Get the current token balance
     *
     * Makes a request to the base URL to get the current token balance
     * Parses the JSON response to extract the balance value
     *
     * @throws \RuntimeException When unable to parse the balance from the response
     *
     * @return int The current token balance
     */
    public function getTokenBalance(): int
    {
        Log::debug('Getting Jina AI token balance');

        $this->setBaseUrl('https://r.jina.ai');
        $this->setPathSuffix('');

        $result = $this->sendRequest('r', [], 'GET');
        $body   = $result['response']->body();
        $data   = json_decode($body, true);

        if (isset($data['data']['balanceLeft'])) {
            return (int) $data['data']['balanceLeft'];
        }

        $error = 'Could not parse token balance from response';
        Log::error($error, [
            'client' => $this->clientName,
            'body'   => $body,
        ]);

        throw new \RuntimeException($error);
    }

    /**
     * Read and parse content from a URL using the Reader API
     *
     * @param string      $url              The URL to read and parse
     * @param array       $additionalParams Additional parameters to include in the request
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @return array API response data
     */
    public function reader(
        string $url,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        Log::debug('Reading URL with Jina AI Reader', [
            'client' => $this->clientName,
            'url'    => $url,
        ]);

        // Set base URL for reader endpoint
        $this->setBaseUrl('https://r.jina.ai');
        $this->setPathSuffix('');

        $originalParams = ['url' => $url];

        $params = array_merge($additionalParams, $originalParams);

        // Pass the URL as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $url;
        }

        return $this->sendCachedRequest('r', $params, 'POST', $attributes, $amount);
    }

    /**
     * Search for content using the Search API
     *
     * @param string      $query            The search query
     * @param array       $additionalParams Additional parameters to include in the request
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @return array API response data
     */
    public function serp(
        string $query,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        Log::debug('Searching with Jina AI Search', [
            'client' => $this->clientName,
            'query'  => $query,
        ]);

        // Set base URL for search endpoint
        $this->setBaseUrl('https://s.jina.ai');
        $this->setPathSuffix('');

        $originalParams = ['q' => $query];

        $params = array_merge($additionalParams, $originalParams);

        // Pass the query as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $query;
        }

        return $this->sendCachedRequest('s', $params, 'POST', $attributes, $amount);
    }

    /**
     * Rerank documents based on a query
     *
     * @param string      $query            The search query
     * @param array       $documents        Array of documents to rerank
     * @param string      $model            The model to use for reranking
     * @param int|null    $topN             Number of top documents to return (defaults to count of documents)
     * @param bool        $returnDocuments  Whether to return the full documents in the response
     * @param array       $additionalParams Additional parameters to include in the request
     * @param string|null $attributes       Optional attributes to store with the cache entry
     * @param int         $amount           Amount to pass to incrementAttempts
     *
     * @return array API response data
     */
    public function rerank(
        string $query,
        array $documents,
        string $model = 'jina-reranker-v2-base-multilingual',
        ?int $topN = null,
        bool $returnDocuments = false,
        array $additionalParams = [],
        ?string $attributes = null,
        int $amount = 1
    ): array {
        $documentsCount = count($documents);
        $topN           = $topN ?? $documentsCount;
        $pathSuffix     = 'v1/rerank';

        Log::debug('Reranking documents with Jina AI', [
            'client'           => $this->clientName,
            'query'            => $query,
            'documents_count'  => $documentsCount,
            'model'            => $model,
            'top_n'            => $topN,
            'return_documents' => $returnDocuments,
        ]);

        // Set base URL for rerank endpoint
        $this->setBaseUrl('https://api.jina.ai');
        $this->setPathSuffix($pathSuffix);

        $originalParams = [
            'model'            => $model,
            'query'            => $query,
            'top_n'            => $topN,
            'documents'        => $documents,
            'return_documents' => $returnDocuments,
        ];

        $params = array_merge($additionalParams, $originalParams);

        // Pass the query as attributes if attributes is not provided
        if ($attributes === null) {
            $attributes = $query;
        }

        return $this->sendCachedRequest($pathSuffix, $params, 'POST', $attributes, $amount);
    }
}
