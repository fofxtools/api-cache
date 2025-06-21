<?php

declare(strict_types=1);

/**
 * Parse DataForSEO Menu Script
 *
 * Parses the DataForSEO documentation menu and generates dot-notation hierarchy
 *
 * Usage: php parse_menu.php
 */

// Load the MenuParser class
require_once __DIR__ . '/../classes/MenuParser.php';

try {
    $menuFile  = __DIR__ . '/../../resources/dataforseo-docs-menu.html';
    $outputDir = __DIR__ . '/../../storage/app/dataforseo_menu';

    $parser = new MenuParser();

    echo "DataForSEO Menu Parser\n";
    echo "=====================\n\n";

    echo "Parsing menu file: {$menuFile}\n";

    if (!file_exists($menuFile)) {
        echo "Error: Menu file not found: {$menuFile}\n";
        echo "Please ensure the menu.txt file exists in the storage directory.\n";
        exit(1);
    }

    $results = $parser->parseMenuFile($menuFile);

    $stats = $parser->getStatistics($results);

    // Handle new format with navigation and URL-based results
    if (isset($results['navigation']) && isset($results['url_based'])) {
        echo "Statistics:\n";
        echo "- Navigation-based entries: {$stats['navigation']['total_entries']}\n";
        echo "- URL-based entries: {$stats['url_based']['total_entries']}\n";
        echo "- Total unique URLs: {$stats['total_unique_urls']}\n";
        echo "- Navigation max depth: {$stats['navigation']['max_depth']}\n";
        echo "- URL-based max depth: {$stats['url_based']['max_depth']}\n";

        echo "\nNavigation-based depth distribution:\n";
        foreach ($stats['navigation']['depth_distribution'] as $depth => $count) {
            echo "  - Level {$depth}: {$count} entries\n";
        }

        echo "\nURL-based depth distribution:\n";
        foreach ($stats['url_based']['depth_distribution'] as $depth => $count) {
            echo "  - Level {$depth}: {$count} entries\n";
        }
    } else {
        // Legacy format
        echo "Statistics:\n";
        echo "- Total entries: {$stats['total_entries']}\n";
        echo "- Maximum depth: {$stats['max_depth']}\n";
        echo "- Depth distribution:\n";

        foreach ($stats['depth_distribution'] as $depth => $count) {
            echo "  - Level {$depth}: {$count} entries\n";
        }
    }

    echo "\n" . str_repeat('-', 80) . "\n";

    if (isset($results['navigation']) && isset($results['url_based'])) {
        echo "Sample navigation-based entries (first 10):\n";
        echo str_repeat('-', 80) . "\n";
        $sample = array_slice($results['navigation'], 0, 10, true);
        echo $parser->outputResults($sample, 'text');

        echo "\nSample URL-based entries (first 10):\n";
        echo str_repeat('-', 80) . "\n";
        $sample = array_slice($results['url_based'], 0, 10, true);
        echo $parser->outputResults($sample, 'text');
    } else {
        echo "Sample entries (first 10):\n";
        echo str_repeat('-', 80) . "\n";
        $sample = array_slice($results, 0, 10, true);
        echo $parser->outputResults($sample, 'text');
    }

    // Save results to files
    echo "\nSaving results to: {$outputDir}\n";

    $savedFiles = $parser->saveResults($results, $outputDir);

    foreach ($savedFiles as $format => $filename) {
        echo "- Saved {$format} output to: {$filename}\n";
    }

    echo "\nDone! Menu parsed successfully.\n";
    echo "You can now use download_docs.php to download the documentation pages.\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
