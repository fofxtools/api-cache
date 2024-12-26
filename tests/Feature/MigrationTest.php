<?php

namespace FOfX\ApiCache\Tests\Feature;

use FOfX\ApiCache\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class MigrationTest extends TestCase
{
    public function test_migrations_create_expected_tables(): void
    {
        $this->assertTrue(
            Schema::hasTable('api_cache_responses'),
            'api_cache_responses table not found'
        );
        
        $this->assertTrue(
            Schema::hasTable('api_cache_rate_limits'),
            'api_cache_rate_limits table not found'
        );

        // Add this to check columns
        $columns = Schema::getColumnListing('api_cache_rate_limits');
        $this->assertContains(
            'window_request_count',
            $columns,
            'window_request_count column not found in api_cache_rate_limits table'
        );
    }
} 