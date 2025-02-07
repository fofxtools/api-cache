<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;

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
     * @return string Valid table name with appropriate prefix and suffix
     */
    public function getTableName(string $clientName): string
    {
        // Replace any non-alphanumeric characters (except hyphen and underscore) with underscore
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $clientName);
        // Replace hyphens with underscores for SQL compatibility
        $sanitized = str_replace('-', '_', $sanitized);

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
        if ($this->compression->isEnabled()) {
            $suffix = $compressed_string;
        } else {
            $suffix = '';
        }

        $table_name = $prefix_string . $sanitized . $responses_string . $suffix;

        // Replace multiple underscores with a single underscore
        $table_name = preg_replace('/_+/', '_', $table_name);

        return $table_name;
    }

    /**
     * Prepare headers for storage (headers are always an array)
     *
     * @param array|null $headers HTTP headers array
     *
     * @throws \JsonException When JSON encoding fails
     *
     * @return string|null JSON encoded and optionally compressed headers
     */
    public function prepareHeaders(?array $headers): ?string
    {
        if ($headers === null) {
            return null;
        }

        try {
            $encoded = json_encode($headers, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::error('Failed to encode headers', [
                'error'   => $e->getMessage(),
                'headers' => $headers,
            ]);

            // Re-throw after logging
            throw $e;
        }

        if ($this->compression->isEnabled()) {
            return $this->compression->compress($encoded, 'headers');
        } else {
            return $encoded;
        }
    }

    /**
     * Retrieve headers from storage (headers are always an array)
     *
     * @param string|null $data Stored HTTP headers data
     *
     * @throws \JsonException    When JSON decoding fails
     * @throws \RuntimeException When decoded value is not an array
     *
     * @return array|null Decoded headers array
     */
    public function retrieveHeaders(?string $data): ?array
    {
        if ($data === null) {
            return null;
        }

        if ($this->compression->isEnabled()) {
            $raw = $this->compression->decompress($data);
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
     * @param string|null $body Raw body content
     *
     * @return string|null Optionally compressed body
     */
    public function prepareBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        if ($this->compression->isEnabled()) {
            return $this->compression->compress($body, 'body');
        } else {
            return $body;
        }
    }

    /**
     * Retrieve body from storage (body is always a string)
     *
     * @param string|null $data Stored body data
     *
     * @return string|null Raw body content
     */
    public function retrieveBody(?string $data): ?string
    {
        if ($data === null) {
            return null;
        }

        if ($this->compression->isEnabled()) {
            return $this->compression->decompress($data);
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
        $table     = $this->getTableName($clientName);
        $expiresAt = $ttl ? now()->addSeconds($ttl) : null;

        // Ensure required fields exist
        if (empty($metadata['endpoint']) || empty($metadata['response_body'])) {
            Log::error('Missing required fields for cache storage', [
                'client'            => $clientName,
                'key'               => $key,
                'has_endpoint'      => isset($metadata['endpoint']),
                'has_response_body' => isset($metadata['response_body']),
            ]);

            throw new \InvalidArgumentException('Missing required fields, endpoint and response_body are required');
        }

        // Set defaults for optional fields
        $metadata = array_merge([
            'version'              => null,
            'base_url'             => null,
            'full_url'             => null,
            'method'               => null,
            'request_headers'      => null,
            'request_body'         => null,
            'response_status_code' => null,
            'response_headers'     => null,
            'response_size'        => strlen($metadata['response_body']),
            'response_time'        => null,
        ], $metadata);

        $this->db->table($table)->insert([
            'client'               => $clientName,
            'key'                  => $key,
            'version'              => $metadata['version'],
            'endpoint'             => $metadata['endpoint'],
            'base_url'             => $metadata['base_url'],
            'full_url'             => $metadata['full_url'],
            'method'               => $metadata['method'],
            'request_headers'      => $this->prepareHeaders($metadata['request_headers']),
            'request_body'         => $this->prepareBody($metadata['request_body']),
            'response_status_code' => $metadata['response_status_code'],
            'response_headers'     => $this->prepareHeaders($metadata['response_headers']),
            'response_body'        => $this->prepareBody($metadata['response_body']),
            'response_size'        => $metadata['response_size'],
            'response_time'        => $metadata['response_time'],
            'expires_at'           => $expiresAt,
            'created_at'           => now(),
            'updated_at'           => now(),
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

        $data = $this->db->table($table)
            ->where('key', $key)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
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

        Log::debug('Cache hit', [
            'client'     => $clientName,
            'key'        => $key,
            'table'      => $table,
            'expires_at' => $data->expires_at,
        ]);

        return [
            'version'              => $data->version,
            'endpoint'             => $data->endpoint,
            'base_url'             => $data->base_url,
            'full_url'             => $data->full_url,
            'method'               => $data->method,
            'request_headers'      => $this->retrieveHeaders($data->request_headers),
            'request_body'         => $this->retrieveBody($data->request_body),
            'response_status_code' => $data->response_status_code,
            'response_headers'     => $this->retrieveHeaders($data->response_headers),
            'response_body'        => $this->retrieveBody($data->response_body),
            'response_size'        => $data->response_size,
            'response_time'        => $data->response_time,
            'expires_at'           => $data->expires_at,
        ];
    }

    /**
     * Cleanup expired responses
     *
     * Algorithm:
     * - If client specified, clean only that client's tables
     * - Otherwise clean all clients from config
     *
     * @param string|null $clientName Client name
     */
    public function cleanup(?string $clientName = null): void
    {
        if ($clientName) {
            $clientsArray = [$clientName];
        } else {
            $clientsArray = array_keys(config('api-cache.apis'));
        }

        foreach ($clientsArray as $clientElement) {
            $table   = $this->getTableName($clientElement);
            $deleted = $this->db->table($table)
                ->where('expires_at', '<=', now())
                ->delete();

            Log::info('Cleaned up expired responses', [
                'client'        => $clientElement,
                'table'         => $table,
                'deleted_count' => $deleted,
            ]);
        }
    }
}
