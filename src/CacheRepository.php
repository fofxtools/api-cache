<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use FOfX\Helper;
use Illuminate\Support\Facades\DB;

class CacheRepository
{
    protected Connection $db;
    protected CompressionService $compression;

    public function __construct(Connection $db, CompressionService $compression)
    {
        $this->db          = $db;
        $this->compression = $compression;
    }

    /**
     * Get the table name for the client
     *
     * @param string $clientName Client name
     *
     * @throws \InvalidArgumentException When sanitization fails
     *
     * @return string Valid table name with appropriate prefix and suffix
     */
    public function getTableName(string $clientName): string
    {
        // Validate that $clientName only contains alphanumeric characters, hyphens, and underscores
        Helper\validate_identifier($clientName);

        // Replace hyphens with underscores for SQL compatibility
        $sanitized = str_replace('-', '_', $clientName);

        $prefix_string     = 'api_cache_';
        $responses_string  = '_responses';
        $compressed_string = '_compressed';

        // Check if the table name is too long
        $used_characters_count = strlen($prefix_string . $responses_string . $compressed_string);
        $max_length            = 64 - $used_characters_count;

        // If the table name is too long, truncate it
        if (strlen($sanitized) > $max_length) {
            $sanitized = substr($sanitized, 0, $max_length);
        }

        // If compression is enabled, add the compressed suffix
        if ($this->compression->isEnabled($clientName)) {
            $suffix = $compressed_string;
        } else {
            $suffix = '';
        }

        $table_name = $prefix_string . $sanitized . $responses_string . $suffix;

        // Replace multiple underscores with a single underscore
        $table_name = preg_replace('/_+/', '_', $table_name);

        // If the table name is 'api_cache_responses'
        // Or 'api_cache_responses_compressed'
        // Then throw an exception
        if ($table_name === 'api_cache_responses' || $table_name === 'api_cache_responses_compressed') {
            Log::error('Failed to sanitize', [
                'client'     => $clientName,
                'sanitized'  => $sanitized,
                'table_name' => $table_name,
            ]);

            throw new \InvalidArgumentException('Sanitization error for string: ' . $clientName);
        }

        return $table_name;
    }

    /**
     * Prepare headers for storage (headers are always an array)
     *
     * @param string      $clientName Client name
     * @param array|null  $headers    HTTP headers array
     * @param string|null $context    Context of the headers
     *
     * @throws \JsonException When JSON encoding fails
     *
     * @return string|null JSON encoded and optionally compressed headers
     */
    public function prepareHeaders(string $clientName, ?array $headers, ?string $context = null): ?string
    {
        $compressionEnabled = $this->compression->isEnabled($clientName);

        if ($context !== null) {
            $appendString = ' (' . $context . ')';
        } else {
            $appendString = '';
        }

        Log::debug('Preparing headers' . $appendString, [
            'client'              => $clientName,
            'compression_enabled' => $compressionEnabled,
        ]);

        if ($headers === null) {
            return null;
        }

        try {
            $encoded = json_encode($headers, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::error('Failed to encode headers', [
                'client'  => $clientName,
                'error'   => $e->getMessage(),
                'headers' => $headers,
            ]);

            // Re-throw after logging
            throw $e;
        }

        if ($compressionEnabled) {
            return $this->compression->compress($clientName, $encoded, 'headers');
        } else {
            return $encoded;
        }
    }

    /**
     * Retrieve headers from storage (headers are always an array)
     *
     * @param string      $clientName Client name
     * @param string|null $data       Stored HTTP headers data
     * @param string|null $context    Context of the headers
     *
     * @throws \JsonException    When JSON decoding fails
     * @throws \RuntimeException When decoded value is not an array
     *
     * @return array|null Decoded headers array
     */
    public function retrieveHeaders(string $clientName, ?string $data, ?string $context = null): ?array
    {
        $compressionEnabled = $this->compression->isEnabled($clientName);

        if ($context !== null) {
            $appendString = ' (' . $context . ')';
        } else {
            $appendString = '';
        }

        Log::debug('Retrieving headers' . $appendString, [
            'client'              => $clientName,
            'compression_enabled' => $compressionEnabled,
        ]);

        if ($data === null) {
            return null;
        }

        if ($compressionEnabled) {
            $raw = $this->compression->decompress($clientName, $data);
        } else {
            $raw = $data;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            // Since headers must be an array, we need to handle the case
            // where valid JSON decodes to null or a non-array
            if (!is_array($decoded)) {
                Log::error('Decoded headers must be an array', [
                    'type'    => gettype($decoded),
                    'decoded' => $decoded,
                    'raw'     => $raw,
                ]);

                throw new \RuntimeException('Decoded headers must be an array');
            }

            return $decoded;
        } catch (\JsonException $e) {
            Log::error('Failed to decode headers', [
                'error' => $e->getMessage(),
                'raw'   => $raw,
            ]);

            // Re-throw after logging
            throw $e;
        }
    }

    /**
     * Prepare body for storage (body is always a string)
     *
     * @param string      $clientName Client name
     * @param string|null $body       Raw body content
     * @param string|null $context    Context of the body
     *
     * @return string|null Optionally compressed body
     */
    public function prepareBody(string $clientName, ?string $body, ?string $context = null): ?string
    {
        $compressionEnabled = $this->compression->isEnabled($clientName);

        if ($context !== null) {
            $appendString = ' (' . $context . ')';
        } else {
            $appendString = '';
        }

        Log::debug('Preparing body' . $appendString, [
            'client'              => $clientName,
            'compression_enabled' => $compressionEnabled,
            'body_length'         => strlen($body ?? ''),
        ]);

        if ($body === null) {
            return null;
        }

        if ($compressionEnabled) {
            return $this->compression->compress($clientName, $body, 'body');
        } else {
            return $body;
        }
    }

    /**
     * Retrieve body from storage (body is always a string)
     *
     * @param string      $clientName Client name
     * @param string|null $data       Stored body data
     * @param string|null $context    Context of the body
     *
     * @return string|null Raw body content
     */
    public function retrieveBody(string $clientName, ?string $data, ?string $context = null): ?string
    {
        $compressionEnabled = $this->compression->isEnabled($clientName);

        if ($context !== null) {
            $appendString = ' (' . $context . ')';
        } else {
            $appendString = '';
        }

        Log::debug('Retrieving body' . $appendString, [
            'client'              => $clientName,
            'compression_enabled' => $compressionEnabled,
            'body_length'         => strlen($data ?? ''),
            'data_type'           => gettype($data),
            'data_sample'         => mb_substr($data ?? '', 0, 20),
        ]);

        if ($data === null) {
            return null;
        }

        if ($compressionEnabled) {
            return $this->compression->decompress($clientName, $data);
        } else {
            return $data;
        }
    }

    /**
     * Store the response in the cache
     *
     * Algorithm:
     * - Determine table name based on compression
     * - Calculate expires_at from ttl
     * - Validate required fields (endpoint and response_body are required)
     * - Prepare data for storage
     * - Store in database
     *
     * @param string   $clientName Client name
     * @param string   $key        Cache key
     * @param array    $metadata   Response metadata
     * @param int|null $ttl        Time to live in seconds
     *
     * @throws \InvalidArgumentException When required fields are missing
     */
    public function store(string $clientName, string $key, array $metadata, ?int $ttl = null): void
    {
        $table = $this->getTableName($clientName);

        // Single source of truth for current time
        $now = now();

        if ($ttl) {
            $expiresAt = $now->copy()->addSeconds($ttl);
        } else {
            $expiresAt = null;
        }

        // Ensure required fields exist
        if (empty($metadata['response_body'])) {
            Log::error('Missing required field for cache storage', [
                'client'            => $clientName,
                'key'               => $key,
                'has_response_body' => isset($metadata['response_body']),
            ]);

            throw new \InvalidArgumentException('Missing required field, response_body is required');
        }

        // Set defaults for optional fields
        $metadata = array_merge([
            'version'                => null,
            'endpoint'               => null,
            'base_url'               => null,
            'full_url'               => null,
            'method'                 => null,
            'attributes'             => null,
            'credits'                => null,
            'cost'                   => null,
            'request_params_summary' => null,
            'request_headers'        => null,
            'request_body'           => null,
            'response_headers'       => null,
            'response_status_code'   => null,
            'response_time'          => null,
        ], $metadata);

        // Prepare data for storage
        $preparedRequestHeaders  = $this->prepareHeaders($clientName, $metadata['request_headers'], 'request');
        $preparedRequestBody     = $this->prepareBody($clientName, $metadata['request_body'], 'request');
        $preparedResponseHeaders = $this->prepareHeaders($clientName, $metadata['response_headers'], 'response');
        $preparedResponseBody    = $this->prepareBody($clientName, $metadata['response_body'], 'response');

        // Add response size to metadata
        $metadata['response_size'] = strlen($preparedResponseBody);

        $this->db->table($table)->insert([
            'client'                 => $clientName,
            'key'                    => $key,
            'version'                => $metadata['version'],
            'endpoint'               => $metadata['endpoint'],
            'base_url'               => $metadata['base_url'],
            'full_url'               => $metadata['full_url'],
            'method'                 => $metadata['method'],
            'attributes'             => $metadata['attributes'],
            'credits'                => $metadata['credits'],
            'cost'                   => $metadata['cost'],
            'request_params_summary' => $metadata['request_params_summary'],
            'request_headers'        => $preparedRequestHeaders,
            'request_body'           => $preparedRequestBody,
            'response_headers'       => $preparedResponseHeaders,
            'response_body'          => $preparedResponseBody,
            'response_status_code'   => $metadata['response_status_code'],
            'response_size'          => $metadata['response_size'],
            'response_time'          => $metadata['response_time'],
            'expires_at'             => $expiresAt,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);

        Log::info('Stored response in cache', [
            'client'        => $clientName,
            'key'           => $key,
            'table'         => $table,
            'expires_at'    => $expiresAt,
            'response_size' => $metadata['response_size'],
        ]);
    }

    /**
     * Get the response from the cache
     *
     * Algorithm:
     * - Get from correct table
     * - Check if expired
     * - Return null if not found or expired
     * - Decompress if needed
     * - Return data
     *
     * @param string $clientName Client name
     * @param string $key        Cache key
     *
     * @return array|null Response data
     */
    public function get(string $clientName, string $key): ?array
    {
        $table = $this->getTableName($clientName);
        $now   = now();

        $data = $this->db->table($table)
            ->where('key', $key)
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->first();

        if (!$data) {
            Log::debug('Cache miss', [
                'client' => $clientName,
                'key'    => $key,
                'table'  => $table,
            ]);

            return null;
        }

        // Debug raw data before processing
        Log::debug('Raw data from database', [
            'response_headers_length'         => strlen($data->response_headers ?? ''),
            'response_headers_type'           => gettype($data->response_headers),
            'response_headers_bin2hex_sample' => $data->response_headers === null ? null : bin2hex(mb_substr($data->response_headers, 0, 20)),
            'response_body_length'            => strlen($data->response_body ?? ''),
            'response_body_type'              => gettype($data->response_body),
            'response_body_bin2hex_sample'    => $data->response_body === null ? null : bin2hex(mb_substr($data->response_body, 0, 20)),
        ]);

        Log::debug('Cache hit', [
            'client'     => $clientName,
            'key'        => $key,
            'table'      => $table,
            'expires_at' => $data->expires_at,
        ]);

        return [
            'version'                => $data->version,
            'endpoint'               => $data->endpoint,
            'base_url'               => $data->base_url,
            'full_url'               => $data->full_url,
            'method'                 => $data->method,
            'attributes'             => $data->attributes,
            'credits'                => $data->credits,
            'cost'                   => $data->cost,
            'request_params_summary' => $data->request_params_summary,
            'request_headers'        => $this->retrieveHeaders($clientName, $data->request_headers, 'request'),
            'request_body'           => $this->retrieveBody($clientName, $data->request_body, 'request'),
            'response_headers'       => $this->retrieveHeaders($clientName, $data->response_headers, 'response'),
            'response_body'          => $this->retrieveBody($clientName, $data->response_body, 'response'),
            'response_status_code'   => $data->response_status_code,
            'response_size'          => $data->response_size,
            'response_time'          => $data->response_time,
            'expires_at'             => $data->expires_at,
        ];
    }

    /**
     * Count all cached responses (active and expired) for a client
     *
     * @param string $clientName Client identifier
     *
     * @return int Total number of responses
     */
    public function countTotalResponses(string $clientName): int
    {
        $table = $this->getTableName($clientName);

        return DB::table($table)->count();
    }

    /**
     * Count only active (non-expired) cached responses for a client
     *
     * @param string $clientName Client identifier
     *
     * @return int Number of active responses
     */
    public function countActiveResponses(string $clientName): int
    {
        $table = $this->getTableName($clientName);
        $now   = now();

        return DB::table($table)
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->count();
    }

    /**
     * Count only expired cached responses for a client
     *
     * @param string $clientName Client identifier
     *
     * @return int Number of expired responses
     */
    public function countExpiredResponses(string $clientName): int
    {
        $table = $this->getTableName($clientName);
        $now   = now();

        return DB::table($table)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->count();
    }

    /**
     * Delete expired responses
     *
     * Algorithm:
     * - If client specified, delete only that client's expired responses
     * - Otherwise delete all expired responses from all clients
     *
     * @param string|null $clientName Client name
     */
    public function deleteExpired(?string $clientName = null): void
    {
        if ($clientName) {
            $clientsArray = [$clientName];
        } else {
            $clientsArray = array_keys(config('api-cache.apis'));
        }

        $now = now();

        foreach ($clientsArray as $clientElement) {
            $table   = $this->getTableName($clientElement);
            $deleted = $this->db->table($table)
                ->where('expires_at', '<=', $now)
                ->delete();

            Log::info('Deleted expired responses', [
                'client'        => $clientElement,
                'table'         => $table,
                'deleted_count' => $deleted,
            ]);
        }
    }
}
