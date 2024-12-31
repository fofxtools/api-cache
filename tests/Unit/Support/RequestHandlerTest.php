<?php

namespace FOfX\ApiCache\Tests\Unit\Support;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\Support\RequestHandler;
use FOfX\ApiCache\ApiClients\DemoApiClient;
use Monolog\Logger;

class RequestHandlerTest extends TestCase
{
    protected DemoApiClient $client;
    protected RequestHandler $handler;
    protected array $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'debug'   => true,
            'clients' => [
                'demo_api' => [
                    'cache_ttl'   => 3600,
                    'rate_limits' => [
                        'window_size'  => 60,
                        'max_requests' => 5,
                    ],
                ],
            ],
        ];

        $this->client  = new DemoApiClient(new Logger('api-cache'));
        $this->handler = new RequestHandler($this->client, $this->config);
    }

    public function test_it_can_be_instantiated(): void
    {
        $client  = new DemoApiClient(new Logger('api-cache'));
        $handler = new RequestHandler($client, [
            'timeout' => 30,
            'clients' => [
                'demo_api' => [
                    'cache_ttl' => 3600,
                ],
            ],
        ]);

        $this->assertInstanceOf(RequestHandler::class, $handler);
    }
}
