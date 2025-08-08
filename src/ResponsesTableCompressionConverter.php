<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResponsesTableCompressionConverter
{
    private string $clientName;
    private int $batchSize            = 100;
    private bool $overwrite           = false;
    private bool $copyProcessingState = false;

    private CacheRepository $cacheRepository;
    private CompressionService $compressionService;

    /**
     * Constructor with optional dependency injection
     *
     * @param string                  $clientName         Client name
     * @param CacheRepository|null    $cacheRepository    Optional cache repository
     * @param CompressionService|null $compressionService Optional compression service
     */
    public function __construct(
        string $clientName,
        ?CacheRepository $cacheRepository = null,
        ?CompressionService $compressionService = null
    ) {
        $this->clientName = $clientName;

        // Use dependency injection or fall back to container resolution
        $this->cacheRepository    = $cacheRepository ?? app(CacheRepository::class);
        $this->compressionService = $compressionService ?? app(CompressionService::class);
    }

    /**
     * Get client name
     *
     * @return string
     */
    public function getClientName(): string
    {
        return $this->clientName;
    }

    /**
     * Set client name
     *
     * @param string $clientName
     *
     * @return void
     */
    public function setClientName(string $clientName): void
    {
        $this->clientName = $clientName;
    }

    /**
     * Get batch size
     *
     * @return int
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Set batch size
     *
     * @param int $batchSize
     *
     * @return void
     */
    public function setBatchSize(int $batchSize): void
    {
        $this->batchSize = $batchSize;
    }

    /**
     * Get overwrite flag
     *
     * @return bool
     */
    public function getOverwrite(): bool
    {
        return $this->overwrite;
    }

    /**
     * Set overwrite flag
     *
     * @param bool $overwrite
     *
     * @return void
     */
    public function setOverwrite(bool $overwrite): void
    {
        $this->overwrite = $overwrite;
    }

    /**
     * Get copy processing state flag
     *
     * @return bool
     */
    public function getCopyProcessingState(): bool
    {
        return $this->copyProcessingState;
    }

    /**
     * Set copy processing state flag
     *
     * @param bool $copyProcessingState
     *
     * @return void
     */
    public function setCopyProcessingState(bool $copyProcessingState): void
    {
        $this->copyProcessingState = $copyProcessingState;
    }

    /**
     * Get count of uncompressed rows
     *
     * @return int
     */
    public function getUncompressedRowCount(): int
    {
        $tableName = $this->cacheRepository->getTableName($this->clientName, false);

        return DB::table($tableName)->count();
    }

    /**
     * Get count of compressed rows
     *
     * @return int
     */
    public function getCompressedRowCount(): int
    {
        $tableName = $this->cacheRepository->getTableName($this->clientName, true);

        return DB::table($tableName)->count();
    }

    /**
     * Prepare a row for compressed storage
     *
     * @param object $row Original uncompressed row
     *
     * @return array Prepared data for compressed table
     */
    public function prepareCompressedRow(object $row): array
    {
        // Convert row to array for easier manipulation
        $data = (array) $row;

        // Always compress data when writing to compressed table
        // Compressed table should ALWAYS contain compressed data

        // Compress headers while preserving original JSON formatting
        if ($data['request_headers'] !== null) {
            $data['request_headers'] = $this->compressionService->forceCompress(
                $this->clientName,
                $data['request_headers'],
                'request_headers'
            );
        }

        if ($data['response_headers'] !== null) {
            $data['response_headers'] = $this->compressionService->forceCompress(
                $this->clientName,
                $data['response_headers'],
                'response_headers'
            );
        }

        // Compress bodies while preserving original formatting
        if ($data['request_body'] !== null) {
            $data['request_body'] = $this->compressionService->forceCompress(
                $this->clientName,
                $data['request_body'],
                'request_body'
            );
        }

        if ($data['response_body'] !== null) {
            $originalBody          = $data['response_body'];
            $data['response_body'] = $this->compressionService->forceCompress(
                $this->clientName,
                $originalBody,
                'response_body'
            );

            // Update response_size to reflect compressed size
            $data['response_size'] = strlen($data['response_body']);
        }

        // Reset processing state unless explicitly copying it
        if (!$this->copyProcessingState) {
            $data['processed_at']     = null;
            $data['processed_status'] = null;
        }

        return $data;
    }

    /**
     * Convert a batch of rows from uncompressed to compressed table
     *
     * @param int|null $batchSize Batch size (uses class property if null)
     * @param int      $offset    Starting offset (default: 0)
     *
     * @return array Statistics: total_count, processed_count, skipped_count, error_count
     */
    public function convertBatch(?int $batchSize = null, int $offset = 0): array
    {
        $batchSize = $batchSize ?? $this->batchSize;

        $stats = [
            'total_count'     => 0,
            'processed_count' => 0,
            'skipped_count'   => 0,
            'error_count'     => 0,
        ];

        $uncompressedTable = $this->cacheRepository->getTableName($this->clientName, false);
        $compressedTable   = $this->cacheRepository->getTableName($this->clientName, true);

        Log::debug('Starting batch conversion', [
            'client'             => $this->clientName,
            'batch_size'         => $batchSize,
            'offset'             => $offset,
            'uncompressed_table' => $uncompressedTable,
            'compressed_table'   => $compressedTable,
        ]);

        return DB::transaction(function () use ($batchSize, $offset, $uncompressedTable, $compressedTable, &$stats) {
            // Get batch of uncompressed rows
            $rows = DB::table($uncompressedTable)
                ->offset($offset)
                ->limit($batchSize)
                ->get();

            $stats['total_count'] = $rows->count();

            foreach ($rows as $row) {
                try {
                    // Check if compressed row already exists
                    if (!$this->overwrite) {
                        $exists = DB::table($compressedTable)
                            ->where('key', $row->key)
                            ->exists();

                        if ($exists) {
                            $stats['skipped_count']++;

                            continue;
                        }
                    }

                    // Prepare compressed data
                    $compressedData = $this->prepareCompressedRow($row);

                    // Insert or update compressed row
                    if ($this->overwrite) {
                        DB::table($compressedTable)->updateOrInsert(
                            ['key' => $row->key],
                            $compressedData
                        );
                    } else {
                        DB::table($compressedTable)->insert($compressedData);
                    }

                    $stats['processed_count']++;
                } catch (\Exception $e) {
                    Log::error('Error converting row', [
                        'client'  => $this->clientName,
                        'row_id'  => $row->id,
                        'row_key' => $row->key,
                        'error'   => $e->getMessage(),
                    ]);

                    $stats['error_count']++;
                }
            }

            Log::debug('Batch conversion completed', [
                'client' => $this->clientName,
                'stats'  => $stats,
            ]);

            return $stats;
        });
    }

    /**
     * Convert all rows from uncompressed to compressed table
     *
     * @return array Total statistics: total_count, processed_count, skipped_count, error_count
     */
    public function convertAll(): array
    {
        $totalStats = [
            'total_count'     => 0,
            'processed_count' => 0,
            'skipped_count'   => 0,
            'error_count'     => 0,
        ];

        $totalRows = $this->getUncompressedRowCount();
        $offset    = 0;

        Log::info('Starting full table conversion', [
            'client'     => $this->clientName,
            'total_rows' => $totalRows,
            'batch_size' => $this->batchSize,
        ]);

        while ($offset < $totalRows) {
            $batchStats = $this->convertBatch($this->batchSize, $offset);

            // Aggregate stats
            $totalStats['total_count'] += $batchStats['total_count'];
            $totalStats['processed_count'] += $batchStats['processed_count'];
            $totalStats['skipped_count'] += $batchStats['skipped_count'];
            $totalStats['error_count'] += $batchStats['error_count'];

            $offset += $this->batchSize;

            Log::debug('Batch completed', [
                'client'      => $this->clientName,
                'offset'      => $offset,
                'total_rows'  => $totalRows,
                'batch_stats' => $batchStats,
                'total_stats' => $totalStats,
            ]);
        }

        Log::info('Full table conversion completed', [
            'client'      => $this->clientName,
            'total_stats' => $totalStats,
        ]);

        return $totalStats;
    }

    /**
     * Validate a compressed field by comparing uncompressed and compressed versions
     *
     * @param string|null $uncompressedData Original uncompressed data
     * @param string|null $compressedData   Compressed data to validate
     * @param string      $fieldType        Type of field ('headers' or 'body')
     *
     * @return bool True if data matches, false otherwise
     */
    public function validateCompressedField(?string $uncompressedData, ?string $compressedData, string $fieldType): bool
    {
        // Handle null cases
        if ($uncompressedData === null && $compressedData === null) {
            return true;
        }

        if ($uncompressedData === null || $compressedData === null) {
            Log::warning('Null mismatch in field validation', [
                'client'            => $this->clientName,
                'field_type'        => $fieldType,
                'uncompressed_null' => $uncompressedData === null,
                'compressed_null'   => $compressedData === null,
            ]);

            return false;
        }

        try {
            // Compressed table always contains compressed data, so always decompress
            $decompressedData = $this->compressionService->forceDecompress(
                $this->clientName,
                $compressedData,
                $fieldType
            );

            return $uncompressedData === $decompressedData;
        } catch (\Exception $e) {
            Log::error('Error validating field', [
                'client'     => $this->clientName,
                'field_type' => $fieldType,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Validate compressed row data against uncompressed original
     *
     * @param object $uncompressedRow Original uncompressed row
     * @param object $compressedRow   Compressed row to validate
     *
     * @return bool True if data matches, false otherwise
     */
    public function validateRow(object $uncompressedRow, object $compressedRow): bool
    {
        try {
            // Get all table columns dynamically
            $uncompressedTable = $this->cacheRepository->getTableName($this->clientName, false);
            $tableColumns      = DB::getSchemaBuilder()->getColumnListing($uncompressedTable);

            // Fields that always get excluded
            $alwaysExclude = ['id', 'response_size']; // id is auto-increment, response_size is updated during compression

            // Fields that need special compression validation
            $specialFields = ['request_headers', 'request_body', 'response_headers', 'response_body'];

            // Fields that are conditionally excluded based on copyProcessingState
            $conditionalExclude = [];
            if (!$this->copyProcessingState) {
                $conditionalExclude[] = 'processed_at';
                $conditionalExclude[] = 'processed_status';
            }

            $excludeFields = array_merge($alwaysExclude, $specialFields, $conditionalExclude);
            $regularFields = array_diff($tableColumns, $excludeFields);

            // Validate regular fields for exact matches
            foreach ($regularFields as $field) {
                if ($uncompressedRow->$field !== $compressedRow->$field) {
                    Log::debug('Field mismatch during validation', [
                        'client'           => $this->clientName,
                        'field'            => $field,
                        'uncompressed_key' => $uncompressedRow->key ?? 'unknown',
                        'compressed_key'   => $compressedRow->key ?? 'unknown',
                    ]);

                    return false;
                }
            }

            // Validate special fields with compression logic
            if (!$this->validateCompressedField($uncompressedRow->request_headers, $compressedRow->request_headers, 'headers')) {
                return false;
            }

            if (!$this->validateCompressedField($uncompressedRow->response_headers, $compressedRow->response_headers, 'headers')) {
                return false;
            }

            if (!$this->validateCompressedField($uncompressedRow->request_body, $compressedRow->request_body, 'body')) {
                return false;
            }

            if (!$this->validateCompressedField($uncompressedRow->response_body, $compressedRow->response_body, 'body')) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Error validating row', [
                'client'           => $this->clientName,
                'uncompressed_key' => $uncompressedRow->key ?? 'unknown',
                'compressed_key'   => $compressedRow->key ?? 'unknown',
                'error'            => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Validate a batch of compressed rows against uncompressed originals
     *
     * @param int|null $batchSize Batch size (uses class property if null)
     * @param int      $offset    Starting offset (default: 0)
     *
     * @return array Validation statistics: validated_count, mismatch_count, error_count
     */
    public function validateBatch(?int $batchSize = null, int $offset = 0): array
    {
        $batchSize = $batchSize ?? $this->batchSize;

        $stats = [
            'validated_count' => 0,
            'mismatch_count'  => 0,
            'error_count'     => 0,
        ];

        $uncompressedTable = $this->cacheRepository->getTableName($this->clientName, false);
        $compressedTable   = $this->cacheRepository->getTableName($this->clientName, true);

        Log::debug('Starting batch validation', [
            'client'     => $this->clientName,
            'batch_size' => $batchSize,
            'offset'     => $offset,
        ]);

        // Get batch of compressed rows
        $compressedRows = DB::table($compressedTable)
            ->offset($offset)
            ->limit($batchSize)
            ->get()
            ->keyBy('key');

        if ($compressedRows->isEmpty()) {
            return $stats;
        }

        // Get corresponding uncompressed rows
        $uncompressedRows = DB::table($uncompressedTable)
            ->whereIn('key', $compressedRows->keys())
            ->get()
            ->keyBy('key');

        foreach ($compressedRows as $key => $compressedRow) {
            try {
                $uncompressedRow = $uncompressedRows->get($key);

                if (!$uncompressedRow) {
                    Log::warning('Compressed row has no uncompressed counterpart', [
                        'client' => $this->clientName,
                        'key'    => $key,
                    ]);
                    $stats['error_count']++;

                    continue;
                }

                $isValid = $this->validateRow($uncompressedRow, $compressedRow);

                if ($isValid) {
                    $stats['validated_count']++;
                } else {
                    $stats['mismatch_count']++;
                }
            } catch (\Exception $e) {
                Log::error('Error validating row', [
                    'client' => $this->clientName,
                    'key'    => $key,
                    'error'  => $e->getMessage(),
                ]);

                $stats['error_count']++;
            }
        }

        Log::debug('Batch validation completed', [
            'client' => $this->clientName,
            'stats'  => $stats,
        ]);

        return $stats;
    }

    /**
     * Validate all compressed rows against uncompressed originals
     *
     * @return array Total validation statistics: validated_count, mismatch_count, error_count
     */
    public function validateAll(): array
    {
        $totalStats = [
            'validated_count' => 0,
            'mismatch_count'  => 0,
            'error_count'     => 0,
        ];

        $totalRows = $this->getCompressedRowCount();
        $offset    = 0;

        Log::info('Starting full table validation', [
            'client'     => $this->clientName,
            'total_rows' => $totalRows,
            'batch_size' => $this->batchSize,
        ]);

        while ($offset < $totalRows) {
            $batchStats = $this->validateBatch($this->batchSize, $offset);

            // Aggregate stats
            $totalStats['validated_count'] += $batchStats['validated_count'];
            $totalStats['mismatch_count'] += $batchStats['mismatch_count'];
            $totalStats['error_count'] += $batchStats['error_count'];

            $offset += $this->batchSize;

            Log::debug('Validation batch completed', [
                'client'      => $this->clientName,
                'offset'      => $offset,
                'total_rows'  => $totalRows,
                'batch_stats' => $batchStats,
                'total_stats' => $totalStats,
            ]);
        }

        Log::info('Full table validation completed', [
            'client'      => $this->clientName,
            'total_stats' => $totalStats,
        ]);

        return $totalStats;
    }
}
