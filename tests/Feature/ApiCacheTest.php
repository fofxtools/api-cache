<?php

namespace FOfX\ApiCache\Tests\Feature;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\Support\RequestHandler;
use Illuminate\Support\Facades\DB;

class ApiCacheTest extends TestCase
{
    public function test_it_can_cache_responses(): void
    {
        $handler = new RequestHandler([
            'timeout' => 30,
            'clients' => [
                'openai' => [
                    'cache_ttl'   => 3600,
                    'rate_limits' => [
                        'window_size'  => 60,
                        'max_requests' => 60,
                    ],
                ],
            ],
        ]);

        // Make a request that should be cached
        $handler->send('openai', 'GET', 'https://api.openai.com/v1/models');

        // Check if it was cached in database
        $cached = DB::table('api_cache_responses')
            ->where('client', 'openai')
            ->where('endpoint', '/v1/models')
            ->first();

        $this->assertNotNull($cached);
        $this->assertEquals('/v1/models', $cached->endpoint);
        $this->assertEquals('https://api.openai.com', $cached->base_url);
    }
}
