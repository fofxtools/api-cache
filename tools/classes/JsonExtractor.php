<?php

declare(strict_types=1);

/**
 * DataForSEO JSON Extractor
 *
 * Parses downloaded HTML documentation files and extracts JSON samples
 */
class JsonExtractor
{
    private string $docsDir;
    private string $outputDir;
    private bool $forceExtraction;
    private array $extractedSamples = [];
    private $progressCallback;

    public function __construct(
        string $docsDir = null,
        string $outputDir = null,
        bool $forceExtraction = false
    ) {
        $this->docsDir          = $docsDir ?? __DIR__ . '/../../storage/app/dataforseo_docs';
        $this->outputDir        = $outputDir ?? __DIR__ . '/../../storage/app/dataforseo_json_samples';
        $this->forceExtraction  = $forceExtraction;
        $this->progressCallback = null;
    }

    /**
     * Extract JSON samples from all menu items
     */
    public function extractFromMenuData(array $menuData): array
    {
        $results = [
            'total_files'   => count($menuData),
            'processed'     => 0,
            'found_samples' => 0,
            'skipped'       => 0,
            'missing_files' => 0,
            'errors'        => 0,
            'files'         => [],
        ];

        $this->ensureOutputDirectoryExists();

        $current = 0;
        foreach ($menuData as $dotNotation => $url) {
            $current++;

            // Show progress
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, $current, count($menuData), $dotNotation);
            }

            $result                         = $this->extractFromFile($dotNotation);
            $results['files'][$dotNotation] = $result;

            switch ($result['status']) {
                case 'extracted':
                    $results['found_samples']++;

                    break;
                case 'skipped':
                    $results['skipped']++;

                    break;
                case 'no_sample':
                    // File processed but no JSON found
                    break;
                case 'missing_file':
                    $results['missing_files']++;

                    break;
                case 'error':
                    $results['errors']++;

                    break;
            }

            $results['processed']++;
        }

        return $results;
    }

    /**
     * Extract JSON sample from a single file
     */
    public function extractFromFile(string $dotNotation): array
    {
        $htmlFilename = $this->generateFilename($dotNotation, 'html');
        $htmlPath     = $this->docsDir . '/' . $htmlFilename;

        $jsonFilename = $this->generateFilename($dotNotation, 'json');
        $jsonPath     = $this->outputDir . '/' . $jsonFilename;

        // Skip if JSON file already exists and not forcing extraction
        if (!$this->forceExtraction && file_exists($jsonPath)) {
            return [
                'status'    => 'skipped',
                'html_file' => $htmlPath,
                'json_file' => $jsonPath,
                'message'   => 'JSON file already exists',
            ];
        }

        // Check if HTML file exists
        if (!file_exists($htmlPath)) {
            return [
                'status'    => 'missing_file',
                'html_file' => $htmlPath,
                'json_file' => null,
                'message'   => 'HTML file not found',
            ];
        }

        try {
            $htmlContent = file_get_contents($htmlPath);
            if ($htmlContent === false) {
                return [
                    'status'    => 'error',
                    'html_file' => $htmlPath,
                    'json_file' => null,
                    'message'   => 'Failed to read HTML file',
                ];
            }

            $jsonSample = $this->extractJsonFromHtml($htmlContent);

            if ($jsonSample === null) {
                return [
                    'status'    => 'no_sample',
                    'html_file' => $htmlPath,
                    'json_file' => null,
                    'message'   => 'No JSON sample found in HTML',
                ];
            }

            // Save JSON sample to file (paths already generated above)

            $bytesWritten = file_put_contents($jsonPath, $jsonSample);

            if ($bytesWritten === false) {
                return [
                    'status'    => 'error',
                    'html_file' => $htmlPath,
                    'json_file' => $jsonPath,
                    'message'   => 'Failed to write JSON file',
                ];
            }

            $this->extractedSamples[] = $jsonPath;

            return [
                'status'    => 'extracted',
                'html_file' => $htmlPath,
                'json_file' => $jsonPath,
                'size'      => $bytesWritten,
                'message'   => 'JSON sample extracted successfully',
            ];
        } catch (Exception $e) {
            return [
                'status'    => 'error',
                'html_file' => $htmlPath,
                'json_file' => null,
                'message'   => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Extract JSON from HTML content
     */
    private function extractJsonFromHtml(string $htmlContent): ?string
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Strategy 1: Look for pre tags with JSON class or data attributes
        $preElements = $xpath->query('//pre[contains(@class, "json") or contains(@data-enlighter-language, "json") or contains(@class, "tab-json")]');

        foreach ($preElements as $pre) {
            if ($pre instanceof DOMElement) {
                $jsonText = $this->extractJsonFromElement($pre);
                if ($jsonText !== null) {
                    return $jsonText;
                }
            }
        }

        // Strategy 2: Look for code blocks with JSON classes
        $codeElements = $xpath->query('//code[contains(@class, "json") or contains(@data-enlighter-language, "json") or contains(@class, "tab-json") or @class="language-json"]');

        foreach ($codeElements as $code) {
            if ($code instanceof DOMElement) {
                $jsonText = $this->extractJsonFromElement($code);
                if ($jsonText !== null) {
                    return $jsonText;
                }
            }
        }

        // Strategy 2b: Look for ALL pre/code combinations and test content
        $preCodeElements = $xpath->query('//pre/code | //pre | //code');

        foreach ($preCodeElements as $element) {
            $content = trim($element->textContent);

            // Check if content looks like JSON (starts with { or [)
            if ($this->looksLikeJson($content) && $element instanceof DOMElement) {
                $jsonText = $this->extractJsonFromElement($element);
                if ($jsonText !== null) {
                    return $jsonText;
                }
            }
        }

        // Strategy 3: Look for any pre/code that contains JSON-like content
        $allPreCode = $xpath->query('//pre | //code');

        foreach ($allPreCode as $element) {
            $content = trim($element->textContent);

            // Check if content looks like JSON (starts with { or [)
            if ($this->looksLikeJson($content)) {
                $jsonText = $this->cleanAndValidateJson($content);
                if ($jsonText !== null) {
                    return $jsonText;
                }
            }
        }

        // Strategy 4: Look for text that looks like JSON in the entire document
        $textContent = $dom->textContent;
        if (preg_match('/\{[^{}]*"version"[^{}]*\}/', $textContent, $matches)) {
            // Look for blocks that contain "version" which is common in DataForSEO responses
            $jsonText = $this->expandJsonFromMatch($textContent, $matches[0]);
            if ($jsonText !== null) {
                return $jsonText;
            }
        }

        return null;
    }

    /**
     * Extract JSON from a DOM element
     */
    protected function extractJsonFromElement(DOMElement $element): ?string
    {
        // Always try innerHTML approach first to handle HTML entities properly
        $innerHTML = '';
        foreach ($element->childNodes as $child) {
            $innerHTML .= $element->ownerDocument->saveXML($child);
        }

        if (!empty(trim($innerHTML))) {
            // Remove HTML tags from innerHTML and decode HTML entities
            $content = html_entity_decode(strip_tags($innerHTML), ENT_QUOTES | ENT_XML1, 'UTF-8');

            // Try to extract just the JSON part if it's mixed with other content
            $content = $this->extractJsonBoundary($content);
            $result  = $this->cleanAndValidateJson($content);
            if ($result !== null) {
                return $result;
            }
        }

        // Fallback: Try to find nested pre elements (like in the example)
        $nestedPre = $element->getElementsByTagName('pre');
        if ($nestedPre->length > 0) {
            $nestedElement = $nestedPre->item(0);

            // Get the innerHTML instead of textContent to avoid capturing content after </pre>
            $nestedInnerHTML = '';
            foreach ($nestedElement->childNodes as $child) {
                $nestedInnerHTML .= $nestedElement->ownerDocument->saveXML($child);
            }

            if (!empty(trim($nestedInnerHTML))) {
                // Remove HTML tags from innerHTML and decode HTML entities
                $content = html_entity_decode(strip_tags($nestedInnerHTML), ENT_QUOTES | ENT_XML1, 'UTF-8');
            } else {
                // Last resort: use textContent but clean it heavily
                $content = trim($nestedElement->textContent);
                $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
            }

            // Try to extract just the JSON part if it's mixed with other content
            $content = $this->extractJsonBoundary($content);
            $result  = $this->cleanAndValidateJson($content);
            if ($result !== null) {
                return $result;
            }
        }

        // Final fallback: use textContent but clean it heavily
        $content = trim($element->textContent);
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);

        // Try to extract just the JSON part if it's mixed with other content
        $content = $this->extractJsonBoundary($content);

        return $this->cleanAndValidateJson($content);
    }

    /**
     * Extract JSON boundary from mixed content
     */
    protected function extractJsonBoundary(string $content): string
    {
        $content = trim($content);

        // If it already looks like clean JSON, return as-is
        if ($this->looksLikeJson($content)) {
            return $content;
        }

        // Try to find JSON boundaries - look for { at start and } at end with balanced braces
        if (preg_match('/^(\s*\{.*\}\s*)$/s', $content, $matches)) {
            return $matches[1];
        }

        // More aggressive approach: find the largest valid JSON block
        $startPos = strpos($content, '{');
        if ($startPos === false) {
            return $content;
        }

        $braceCount = 0;
        $inString   = false;
        $escaped    = false;
        $jsonEnd    = -1;

        for ($i = $startPos; $i < strlen($content); $i++) {
            $char = $content[$i];

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === '"') {
                $inString = !$inString;

                continue;
            }

            if (!$inString) {
                if ($char === '{') {
                    $braceCount++;
                } elseif ($char === '}') {
                    $braceCount--;
                    if ($braceCount === 0) {
                        $jsonEnd = $i;

                        break;
                    }
                }
            }
        }

        if ($jsonEnd > $startPos) {
            return substr($content, $startPos, $jsonEnd - $startPos + 1);
        }

        return $content;
    }

    /**
     * Check if content looks like JSON
     */
    private function looksLikeJson(string $content): bool
    {
        $content = trim($content);

        // Must start with { or [
        if (!str_starts_with($content, '{') && !str_starts_with($content, '[')) {
            return false;
        }

        // Must end with } or ]
        if (!str_ends_with($content, '}') && !str_ends_with($content, ']')) {
            return false;
        }

        // Should contain quotes (JSON strings)
        if (!str_contains($content, '"')) {
            return false;
        }

        // Should be reasonably long (not just {})
        return strlen($content) > 10;
    }

    /**
     * Clean and validate JSON content
     */
    protected function cleanAndValidateJson(string $content): ?string
    {
        $content = trim($content);

        // Remove HTML entities (try different decoding approaches)
        $content = html_entity_decode($content, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Remove ALL problematic characters that can break JSON parsing
        $content = str_replace("\r", '', $content);

        // Remove any remaining control characters except line feeds and tabs
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);

        // Try multiple JSON parsing approaches
        $decoded = null;

        // Approach 1: Standard parsing
        $decoded = json_decode($content, true);

        // Approach 2: If that fails, try with UTF-8 flag
        if ($decoded === null) {
            $decoded = json_decode($content, true, 512, JSON_INVALID_UTF8_IGNORE);
        }

        // Approach 3: If still failing, try removing line feeds right after braces
        if ($decoded === null) {
            $cleanedContent = preg_replace('/(\{|\[)\s*\n\s*/', '$1', $content);
            $cleanedContent = preg_replace('/\s*\n\s*(\}|\])/', '$1', $cleanedContent);
            $decoded        = json_decode($cleanedContent, true);
        }

        // Approach 4: Very aggressive cleaning - normalize all whitespace
        if ($decoded === null) {
            $normalized = preg_replace('/\s+/', ' ', $content);
            $decoded    = json_decode($normalized, true);
        }

        // Strict validation: only proceed if we have valid JSON
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // Re-encode with pretty printing
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Expand JSON from a partial match
     */
    private function expandJsonFromMatch(string $fullText, string $match): ?string
    {
        // This is a fallback method - for now just return the cleaned match
        return $this->cleanAndValidateJson($match);
    }

    /**
     * Generate filename from dot notation identifier
     */
    private function generateFilename(string $identifier, string $extension): string
    {
        // Convert dots to dashes for hierarchy, keep underscores from original URLs
        $filename = str_replace('.', '-', $identifier);
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
        $filename = trim($filename, '_-');

        return $filename . '.' . $extension;
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
     * Set progress callback function
     */
    public function setProgressCallback(callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Get statistics about extracted samples
     */
    public function getExtractionStatistics(): array
    {
        $stats = [
            'total_samples' => count($this->extractedSamples),
            'total_size'    => 0,
            'samples'       => [],
        ];

        foreach ($this->extractedSamples as $filepath) {
            if (file_exists($filepath)) {
                $size = filesize($filepath);
                $stats['total_size'] += $size;
                $stats['samples'][] = [
                    'file'     => basename($filepath),
                    'size'     => $size,
                    'modified' => filemtime($filepath),
                ];
            }
        }

        return $stats;
    }

    /**
     * Get output directory
     */
    public function getOutputDirectory(): string
    {
        return $this->outputDir;
    }

    /**
     * Get force extraction status
     */
    public function getForceExtraction(): bool
    {
        return $this->forceExtraction;
    }

    /**
     * Set force extraction
     */
    public function setForceExtraction(bool $forceExtraction): void
    {
        $this->forceExtraction = $forceExtraction;
    }
}
