<?php

declare(strict_types=1);

/**
 * Extract JSON Samples Script
 *
 * Extracts JSON samples from downloaded documentation files
 *
 * Usage: php extract_json_samples.php [options]
 * Options:
 *   --limit=N     Limit number of files to process (default: all)
 *   --verbose     Show detailed progress information
 *   --save-report Save detailed report to storage/app/extraction_report.txt
 *   --force       Force re-extraction of existing JSON files
 */

// Load required classes
require_once __DIR__ . '/../classes/JsonExtractor.php';

function parseCommandLineArgs(): array
{
    $options = [
        'limit'       => null,
        'verbose'     => false,
        'save_report' => false,
        'force'       => false,
    ];

    $args = array_slice($_SERVER['argv'], 1);

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            $options['limit'] = (int) substr($arg, 8);
        } elseif ($arg === '--verbose') {
            $options['verbose'] = true;
        } elseif ($arg === '--save-report') {
            $options['save_report'] = true;
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

function generateResultsSummary(array $results): array
{
    $noSampleCount = $results['processed'] - $results['found_samples'] - $results['skipped'] - $results['missing_files'] - $results['errors'];

    return [
        'summary' => [
            'Total files'                                               => $results['total_files'],
            'Processed'                                                 => $results['processed'],
            'JSON samples found (files processed with new extractions)' => $results['found_samples'],
            'Skipped (JSON already exists, no HTML parsing)'            => $results['skipped'],
            'No samples (HTML parsed, no valid JSON found)'             => $noSampleCount,
            'Missing files'                                             => $results['missing_files'],
            'Errors'                                                    => $results['errors'],
        ],
        'no_sample_count' => $noSampleCount,
    ];
}

function generateDetailedReport(array $results): string
{
    $summary = generateResultsSummary($results);

    $report = "DataForSEO JSON Extraction Report\n";
    $report .= 'Generated: ' . date('Y-m-d H:i:s') . "\n";
    $report .= str_repeat('=', 50) . "\n\n";

    $report .= "SUMMARY:\n";
    foreach ($summary['summary'] as $key => $value) {
        $report .= "- {$key}: {$value}\n";
    }
    $report .= "\n";

    $report .= "FILES WITH JSON SAMPLES:\n";
    $report .= str_repeat('-', 30) . "\n";
    foreach ($results['files'] as $dotNotation => $result) {
        if ($result['status'] === 'extracted') {
            $size = isset($result['size']) ? formatFileSize($result['size']) : 'unknown';
            $report .= "✓ {$dotNotation} ({$size})\n";
        }
    }

    $report .= "\nFILES WITHOUT JSON SAMPLES:\n";
    $report .= str_repeat('-', 30) . "\n";
    foreach ($results['files'] as $dotNotation => $result) {
        if ($result['status'] === 'no_sample') {
            $report .= "- {$dotNotation}\n";
        }
    }

    if ($results['missing_files'] > 0) {
        $report .= "\nMISSING HTML FILES:\n";
        $report .= str_repeat('-', 30) . "\n";
        foreach ($results['files'] as $dotNotation => $result) {
            if ($result['status'] === 'missing_file') {
                $report .= "! {$dotNotation}\n";
            }
        }
    }

    if ($results['errors'] > 0) {
        $report .= "\nERRORS:\n";
        $report .= str_repeat('-', 30) . "\n";
        foreach ($results['files'] as $dotNotation => $result) {
            if ($result['status'] === 'error') {
                $report .= "✗ {$dotNotation}: {$result['message']}\n";
            }
        }
    }

    return $report;
}

try {
    $options = parseCommandLineArgs();

    echo "DataForSEO JSON Sample Extractor\n";
    echo "================================\n\n";

    // Load menu data
    $menuFile = __DIR__ . '/../../storage/app/dataforseo_menu/dataforseo_menu.json';
    echo "Loading menu data from: {$menuFile}\n";

    $menuData = loadMenuData($menuFile);

    // Apply limit if specified
    if ($options['limit'] !== null) {
        $menuData = array_slice($menuData, 0, $options['limit'], true);
        echo "Limited to first {$options['limit']} files\n";
    }

    echo 'Found ' . count($menuData) . " files to process\n";

    // Initialize extractor
    $extractor = new JsonExtractor(null, null, $options['force']);

    echo "Settings:\n";
    echo '- Docs directory: ' . dirname($extractor->getOutputDirectory()) . "/dataforseo_docs\n";
    echo '- Output directory: ' . $extractor->getOutputDirectory() . "\n";
    echo '- Verbose mode: ' . ($options['verbose'] ? 'Yes' : 'No') . "\n";
    echo '- Force extraction: ' . ($options['force'] ? 'Yes' : 'No') . "\n\n";

    // Set progress callback
    if ($options['verbose']) {
        $extractor->setProgressCallback(function ($current, $total, $dotNotation) {
            $percentage = round(($current / $total) * 100, 1);
            echo "[{$current}/{$total}] ({$percentage}%) {$dotNotation}... ";
            flush();
        });
    } else {
        $extractor->setProgressCallback(function ($current, $total, $dotNotation) {
            if ($current % 50 === 0 || $current === $total) {
                $percentage = round(($current / $total) * 100, 1);
                echo "Progress: {$current}/{$total} ({$percentage}%)\n";
            }
        });
    }

    // Start extraction process
    echo "Starting JSON extraction process...\n";
    echo str_repeat('-', 80) . "\n";

    $startTime = microtime(true);
    $results   = $extractor->extractFromMenuData($menuData);
    $endTime   = microtime(true);

    $duration = $endTime - $startTime;

    if ($options['verbose']) {
        echo "\n";
    }

    echo str_repeat('-', 80) . "\n";
    echo "Extraction Results:\n";

    $summary = generateResultsSummary($results);
    foreach ($summary['summary'] as $key => $value) {
        echo "- {$key}: {$value}\n";
    }
    echo '- Duration: ' . round($duration, 2) . " seconds\n";

    // Show skipped files if any
    if ($results['skipped'] > 0) {
        echo "\nSkipped Files (already exist) ({$results['skipped']}):\n";
        foreach ($results['files'] as $dotNotation => $result) {
            if ($result['status'] === 'skipped') {
                echo "- {$dotNotation}\n";
            }
        }
    }

    // Show files without JSON samples
    if ($summary['no_sample_count'] > 0) {
        echo "\nFiles without JSON samples ({$summary['no_sample_count']}):\n";
        foreach ($results['files'] as $dotNotation => $result) {
            if ($result['status'] === 'no_sample') {
                echo "- {$dotNotation}\n";
            }
        }
    }

    // Show missing files if any
    if ($results['missing_files'] > 0) {
        echo "\nMissing HTML Files ({$results['missing_files']}):\n";
        foreach ($results['files'] as $dotNotation => $result) {
            if ($result['status'] === 'missing_file') {
                echo "- {$dotNotation}\n";
            }
        }
    }

    // Show errors if any
    if ($results['errors'] > 0) {
        echo "\nErrors ({$results['errors']}):\n";
        foreach ($results['files'] as $dotNotation => $result) {
            if ($result['status'] === 'error') {
                echo "- {$dotNotation}: {$result['message']}\n";
            }
        }
    }

    // Show sample of extracted files
    if ($results['found_samples'] > 0) {
        echo "\nSuccessfully Extracted JSON Samples ({$results['found_samples']}):\n";
        $count = 0;
        foreach ($results['files'] as $dotNotation => $result) {
            if ($result['status'] === 'extracted' && $count < 20) {
                $size = isset($result['size']) ? formatFileSize($result['size']) : 'unknown';
                echo "- {$dotNotation} → " . basename($result['json_file']) . " ({$size})\n";
                $count++;
            }
        }
        if ($results['found_samples'] > 20) {
            echo '... and ' . ($results['found_samples'] - 20) . " more files\n";
        }
    }

    // Get file statistics
    if ($results['found_samples'] > 0) {
        $stats = $extractor->getExtractionStatistics();
        echo "\nFile Statistics:\n";
        echo "- Total JSON files: {$stats['total_samples']}\n";
        echo '- Total size: ' . formatFileSize($stats['total_size']) . "\n";
        echo '- Average size: ' . formatFileSize((int)($stats['total_size'] / $stats['total_samples'])) . "\n";
        echo "\nFiles saved to: " . $extractor->getOutputDirectory() . "\n";
    }

    // Save detailed report if requested
    if ($options['save_report']) {
        $reportFile    = __DIR__ . '/../../storage/app/extraction_report.txt';
        $reportContent = generateDetailedReport($results);
        file_put_contents($reportFile, $reportContent);
        echo "\nDetailed report saved to: {$reportFile}\n";
    }

    echo "\nDone! JSON samples extracted successfully.\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    echo "\nUsage: php extract_json_samples.php [options]\n";
    echo "Options:\n";
    echo "  --limit=N       Limit number of files to process (default: all)\n";
    echo "  --verbose       Show detailed progress information\n";
    echo "  --save-report   Save detailed report to storage/app/extraction_report.txt\n";
    echo "  --force         Force re-extraction of existing JSON files\n";
    exit(1);
}
