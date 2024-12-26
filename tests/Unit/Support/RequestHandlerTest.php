<?php

namespace FOfX\ApiCache\Tests\Unit\Support;

use FOfX\ApiCache\Tests\TestCase;
use FOfX\ApiCache\Support\RequestHandler;

class RequestHandlerTest extends TestCase
{
    public function test_it_can_be_instantiated(): void
    {
        $handler = new RequestHandler([
            'timeout' => 30,
        ]);

        $this->assertInstanceOf(RequestHandler::class, $handler);
    }
}
