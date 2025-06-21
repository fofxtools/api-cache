<?php

declare(strict_types=1);

/**
 * DataForSEO Documentation Downloader
 *
 * Downloads documentation pages from URLs and stores them locally
 */
class DocumentationDownloader
{
    private string $baseUrl;
    private string $outputDir;
    private int $delayMicroseconds;
    private int $maxRetries;
    private bool $forceDownload;
    private array $downloadedFiles = [];
    private $progressCallback;

    public function __construct(
        string $baseUrl = 'https://docs.dataforseo.com',
        string $outputDir = null,
        int $delayMicroseconds = 5000000, // 5 seconds
        int $maxRetries = 3,
        bool $forceDownload = false
    ) {
        $this->baseUrl           = rtrim($baseUrl, '/');
        $this->outputDir         = $outputDir ?? __DIR__ . '/../../storage/app/dataforseo_docs';
        $this->delayMicroseconds = $delayMicroseconds;
        $this->maxRetries        = $maxRetries;
        $this->forceDownload     = $forceDownload;
        $this->progressCallback  = null;
    }

    /**
     * Download all URLs from menu data
     */
    public function downloadFromMenuData(array $menuData): array
    {
        $this->ensureOutputDirectoryExists();

        $results = [
            'total_urls' => count($menuData),
            'downloaded' => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'files'      => [],
        ];

        $current = 0;
        foreach ($menuData as $dotNotation => $relativeUrl) {
            $current++;

            // Handle both relative and absolute URLs
            if (str_starts_with($relativeUrl, 'http://') || str_starts_with($relativeUrl, 'https://')) {
                $fullUrl = $relativeUrl; // Already absolute
            } else {
                $fullUrl = $this->baseUrl . $relativeUrl; // Make absolute
            }

            // Show progress before download
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, $current, count($menuData), $dotNotation, $fullUrl, 'starting');
            }

            $result = $this->downloadUrl($fullUrl, $dotNotation);

            // Show result after download
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, $current, count($menuData), $dotNotation, $fullUrl, 'completed', $result);
            }

            $results['files'][$dotNotation] = $result;

            switch ($result['status']) {
                case 'downloaded':
                    $results['downloaded']++;

                    break;
                case 'failed':
                    $results['failed']++;

                    break;
                case 'skipped':
                    $results['skipped']++;

                    break;
            }

            // Rate limiting delay (only for downloads, not skips)
            if ($this->delayMicroseconds > 0 && $result['status'] === 'downloaded') {
                usleep($this->delayMicroseconds);
            }
        }

        return $results;
    }

    /**
     * Download a single URL
     */
    public function downloadUrl(string $url, string $identifier): array
    {
        $filename = $this->generateFilename($identifier);
        $filepath = $this->outputDir . '/' . $filename;

        // Skip if file already exists and not forcing download
        if (!$this->forceDownload && file_exists($filepath)) {
            return [
                'status'  => 'skipped',
                'url'     => $url,
                'file'    => $filepath,
                'message' => 'File already exists',
            ];
        }

        $attempts  = 0;
        $lastError = null;

        while ($attempts < $this->maxRetries) {
            $attempts++;

            try {
                $content = $this->fetchUrl($url);

                if (empty($content)) {
                    $lastError = 'Empty response from URL';

                    continue;
                }

                // Save content to file
                $bytesWritten = file_put_contents($filepath, $content);

                if ($bytesWritten === false) {
                    $lastError = 'Failed to write file';

                    continue;
                }

                $this->downloadedFiles[] = $filepath;

                return [
                    'status'   => 'downloaded',
                    'url'      => $url,
                    'file'     => $filepath,
                    'size'     => $bytesWritten,
                    'attempts' => $attempts,
                ];
            } catch (Exception $e) {
                $lastError = $e->getMessage();

                // Wait before retry
                if ($attempts < $this->maxRetries) {
                    usleep($this->delayMicroseconds * $attempts);
                }
            }
        }

        return [
            'status'   => 'failed',
            'url'      => $url,
            'file'     => null,
            'error'    => $lastError,
            'attempts' => $attempts,
        ];
    }

    /**
     * Fetch URL content using cURL
     */
    private function fetchUrl(string $url): string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:68.0) Gecko/20100101 Firefox/68.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '', // Automatically handle gzip/deflate decompression
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'DNT: 1',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
            ],
        ]);

        $content  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($content === false) {
            throw new RuntimeException("cURL error: {$error}");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("HTTP error: {$httpCode}");
        }

        return $content;
    }

    /**
     * Generate filename from dot notation identifier
     */
    private function generateFilename(string $identifier): string
    {
        // Convert dots to dashes for hierarchy, keep underscores from original URLs
        $filename = str_replace('.', '-', $identifier);
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
        $filename = trim($filename, '_-');

        return $filename . '.html';
    }

    /**
     * Ensure output directory exists
     */
    private function ensureOutputDirectoryExists(): void
    {
        if (!is_dir($this->outputDir)) {
            if (!mkdir($this->outputDir, 0755, true)) {
                throw new RuntimeException("Failed to create output directory: {$this->outputDir}");
            }
        }

        if (!is_writable($this->outputDir)) {
            throw new RuntimeException("Output directory is not writable: {$this->outputDir}");
        }
    }

    /**
     * Get statistics about downloaded files
     */
    public function getDownloadStatistics(): array
    {
        $stats = [
            'total_files' => count($this->downloadedFiles),
            'total_size'  => 0,
            'files'       => [],
        ];

        foreach ($this->downloadedFiles as $filepath) {
            if (file_exists($filepath)) {
                $size = filesize($filepath);
                $stats['total_size'] += $size;
                $stats['files'][] = [
                    'file'     => basename($filepath),
                    'size'     => $size,
                    'modified' => filemtime($filepath),
                ];
            }
        }

        return $stats;
    }

    /**
     * Clean up old downloaded files
     */
    public function cleanupOldFiles(int $maxAgeDays = 7): int
    {
        $cutoffTime   = time() - ($maxAgeDays * 24 * 60 * 60);
        $deletedCount = 0;

        if (!is_dir($this->outputDir)) {
            return 0;
        }

        $files = glob($this->outputDir . '/*.html');

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }

        return $deletedCount;
    }

    /**
     * Set rate limiting delay
     */
    public function setDelay(int $microseconds): void
    {
        $this->delayMicroseconds = $microseconds;
    }

    /**
     * Set maximum retry attempts
     */
    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = $maxRetries;
    }

    /**
     * Set force download flag
     */
    public function setForceDownload(bool $forceDownload): void
    {
        $this->forceDownload = $forceDownload;
    }

    /**
     * Set progress callback function
     */
    public function setProgressCallback(callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Get output directory
     */
    public function getOutputDirectory(): string
    {
        return $this->outputDir;
    }
}
