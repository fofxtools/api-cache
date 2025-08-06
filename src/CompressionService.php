<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;

class CompressionService
{
    /**
     * Check if compression is enabled for a client
     *
     * @param string $clientName Client identifier
     *
     * @return bool Whether compression is enabled
     */
    public function isEnabled(string $clientName): bool
    {
        return (bool) config("api-cache.apis.{$clientName}.compression_enabled");
    }

    /**
     * Compress data if compression is enabled for the client
     *
     * @param string $clientName Client identifier
     * @param string $data       Raw data to compress
     * @param string $context    Context of what's being compressed (e.g., 'headers', 'body')
     *
     * @throws \RuntimeException If compression fails
     *
     * @return string Compressed data if enabled, original data if not
     */
    public function compress(string $clientName, string $data, string $context = ''): string
    {
        if (!$this->isEnabled($clientName)) {
            Log::debug('Compression disabled for client, returning original data', [
                'client' => $clientName,
            ]);

            return $data;
        }

        // Return early if empty to avoid division by zero
        if (empty($data)) {
            return $data;
        }

        $compressed = gzcompress($data);
        if ($compressed === false) {
            Log::error('Failed to compress data', [
                'client'      => $clientName,
                'context'     => $context,
                'data_length' => strlen($data),
            ]);

            throw new \RuntimeException('Failed to compress data');
        }

        Log::debug('Data compressed successfully', [
            'client'          => $clientName,
            'context'         => $context,
            'original_size'   => strlen($data),
            'compressed_size' => strlen($compressed),
            'ratio'           => round(strlen($compressed) / strlen($data), 2),
        ]);

        return $compressed;
    }

    /**
     * Decompress data if compression is enabled for the client
     *
     * @param string $clientName Client identifier
     * @param string $data       Data to decompress
     * @param string $context    Context of what's being decompressed
     *
     * @throws \RuntimeException If decompression fails
     *
     * @return string Decompressed data if compressed, original data if not compressed
     */
    public function decompress(string $clientName, string $data, string $context = ''): string
    {
        if (!$this->isEnabled($clientName)) {
            Log::debug('Compression disabled for client, returning original data', [
                'client' => $clientName,
            ]);

            return $data;
        }

        if (empty($data)) {
            return $data;
        }

        // Suppress warning since we handle the error
        $decompressed = @gzuncompress($data);
        if ($decompressed === false) {
            Log::error('Failed to decompress data', [
                'client'      => $clientName,
                'context'     => $context,
                'data_length' => strlen($data),
            ]);

            throw new \RuntimeException('Failed to decompress data');
        }

        Log::debug('Data decompressed successfully', [
            'client'            => $clientName,
            'context'           => $context,
            'compressed_size'   => strlen($data),
            'decompressed_size' => strlen($decompressed),
        ]);

        return $decompressed;
    }

    /**
     * Force compress data regardless of client configuration
     *
     * @param string $clientName Client identifier
     * @param string $data       Raw data to compress
     * @param string $context    Context of what's being compressed (e.g., 'headers', 'body')
     *
     * @throws \RuntimeException If compression fails
     *
     * @return string Compressed data
     */
    public function forceCompress(string $clientName, string $data, string $context = ''): string
    {
        // Return early if empty to avoid division by zero
        if (empty($data)) {
            return $data;
        }

        $compressed = gzcompress($data);
        if ($compressed === false) {
            Log::error('Failed to force compress data', [
                'client'      => $clientName,
                'context'     => $context,
                'data_length' => strlen($data),
            ]);

            throw new \RuntimeException('Failed to compress data');
        }

        Log::debug('Data force compressed successfully', [
            'client'          => $clientName,
            'context'         => $context,
            'original_size'   => strlen($data),
            'compressed_size' => strlen($compressed),
            'ratio'           => round(strlen($compressed) / strlen($data), 2),
        ]);

        return $compressed;
    }

    /**
     * Force decompress data regardless of client configuration
     *
     * @param string $clientName Client identifier
     * @param string $data       Data to decompress
     * @param string $context    Context of what's being decompressed
     *
     * @throws \RuntimeException If decompression fails
     *
     * @return string Decompressed data
     */
    public function forceDecompress(string $clientName, string $data, string $context = ''): string
    {
        if (empty($data)) {
            return $data;
        }

        // Suppress warning since we handle the error
        $decompressed = @gzuncompress($data);
        if ($decompressed === false) {
            Log::error('Failed to force decompress data', [
                'client'      => $clientName,
                'context'     => $context,
                'data_length' => strlen($data),
            ]);

            throw new \RuntimeException('Failed to decompress data');
        }

        Log::debug('Data force decompressed successfully', [
            'client'            => $clientName,
            'context'           => $context,
            'compressed_size'   => strlen($data),
            'decompressed_size' => strlen($decompressed),
        ]);

        return $decompressed;
    }
}
