<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests\Unit;

use FOfX\ApiCache\CompressionService;
use PHPUnit\Framework\TestCase;

class CompressionServiceTest extends TestCase
{
    public function test_compression_disabled_by_default(): void
    {
        $service = new CompressionService();
        $this->assertFalse($service->isEnabled());
    }

    public function test_returns_original_data_when_disabled(): void
    {
        $service = new CompressionService(false);
        $data    = 'test data';

        $this->assertSame($data, $service->compress($data));
        $this->assertSame($data, $service->decompress($data));
    }

    public function test_compresses_and_decompresses_when_enabled(): void
    {
        $service = new CompressionService(true);
        $data    = 'test data';

        $compressed = $service->compress($data);
        $this->assertNotSame($data, $compressed);

        $decompressed = $service->decompress($compressed);
        $this->assertSame($data, $decompressed);
    }

    public function test_throws_on_invalid_compressed_data(): void
    {
        $service = new CompressionService(true);

        $this->expectException(\RuntimeException::class);

        // With compression enabled, trying to decompress a raw string should throw an exception
        $service->decompress('invalid data');
    }
}
