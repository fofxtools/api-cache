<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\DataForSeoSerpGoogleProcessor;
use FOfX\ApiCache\ApiCacheManager;
use FOfX\ApiCache\ApiCacheServiceProvider;
use Illuminate\Support\Facades\DB;

class DataForSeoSerpGoogleProcessorTest extends TestCase
{
    protected DataForSeoSerpGoogleProcessor $processor;
    protected ApiCacheManager $cacheManager;
    protected string $responsesTable    = 'api_cache_dataforseo_responses';
    protected string $organicItemsTable = 'dataforseo_serp_google_organic_items';
    protected string $paaItemsTable     = 'dataforseo_serp_google_organic_paa_items';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = app(ApiCacheManager::class);
        $this->processor    = new DataForSeoSerpGoogleProcessor($this->cacheManager);
    }

    /**
     * Get service providers to register for testing.
     */
    protected function getPackageProviders($app): array
    {
        return [ApiCacheServiceProvider::class];
    }

    public function test_constructor_with_cache_manager(): void
    {
        $processor = new DataForSeoSerpGoogleProcessor($this->cacheManager);
        $this->assertInstanceOf(DataForSeoSerpGoogleProcessor::class, $processor);
    }

    public function test_constructor_without_cache_manager(): void
    {
        $processor = new DataForSeoSerpGoogleProcessor();
        $this->assertInstanceOf(DataForSeoSerpGoogleProcessor::class, $processor);
    }

    public function test_set_update_if_newer_changes_value(): void
    {
        $original = $this->processor->getUpdateIfNewer();
        $this->processor->setUpdateIfNewer(!$original);
        $this->assertEquals(!$original, $this->processor->getUpdateIfNewer());
    }

    public function test_set_skip_sandbox_changes_value(): void
    {
        $original = $this->processor->getSkipSandbox();
        $this->processor->setSkipSandbox(!$original);
        $this->assertEquals(!$original, $this->processor->getSkipSandbox());
    }

    public function test_process_responses_with_no_responses(): void
    {
        $stats = $this->processor->processResponses();

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(0, $stats['organic_items']);
        $this->assertEquals(0, $stats['paa_items']);
        $this->assertEquals(0, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function test_process_responses_with_valid_organic_response(): void
    {
        // Insert test response
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-123',
                    'data' => [
                        'keyword'       => 'test keyword',
                        'se_domain'     => 'google.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                        'device'        => 'desktop',
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test keyword',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'device'        => 'desktop',
                            'items'         => [
                                [
                                    'type'                => 'organic',
                                    'rank_group'          => 1,
                                    'rank_absolute'       => 1,
                                    'domain'              => 'example.com',
                                    'title'               => 'Test Title',
                                    'description'         => 'Test Description',
                                    'url'                 => 'https://example.com/test',
                                    'is_featured_snippet' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'test-key',
            'endpoint'             => 'serp/google/organic/task_get/advanced',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(1, $stats['organic_items']);
        $this->assertEquals(0, $stats['paa_items']);
        $this->assertEquals(1, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);

        // Verify organic item was inserted
        $organicItem = DB::table($this->organicItemsTable)->first();
        $this->assertNotNull($organicItem);
        $this->assertEquals('test keyword', $organicItem->keyword);
        $this->assertEquals('example.com', $organicItem->domain);
        $this->assertEquals('Test Title', $organicItem->title);
    }

    public function test_process_responses_with_paa_items(): void
    {
        // Insert test response with PAA items
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-456',
                    'data' => [
                        'keyword'       => 'test paa keyword',
                        'se_domain'     => 'google.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test paa keyword',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'device'        => 'desktop',
                            'items'         => [
                                [
                                    'type'  => 'people_also_ask',
                                    'items' => [
                                        [
                                            'type'             => 'people_also_ask_element',
                                            'title'            => 'What is test?',
                                            'seed_question'    => 'What is test?',
                                            'xpath'            => '//*[@id="test"]',
                                            'expanded_element' => [
                                                [
                                                    'type'           => 'people_also_ask_expanded_element',
                                                    'featured_title' => 'Test Answer',
                                                    'url'            => 'https://example.com/answer',
                                                    'domain'         => 'example.com',
                                                    'title'          => 'Answer Title',
                                                    'description'    => 'Answer Description',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'test-paa-key',
            'endpoint'             => 'serp/google/organic/live/advanced',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses(100, true);

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(0, $stats['organic_items']);
        $this->assertEquals(1, $stats['paa_items']);
        $this->assertEquals(1, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);

        // Verify PAA item was inserted
        $paaItem = DB::table($this->paaItemsTable)->first();
        $this->assertNotNull($paaItem);
        $this->assertEquals('test paa keyword', $paaItem->keyword);
        $this->assertEquals('What is test?', $paaItem->title);
        $this->assertEquals('example.com', $paaItem->answer_domain);
    }

    public function test_process_responses_skips_paa_when_disabled(): void
    {
        // Insert test response with PAA items
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-789',
                    'data' => [
                        'keyword'       => 'test no paa keyword',
                        'se_domain'     => 'google.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                    ],
                    'result' => [
                        [
                            'keyword' => 'test no paa keyword',
                            'items'   => [
                                [
                                    'type'  => 'people_also_ask',
                                    'items' => [
                                        [
                                            'type'             => 'people_also_ask_element',
                                            'title'            => 'What is test?',
                                            'expanded_element' => [
                                                [
                                                    'type'           => 'people_also_ask_expanded_element',
                                                    'featured_title' => 'Test Answer',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'test-no-paa-key',
            'endpoint'             => 'serp/google/organic/task_get/advanced',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses(100, false);

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(0, $stats['organic_items']);
        $this->assertEquals(0, $stats['paa_items']); // Should be 0 since PAA processing is disabled
        $this->assertEquals(1, $stats['total_items']);
        $this->assertEquals(0, $stats['errors']);

        // Verify no PAA items were inserted
        $paaCount = DB::table($this->paaItemsTable)->count();
        $this->assertEquals(0, $paaCount);
    }

    public function test_process_responses_skips_sandbox_when_configured(): void
    {
        // Insert sandbox response
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'sandbox-key',
            'endpoint'             => 'serp/google/organic/task_get/advanced',
            'response_body'        => json_encode(['tasks' => []]),
            'response_status_code' => 200,
            'base_url'             => 'https://sandbox.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Insert non-sandbox response
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'production-key',
            'endpoint'             => 'serp/google/organic/task_get/advanced',
            'response_body'        => json_encode(['tasks' => []]),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->processor->setSkipSandbox(true);
        $stats = $this->processor->processResponses();

        // Should only process the non-sandbox response
        $this->assertEquals(1, $stats['processed_responses']);
    }

    public function test_process_responses_handles_invalid_json(): void
    {
        // Insert response with invalid JSON
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'invalid-json-key',
            'endpoint'             => 'serp/google/organic/task_get/advanced',
            'response_body'        => 'invalid json',
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(1, $stats['errors']);

        // Verify error was logged in processed_status
        $response = DB::table($this->responsesTable)->where('key', 'invalid-json-key')->first();
        $this->assertNotNull($response->processed_at);
        $processedStatus = json_decode($response->processed_status, true);
        $this->assertEquals('ERROR', $processedStatus['status']);
        $this->assertStringContainsString('Invalid JSON', $processedStatus['error']);
    }

    public function test_process_responses_handles_missing_tasks(): void
    {
        // Insert response without tasks array
        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'no-tasks-key',
            'endpoint'             => 'serp/google/organic/task_get/advanced',
            'response_body'        => json_encode(['status' => 'ok']),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(0, $stats['processed_responses']);
        $this->assertEquals(1, $stats['errors']);

        // Verify error was logged
        $response        = DB::table($this->responsesTable)->where('key', 'no-tasks-key')->first();
        $processedStatus = json_decode($response->processed_status, true);
        $this->assertEquals('ERROR', $processedStatus['status']);
        $this->assertStringContainsString('missing tasks array', $processedStatus['error']);
    }

    public function test_process_responses_applies_device_default(): void
    {
        // Insert response without device specified
        $responseData = [
            'tasks' => [
                [
                    'id'   => 'test-task-default-device',
                    'data' => [
                        'keyword'       => 'test keyword',
                        'se_domain'     => 'google.com',
                        'location_code' => 2840,
                        'language_code' => 'en',
                        // No device specified
                    ],
                    'result' => [
                        [
                            'keyword'       => 'test keyword',
                            'se_domain'     => 'google.com',
                            'location_code' => 2840,
                            'language_code' => 'en',
                            'items'         => [
                                [
                                    'type'          => 'organic',
                                    'rank_absolute' => 1,
                                    'domain'        => 'example.com',
                                    'title'         => 'Test Title',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        DB::table($this->responsesTable)->insert([
            'client'               => 'dataforseo',
            'key'                  => 'default-device-key',
            'endpoint'             => 'serp/google/organic/task_get/advanced',
            'response_body'        => json_encode($responseData),
            'response_status_code' => 200,
            'base_url'             => 'https://api.dataforseo.com',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $stats = $this->processor->processResponses();

        $this->assertEquals(1, $stats['processed_responses']);
        $this->assertEquals(1, $stats['organic_items']);

        // Verify device default was applied
        $organicItem = DB::table($this->organicItemsTable)->first();
        $this->assertEquals('desktop', $organicItem->device);
    }

    public function test_reset_processed(): void
    {
        // Insert processed responses
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'processed-key-1',
                'endpoint'             => 'serp/google/organic/task_get/advanced',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => now(),
                'processed_status'     => json_encode(['status' => 'OK']),
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'processed-key-2',
                'endpoint'             => 'serp/google/organic/live/advanced',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => now(),
                'processed_status'     => json_encode(['status' => 'OK']),
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'other-endpoint-key',
                'endpoint'             => 'other/endpoint',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'processed_at'         => now(),
                'processed_status'     => json_encode(['status' => 'OK']),
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $this->processor->resetProcessed();

        // Verify only SERP Google responses were reset
        $resetCount = DB::table($this->responsesTable)
            ->whereNull('processed_at')
            ->whereNull('processed_status')
            ->count();
        $this->assertEquals(2, $resetCount);

        // Verify other endpoint was not reset
        $otherResponse = DB::table($this->responsesTable)
            ->where('key', 'other-endpoint-key')
            ->first();
        $this->assertNotNull($otherResponse->processed_at);
    }

    public function test_clear_processed_tables(): void
    {
        // Insert test data into both tables
        DB::table($this->organicItemsTable)->insert([
            'keyword'       => 'test',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table($this->paaItemsTable)->insert([
            'keyword'       => 'test',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables();

        $this->assertEquals(1, $stats['organic_cleared']);
        $this->assertEquals(1, $stats['paa_cleared']);

        // Verify tables are empty
        $this->assertEquals(0, DB::table($this->organicItemsTable)->count());
        $this->assertEquals(0, DB::table($this->paaItemsTable)->count());
    }

    public function test_clear_processed_tables_exclude_paa(): void
    {
        // Insert test data into both tables
        DB::table($this->organicItemsTable)->insert([
            'keyword'       => 'test',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'rank_absolute' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table($this->paaItemsTable)->insert([
            'keyword'       => 'test',
            'se_domain'     => 'google.com',
            'location_code' => 2840,
            'language_code' => 'en',
            'device'        => 'desktop',
            'item_position' => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $stats = $this->processor->clearProcessedTables(false);

        $this->assertEquals(1, $stats['organic_cleared']);
        $this->assertEquals(0, $stats['paa_cleared']);

        // Verify only organic table is empty
        $this->assertEquals(0, DB::table($this->organicItemsTable)->count());
        $this->assertEquals(1, DB::table($this->paaItemsTable)->count());
    }

    public function test_process_responses_filters_by_endpoint_patterns(): void
    {
        // Insert responses with different endpoints
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'matching-task-get',
                'endpoint'             => 'serp/google/organic/task_get/advanced',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'matching-live',
                'endpoint'             => 'serp/google/organic/live/advanced',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'non-matching',
                'endpoint'             => 'other/endpoint',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponses();

        // Should only process the 2 matching endpoints
        $this->assertEquals(2, $stats['processed_responses']);

        // Verify non-matching endpoint was not processed
        $nonMatchingResponse = DB::table($this->responsesTable)
            ->where('key', 'non-matching')
            ->first();
        $this->assertNull($nonMatchingResponse->processed_at);
    }

    public function test_process_responses_skips_non_200_status_codes(): void
    {
        // Insert responses with different status codes
        DB::table($this->responsesTable)->insert([
            [
                'client'               => 'dataforseo',
                'key'                  => 'success-response',
                'endpoint'             => 'serp/google/organic/task_get/advanced',
                'response_body'        => json_encode(['tasks' => []]),
                'response_status_code' => 200,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
            [
                'client'               => 'dataforseo',
                'key'                  => 'error-response',
                'endpoint'             => 'serp/google/organic/task_get/advanced',
                'response_body'        => json_encode(['error' => 'API Error']),
                'response_status_code' => 400,
                'base_url'             => 'https://api.dataforseo.com',
                'created_at'           => now(),
                'updated_at'           => now(),
            ],
        ]);

        $stats = $this->processor->processResponses();

        // Should only process the 200 status response
        $this->assertEquals(1, $stats['processed_responses']);

        // Verify error response was not processed
        $errorResponse = DB::table($this->responsesTable)
            ->where('key', 'error-response')
            ->first();
        $this->assertNull($errorResponse->processed_at);
    }
}
