<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\CompressionService;
use FOfX\ApiCache\Tests\TestCase;

class CompressionServiceTest extends TestCase
{
    private CompressionService $service;
    private string $clientName = 'test-client';

    protected function setUp(): void
    {
        parent::setUp();

        // Set up default config
        $this->app['config']->set("api-cache.apis.{$this->clientName}.compression_enabled", false);

        $this->service = new CompressionService();
    }

    public function test_isEnabled_returns_false_by_default(): void
    {
        // setUp() should always reset the config to false before each test
        $this->assertFalse($this->service->isEnabled($this->clientName));
    }

    public function test_compress_returns_original_when_disabled(): void
    {
        $data = 'test data';
        $this->assertEquals($data, $this->service->compress($this->clientName, $data));
    }

    public function test_compress_modifies_data_when_enabled(): void
    {
        $this->app['config']->set("api-cache.apis.{$this->clientName}.compression_enabled", true);

        $data       = 'test data';
        $compressed = $this->service->compress($this->clientName, $data);

        $this->assertNotEquals($data, $compressed);
        $this->assertEquals($data, $this->service->decompress($this->clientName, $compressed));
    }

    /**
     * Test that empty data is handled correctly when compression is enabled
     */
    public function test_compress_handles_empty_data_when_enabled(): void
    {
        $this->app['config']->set("api-cache.apis.{$this->clientName}.compression_enabled", true);

        $this->assertEquals('', $this->service->compress($this->clientName, ''));
    }

    /**
     * Test that empty data is handled correctly when compression is disabled
     */
    public function test_compress_handles_empty_data_when_disabled(): void
    {
        $this->assertEquals('', $this->service->compress($this->clientName, ''));
    }

    public function test_decompress_returns_original_when_disabled(): void
    {
        $data = 'test data';
        $this->assertEquals($data, $this->service->decompress($this->clientName, $data));
    }

    public function test_decompress_restores_compressed_data(): void
    {
        $this->app['config']->set("api-cache.apis.{$this->clientName}.compression_enabled", true);

        $data         = 'test data';
        $compressed   = $this->service->compress($this->clientName, $data);
        $decompressed = $this->service->decompress($this->clientName, $compressed);

        $this->assertEquals($data, $decompressed);
    }

    public function test_decompress_throws_on_invalid_data(): void
    {
        $this->app['config']->set("api-cache.apis.{$this->clientName}.compression_enabled", true);

        $this->expectException(\RuntimeException::class);
        $this->service->decompress($this->clientName, 'invalid compressed data');
    }
}
