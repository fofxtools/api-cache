<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

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
     * @param string $data Raw data to compress
     *
     * @throws \RuntimeException If compression fails
     *
     * @return string Compressed data if enabled, original data if not
     */
    public function compress(string $data): string
    {
        if (!$this->enabled) {
            return $data;
        }

        $compressed = gzcompress($data);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress data');
        }

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
            return $data;
        }

        // Suppress warning since we handle the error
        $decompressed = @gzuncompress($data);
        if ($decompressed === false) {
            throw new \RuntimeException('Failed to decompress data');
        }

        return $decompressed;
    }
}
