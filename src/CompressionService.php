<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\Log;

class CompressionService
{
    /**
     * Whether compression is enabled
     */
    protected bool $enabled;

    public function __construct(bool $enabled = false)
    {
        $this->enabled = $enabled;
    }

    /**
     * Check if compression is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Compress data if compression is enabled
     *
     * @param string $data    Raw data to compress
     * @param string $context Context of what's being compressed (e.g., 'headers', 'body')
     *
     * @throws \RuntimeException If compression fails
     *
     * @return string Compressed data if enabled, original data if not
     */
    public function compress(string $data, string $context = ''): string
    {
        if (!$this->enabled) {
            Log::debug('Compression disabled, returning original data');

            return $data;
        }

        // Return early if empty to avoid division by zero
        if (empty($data)) {
            return $data;
        }

        $compressed = gzcompress($data);
        if ($compressed === false) {
            Log::error('Failed to compress data', [
                'context'     => $context,
                'data_length' => strlen($data),
            ]);

            throw new \RuntimeException('Failed to compress data');
        }

        Log::debug('Data compressed successfully', [
            'context'         => $context,
            'original_size'   => strlen($data),
            'compressed_size' => strlen($compressed),
            'ratio'           => round(strlen($compressed) / strlen($data), 2),
        ]);

        return $compressed;
    }

    /**
     * Decompress data if compression is enabled
     *
     * @param string $data Data to decompress
     *
     * @throws \RuntimeException When decompression fails
     *
     * @return string Decompressed data if compressed, original data if not
     */
    public function decompress(string $data): string
    {
        if (!$this->enabled) {
            Log::debug('Compression disabled, returning original data');

            return $data;
        }

        if (empty($data)) {
            return $data;
        }

        // Suppress warning since we handle the error
        $decompressed = @gzuncompress($data);
        if ($decompressed === false) {
            Log::error('Failed to decompress data', [
                'data_length' => strlen($data),
            ]);

            throw new \RuntimeException('Failed to decompress data');
        }

        Log::debug('Data decompressed successfully', [
            'compressed_size' => strlen($data),
            'original_size'   => strlen($decompressed),
        ]);

        return $decompressed;
    }
}
