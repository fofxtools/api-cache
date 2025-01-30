<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class MigrationTest extends TestCase
{
    public function test_demo_tables_are_created(): void
    {
        // Check if tables exist
        $this->assertTrue(
            Schema::hasTable('api_cache_demo_responses')
        );

        $this->assertTrue(
            Schema::hasTable('api_cache_demo_responses_compressed')
        );

        // Check if key columns exist in regular table
        $this->assertTrue(
            Schema::hasColumns('api_cache_demo_responses', [
                'id', 'key', 'client', 'endpoint', 'response_body',
            ])
        );

        // Check if key columns exist in compressed table
        $this->assertTrue(
            Schema::hasColumns('api_cache_demo_responses_compressed', [
                'id', 'key', 'client', 'endpoint', 'response_body',
            ])
        );
    }
}
