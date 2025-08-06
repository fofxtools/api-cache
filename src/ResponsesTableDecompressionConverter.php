<?php

declare(strict_types=1);

namespace FOfX\ApiCache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResponsesTableDecompressionConverter
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
     * Prepare a row for uncompressed storage
     *
     * @param object $row Original compressed row
     *
     * @return array Prepared data for uncompressed table
     */
    public function prepareUncompressedRow(object $row): array
    {
        // Convert row to array for easier manipulation
        $data = (array) $row;

        // Always decompress data when reading from compressed table
        // Compressed table should ALWAYS contain compressed data

        // Decompress headers while preserving original JSON formatting
        if ($data['request_headers'] !== null) {
            $data['request_headers'] = $this->compressionService->forceDecompress(
                $this->clientName,
                $data['request_headers'],
                'request_headers'
            );
        }

        if ($data['response_headers'] !== null) {
            $data['response_headers'] = $this->compressionService->forceDecompress(
                $this->clientName,
                $data['response_headers'],
                'response_headers'
            );
        }

        // Decompress bodies while preserving original formatting
        if ($data['request_body'] !== null) {
            $data['request_body'] = $this->compressionService->forceDecompress(
                $this->clientName,
                $data['request_body'],
                'request_body'
            );
        }

        if ($data['response_body'] !== null) {
            $data['response_body'] = $this->compressionService->forceDecompress(
                $this->clientName,
                $data['response_body'],
                'response_body'
            );

            // Update response_size to reflect uncompressed size
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
     * Convert a batch of rows from compressed to uncompressed table
     *
     * @param int $batchSize Batch size (default: 100)
     * @param int $offset    Starting offset (default: 0)
     *
     * @return array Conversion statistics: total_count, processed_count, skipped_count, error_count
     */
    public function convertBatch(int $batchSize = 100, int $offset = 0): array
    {
        $stats = [
            'total_count'     => 0,
            'processed_count' => 0,
            'skipped_count'   => 0,
            'error_count'     => 0,
        ];

        $compressedTable   = $this->cacheRepository->getTableName($this->clientName, true);
        $uncompressedTable = $this->cacheRepository->getTableName($this->clientName, false);

        Log::debug('Starting batch decompression', [
            'client'             => $this->clientName,
            'batch_size'         => $batchSize,
            'offset'             => $offset,
            'compressed_table'   => $compressedTable,
            'uncompressed_table' => $uncompressedTable,
        ]);

        $compressedRows = DB::table($compressedTable)
            ->orderBy('id')
            ->limit($batchSize)
            ->offset($offset)
            ->get();

        $stats['total_count'] = $compressedRows->count();

        if ($stats['total_count'] === 0) {
            return $stats;
        }

        try {
            // Use database transaction for data integrity
            DB::transaction(function () use ($compressedRows, $uncompressedTable, &$stats) {
                foreach ($compressedRows as $compressedRow) {
                    try {
                        // Check if row already exists in uncompressed table
                        if (!$this->overwrite) {
                            $existingRow = DB::table($uncompressedTable)
                                ->where('key', $compressedRow->key)
                                ->first();

                            if ($existingRow !== null) {
                                $stats['skipped_count']++;

                                continue;
                            }
                        }

                        // Prepare uncompressed row data
                        $uncompressedData = $this->prepareUncompressedRow($compressedRow);

                        // Insert or update in uncompressed table
                        if ($this->overwrite) {
                            DB::table($uncompressedTable)
                                ->updateOrInsert(
                                    ['key' => $compressedRow->key],
                                    $uncompressedData
                                );
                        } else {
                            DB::table($uncompressedTable)->insert($uncompressedData);
                        }

                        $stats['processed_count']++;
                    } catch (\Exception $e) {
                        Log::error('Error decompressing individual row', [
                            'client' => $this->clientName,
                            'key'    => $compressedRow->key ?? 'unknown',
                            'error'  => $e->getMessage(),
                        ]);
                        $stats['error_count']++;
                    }
                }
            });

            Log::debug('Batch decompression completed', [
                'client' => $this->clientName,
                'stats'  => $stats,
            ]);

            return $stats;
        } catch (\Exception $e) {
            Log::error('Error in batch decompression', [
                'client'     => $this->clientName,
                'batch_size' => $batchSize,
                'offset'     => $offset,
                'error'      => $e->getMessage(),
            ]);

            $stats['error_count'] = $stats['total_count']; // Mark entire batch as error

            return $stats;
        }
    }

    /**
     * Convert all rows from compressed to uncompressed table
     *
     * @return array Conversion statistics: total_count, processed_count, skipped_count, error_count
     */
    public function convertAll(): array
    {
        $totalCount = $this->getCompressedRowCount();

        $stats = [
            'total_count'     => $totalCount,
            'processed_count' => 0,
            'skipped_count'   => 0,
            'error_count'     => 0,
        ];

        Log::debug('Starting full decompression', [
            'client'      => $this->clientName,
            'total_count' => $totalCount,
            'batch_size'  => $this->batchSize,
        ]);

        for ($offset = 0; $offset < $totalCount; $offset += $this->batchSize) {
            $batchStats = $this->convertBatch($this->batchSize, $offset);

            $stats['processed_count'] += $batchStats['processed_count'];
            $stats['skipped_count'] += $batchStats['skipped_count'];
            $stats['error_count'] += $batchStats['error_count'];
        }

        Log::debug('Full decompression completed', [
            'client'      => $this->clientName,
            'final_stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * Validate a compressed field by comparing compressed and uncompressed versions
     *
     * @param string|null $compressedData   Compressed field data
     * @param string|null $uncompressedData Uncompressed field data
     * @param string      $fieldType        Field type ('headers' or 'body')
     *
     * @return bool True if field data matches after decompression
     */
    public function validateCompressedField(?string $compressedData, ?string $uncompressedData, string $fieldType): bool
    {
        // Handle null cases
        if ($compressedData === null && $uncompressedData === null) {
            return true;
        }

        if ($compressedData === null || $uncompressedData === null) {
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
     * Validate a single row by comparing compressed and uncompressed versions
     *
     * @param object $compressedRow   Compressed row data
     * @param object $uncompressedRow Uncompressed row data
     *
     * @return bool True if all fields match after decompression
     */
    public function validateRow(object $compressedRow, object $uncompressedRow): bool
    {
        try {
            // Get all table columns dynamically
            $uncompressedTable = $this->cacheRepository->getTableName($this->clientName, false);
            $tableColumns      = DB::getSchemaBuilder()->getColumnListing($uncompressedTable);

            // Fields that always get excluded
            $alwaysExclude = ['id', 'response_size']; // id is auto-increment, response_size is updated during decompression

            // Fields that need special decompression validation
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
                if ($compressedRow->$field !== $uncompressedRow->$field) {
                    Log::debug('Field mismatch during validation', [
                        'client'           => $this->clientName,
                        'field'            => $field,
                        'compressed_key'   => $compressedRow->key ?? 'unknown',
                        'uncompressed_key' => $uncompressedRow->key ?? 'unknown',
                    ]);

                    return false;
                }
            }

            // Validate special fields with decompression logic
            if (!$this->validateCompressedField($compressedRow->request_headers, $uncompressedRow->request_headers, 'headers')) {
                return false;
            }

            if (!$this->validateCompressedField($compressedRow->response_headers, $uncompressedRow->response_headers, 'headers')) {
                return false;
            }

            if (!$this->validateCompressedField($compressedRow->request_body, $uncompressedRow->request_body, 'body')) {
                return false;
            }

            if (!$this->validateCompressedField($compressedRow->response_body, $uncompressedRow->response_body, 'body')) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Error validating row', [
                'client'           => $this->clientName,
                'compressed_key'   => $compressedRow->key ?? 'unknown',
                'uncompressed_key' => $uncompressedRow->key ?? 'unknown',
                'error'            => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Validate a batch of rows by comparing compressed and uncompressed versions
     *
     * @param int $batchSize Batch size (default: 100)
     * @param int $offset    Starting offset (default: 0)
     *
     * @return array Validation statistics: validated_count, mismatch_count, error_count
     */
    public function validateBatch(int $batchSize = 100, int $offset = 0): array
    {
        $stats = [
            'validated_count' => 0,
            'mismatch_count'  => 0,
            'error_count'     => 0,
        ];

        $compressedTable   = $this->cacheRepository->getTableName($this->clientName, true);
        $uncompressedTable = $this->cacheRepository->getTableName($this->clientName, false);

        Log::debug('Starting batch validation', [
            'client'     => $this->clientName,
            'batch_size' => $batchSize,
            'offset'     => $offset,
        ]);

        $compressedRows = DB::table($compressedTable)
            ->orderBy('id')
            ->limit($batchSize)
            ->offset($offset)
            ->get();

        $uncompressedRows = DB::table($uncompressedTable)
            ->orderBy('id')
            ->limit($batchSize)
            ->offset($offset)
            ->get()
            ->keyBy('key'); // Key by 'key' field for easier lookup

        try {
            foreach ($compressedRows as $compressedRow) {
                try {
                    $uncompressedRow = $uncompressedRows->get($compressedRow->key);

                    if ($uncompressedRow === null) {
                        Log::warning('No matching uncompressed row found', [
                            'client' => $this->clientName,
                            'key'    => $compressedRow->key,
                        ]);
                        $stats['error_count']++;

                        continue;
                    }

                    if ($this->validateRow($compressedRow, $uncompressedRow)) {
                        $stats['validated_count']++;
                    } else {
                        $stats['mismatch_count']++;
                    }
                } catch (\Exception $e) {
                    Log::error('Error validating individual row', [
                        'client' => $this->clientName,
                        'key'    => $compressedRow->key ?? 'unknown',
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
        } catch (\Exception $e) {
            Log::error('Error in batch validation', [
                'client'     => $this->clientName,
                'batch_size' => $batchSize,
                'offset'     => $offset,
                'error'      => $e->getMessage(),
            ]);

            $stats['error_count'] = $batchSize; // Mark entire batch as error

            return $stats;
        }
    }

    /**
     * Validate all rows by comparing compressed and uncompressed versions
     *
     * @return array Validation statistics: validated_count, mismatch_count, error_count
     */
    public function validateAll(): array
    {
        $compressedCount   = $this->getCompressedRowCount();
        $uncompressedCount = $this->getUncompressedRowCount();
        $totalCount        = min($compressedCount, $uncompressedCount); // Use smaller count

        $stats = [
            'validated_count' => 0,
            'mismatch_count'  => 0,
            'error_count'     => 0,
        ];

        Log::debug('Starting full validation', [
            'client'      => $this->clientName,
            'total_count' => $totalCount,
            'batch_size'  => $this->batchSize,
        ]);

        for ($offset = 0; $offset < $totalCount; $offset += $this->batchSize) {
            $batchStats = $this->validateBatch($this->batchSize, $offset);

            $stats['validated_count'] += $batchStats['validated_count'];
            $stats['mismatch_count'] += $batchStats['mismatch_count'];
            $stats['error_count'] += $batchStats['error_count'];
        }

        Log::debug('Full validation completed', [
            'client'      => $this->clientName,
            'final_stats' => $stats,
        ]);

        return $stats;
    }
}
