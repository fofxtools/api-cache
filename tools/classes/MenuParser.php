<?php

declare(strict_types=1);

/**
 * DataForSEO Menu Parser
 *
 * Converts nested HTML menu structure to dot-notation hierarchy with URLs
 */
class MenuParser
{
    private array $navigationFlattened = [];
    private array $urlFlattened        = [];

    public function __construct()
    {
        // Constructor intentionally left empty
    }

    /**
     * Parse HTML menu file and return flattened structure
     */
    public function parseMenuFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Menu file not found: {$filePath}");
        }

        $htmlContent = file_get_contents($filePath);
        if ($htmlContent === false) {
            throw new RuntimeException("Failed to read menu file: {$filePath}");
        }

        return $this->parseHtmlContent($htmlContent);
    }

    /**
     * Parse HTML content and extract menu structure
     */
    public function parseHtmlContent(string $htmlContent): array
    {
        // Add proper HTML wrapper if not present
        if (!str_contains($htmlContent, '<html>')) {
            $htmlContent = "<html><body>{$htmlContent}</body></html>";
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath    = new DOMXPath($dom);
        $menuList = $xpath->query('//ul[@id="menu-menu"]');

        if ($menuList->length === 0) {
            throw new RuntimeException('Could not find menu-menu element in HTML');
        }

        $this->navigationFlattened = [];
        $this->urlFlattened        = [];
        $menuElement               = $menuList->item(0);
        if ($menuElement instanceof DOMElement) {
            $this->processMenuList($menuElement, []);
        }

        return [
            'navigation' => $this->navigationFlattened,
            'url_based'  => $this->urlFlattened,
        ];
    }

    /**
     * Recursively process menu list elements
     */
    private function processMenuList(DOMElement $ul, array $hierarchy): void
    {
        foreach ($ul->childNodes as $node) {
            if ($node->nodeType === XML_ELEMENT_NODE && $node->nodeName === 'li' && $node instanceof DOMElement) {
                $this->processMenuItem($node, $hierarchy);
            }
        }
    }

    /**
     * Process individual menu item
     */
    private function processMenuItem(DOMElement $li, array $hierarchy): void
    {
        $anchor = $li->getElementsByTagName('a')->item(0);

        if ($anchor === null) {
            return;
        }

        $text = trim($anchor->textContent);
        $href = $anchor->getAttribute('href');

        if (empty($text)) {
            return;
        }

        // Normalize text to create hierarchy key
        $normalizedText   = $this->normalizeText($text);
        $currentHierarchy = array_merge($hierarchy, [$normalizedText]);

        // Only add entries with actual URLs (not just '#')
        if (!empty($href) && $href !== '#') {
            // Navigation-based dot notation (current approach)
            $dotNotation                             = implode('.', $currentHierarchy);
            $this->navigationFlattened[$dotNotation] = $href;

            // URL-based dot notation
            $urlDotNotation = $this->generateUrlBasedDotNotation($href);
            if ($urlDotNotation !== null) {
                $this->urlFlattened[$urlDotNotation] = $href;
            }
        }

        // Process sub-menus
        $subMenus = $li->getElementsByTagName('ul');
        if ($subMenus->length > 0) {
            $subMenu = $subMenus->item(0);
            if ($subMenu instanceof DOMElement) {
                $this->processMenuList($subMenu, $currentHierarchy);
            }
        }
    }

    /**
     * Normalize text for hierarchy keys
     */
    private function normalizeText(string $text): string
    {
        // Convert to lowercase and replace spaces/special chars with underscores
        $normalized = strtolower($text);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        $normalized = trim($normalized, '_');

        return $normalized;
    }

    /**
     * Generate URL-based dot notation from URL path
     */
    private function generateUrlBasedDotNotation(string $url): ?string
    {
        // Remove leading/trailing slashes and parse the path
        $path = trim(parse_url($url, PHP_URL_PATH), '/');

        if (empty($path)) {
            return null;
        }

        // Split into segments
        $segments = explode('/', $path);

        // Remove 'v3' if it's the first segment
        if ($segments[0] === 'v3') {
            array_shift($segments);
        }

        // Filter out empty segments
        $segments = array_filter($segments, fn ($segment) => !empty($segment));

        if (empty($segments)) {
            return null;
        }

        // Join segments with dots
        return implode('.', $segments);
    }

    /**
     * Output results in different formats
     */
    public function outputResults(array $flattened, string $format = 'json'): string
    {
        switch ($format) {
            case 'json':
                return json_encode($flattened, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            case 'php':
                return var_export($flattened, true);

            case 'text':
                $output = '';
                foreach ($flattened as $key => $url) {
                    $output .= sprintf("%-60s => %s\n", $key, $url);
                }

                return $output;

            case 'csv':
                $output = "dot_notation,url\n";
                foreach ($flattened as $key => $url) {
                    $output .= sprintf("\"%s\",\"%s\"\n", $key, $url);
                }

                return $output;

            default:
                throw new InvalidArgumentException("Unsupported format: {$format}");
        }
    }

    /**
     * Get statistics about the flattened menu
     */
    public function getStatistics(array $results): array
    {
        // Handle both old format (direct array) and new format (with navigation/url_based keys)
        if (isset($results['navigation']) && isset($results['url_based'])) {
            $navigationStats = $this->calculateStats($results['navigation'], 'navigation');
            $urlStats        = $this->calculateStats($results['url_based'], 'url_based');

            return [
                'navigation'        => $navigationStats,
                'url_based'         => $urlStats,
                'total_unique_urls' => count(array_unique(array_merge(
                    array_values($results['navigation']),
                    array_values($results['url_based'])
                ))),
            ];
        } else {
            // Old format
            return $this->calculateStats($results, 'legacy');
        }
    }

    /**
     * Calculate statistics for a flattened array
     */
    private function calculateStats(array $flattened, string $type): array
    {
        $stats = [
            'type'               => $type,
            'total_entries'      => count($flattened),
            'max_depth'          => 0,
            'depth_distribution' => [],
        ];

        foreach ($flattened as $key => $url) {
            $depth              = substr_count($key, '.') + 1;
            $stats['max_depth'] = max($stats['max_depth'], $depth);

            if (!isset($stats['depth_distribution'][$depth])) {
                $stats['depth_distribution'][$depth] = 0;
            }
            $stats['depth_distribution'][$depth]++;
        }

        return $stats;
    }

    /**
     * Save results to files
     */
    public function saveResults(array $results, string $outputDir, string $baseFilename = 'dataforseo_menu'): array
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $savedFiles = [];
        //$formats = ['json', 'php', 'text', 'csv'];
        $formats = ['json'];

        // Handle both old format (direct array) and new format (with navigation/url_based keys)
        if (isset($results['navigation']) && isset($results['url_based'])) {
            // New format with both navigation and URL-based
            $datasets = [
                'navigation' => $results['navigation'],
                ''           => $results['url_based'],  // Default dataset (no suffix)
            ];
        } else {
            // Old format - treat as navigation only
            $datasets = ['navigation' => $results];
        }

        foreach ($datasets as $suffix => $flattened) {
            $filename_suffix = $suffix === '' ? '' : "_{$suffix}";

            foreach ($formats as $format) {
                $filename = "{$outputDir}/{$baseFilename}{$filename_suffix}.{$format}";
                $content  = $this->outputResults($flattened, $format);
                file_put_contents($filename, $content);
                $savedFiles["{$suffix}_{$format}"] = $filename;
            }
        }

        return $savedFiles;
    }
}
