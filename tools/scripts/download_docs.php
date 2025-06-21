<?php

declare(strict_types=1);

/**
 * Download DataForSEO Documentation Script
 *
 * Downloads documentation pages from parsed menu URLs
 *
 * Usage: php download_docs.php [options]
 * Options:
 *   --delay=N     Delay between requests in microseconds (default: 5000000 = 5.0s)
 *   --retries=N   Maximum retry attempts (default: 3)
 *   --limit=N     Limit number of URLs to download (default: all)
 *   --force       Force download even if file exists (default: false)
 *   --cleanup     Clean up files older than 7 days before starting
 */

// Load required classes
require_once __DIR__ . '/../classes/DocumentationDownloader.php';

function parseCommandLineArgs(): array
{
    $options = [
        'delay'   => 5000000,
        'retries' => 3,
        'limit'   => null,
        'cleanup' => false,
        'force'   => false,
    ];

    $args = array_slice($_SERVER['argv'], 1);

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--delay=')) {
            $options['delay'] = (int) substr($arg, 8);
        } elseif (str_starts_with($arg, '--retries=')) {
            $options['retries'] = (int) substr($arg, 10);
        } elseif (str_starts_with($arg, '--limit=')) {
            $options['limit'] = (int) substr($arg, 8);
        } elseif ($arg === '--cleanup') {
            $options['cleanup'] = true;
        } elseif ($arg === '--force') {
            $options['force'] = true;
        }
    }

    return $options;
}

function loadMenuData(string $menuFile): array
{
    if (!file_exists($menuFile)) {
        throw new RuntimeException("Menu data file not found: {$menuFile}");
    }

    $content = file_get_contents($menuFile);
    if ($content === false) {
        throw new RuntimeException("Failed to read menu data file: {$menuFile}");
    }

    $menuData = json_decode($content, true);
    if ($menuData === null) {
        throw new RuntimeException('Failed to parse JSON menu data');
    }

    return $menuData;
}

function formatFileSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow   = min($pow, count($units) - 1);

    $bytes /= (1 << (10 * $pow));

    return round($bytes, 2) . ' ' . $units[$pow];
}

try {
    $options = parseCommandLineArgs();

    echo "DataForSEO Documentation Downloader\n";
    echo "===================================\n\n";

    // Load menu data
    $menuFile = __DIR__ . '/../../storage/app/dataforseo_menu/dataforseo_menu.json';
    echo "Loading menu data from: {$menuFile}\n";

    $menuData = loadMenuData($menuFile);

    // Apply limit if specified
    if ($options['limit'] !== null) {
        $menuData = array_slice($menuData, 0, $options['limit'], true);
        echo "Limited to first {$options['limit']} URLs\n";
    }

    echo 'Found ' . count($menuData) . " URLs to download\n";

    // Initialize downloader
    $downloader = new DocumentationDownloader();
    $downloader->setDelay($options['delay']);
    $downloader->setMaxRetries($options['retries']);
    $downloader->setForceDownload($options['force']);

    // Set progress callback for real-time output
    $downloader->setProgressCallback(function ($current, $total, $dotNotation, $url, $phase, $result = null) {
        $percentage = round(($current / $total) * 100, 1);

        if ($phase === 'starting') {
            echo "[{$current}/{$total}] ({$percentage}%) {$dotNotation}... ";
            flush();
        } elseif ($phase === 'completed' && $result) {
            switch ($result['status']) {
                case 'downloaded':
                    $size = isset($result['size']) ? formatFileSize($result['size']) : 'unknown';
                    echo "✓ Downloaded ({$size})\n";

                    break;
                case 'skipped':
                    echo "- Skipped (exists)\n";

                    break;
                case 'failed':
                    echo "✗ Failed: {$result['error']}\n";

                    break;
            }
        }
    });

    echo "Settings:\n";
    echo '- Delay between requests: ' . ($options['delay'] / 1000000) . " seconds\n";
    echo "- Maximum retries: {$options['retries']}\n";
    echo '- Force download: ' . ($options['force'] ? 'Yes' : 'No') . "\n";
    echo '- Output directory: ' . $downloader->getOutputDirectory() . "\n\n";

    // Cleanup old files if requested
    if ($options['cleanup']) {
        echo "Cleaning up old files...\n";
        $deletedCount = $downloader->cleanupOldFiles(7);
        echo "Deleted {$deletedCount} old files\n\n";
    }

    // Start download process
    echo "Starting download process...\n";
    echo str_repeat('-', 80) . "\n";

    $startTime = microtime(true);
    $results   = $downloader->downloadFromMenuData($menuData);
    $endTime   = microtime(true);

    $duration = $endTime - $startTime;

    echo str_repeat('-', 80) . "\n";
    echo "Download Results:\n";
    echo "- Total URLs: {$results['total_urls']}\n";
    echo "- Downloaded: {$results['downloaded']}\n";
    echo "- Failed: {$results['failed']}\n";
    echo "- Skipped: {$results['skipped']}\n";
    echo '- Duration: ' . round($duration, 2) . " seconds\n";

    // Show failed downloads if any
    if ($results['failed'] > 0) {
        echo "\nFailed Downloads:\n";
        foreach ($results['files'] as $dotNotation => $result) {
            if ($result['status'] === 'failed') {
                echo "- {$dotNotation}: {$result['error']}\n";
            }
        }
    }

    // Get file statistics
    $stats = $downloader->getDownloadStatistics();
    if ($stats['total_files'] > 0) {
        echo "\nFile Statistics:\n";
        echo "- Total files: {$stats['total_files']}\n";
        echo '- Total size: ' . formatFileSize($stats['total_size']) . "\n";
        echo '- Average size: ' . formatFileSize((int)($stats['total_size'] / $stats['total_files'])) . "\n";
    }

    echo "\nDone! Documentation pages downloaded successfully.\n";
    echo 'Files saved to: ' . $downloader->getOutputDirectory() . "\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    echo "\nUsage: php download_docs.php [options]\n";
    echo "Options:\n";
    echo "  --delay=N     Delay between requests in microseconds (default: 5000000)\n";
    echo "  --retries=N   Maximum retry attempts (default: 3)\n";
    echo "  --limit=N     Limit number of URLs to download (default: all)\n";
    echo "  --force       Force download even if file exists (default: false)\n";
    echo "  --cleanup     Clean up files older than 7 days before starting\n";
    exit(1);
}
