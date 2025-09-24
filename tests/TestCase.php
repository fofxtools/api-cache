<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Illuminate\Support\Facades\Storage;

class TestCase extends Orchestra
{
    // Use constant (not instance property) for access in both static setUpBeforeClass() and instance methods
    protected const PSL_FILENAME            = 'public_suffix_list.dat';
    protected static bool $pslSetupComplete = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Check for PSL file required by domain validation tests
        $localPslPath = dirname(__DIR__) . '/local/resources/' . self::PSL_FILENAME;
        if (!file_exists($localPslPath)) {
            self::markTestSkipped("Domain validation tests (fofx/utility library) require PSL file at: $localPslPath");
        }
    }

    protected function setupPslFile(): void
    {
        $localPslPath = dirname(__DIR__) . '/local/resources/' . self::PSL_FILENAME;
        if (file_exists($localPslPath)) {
            $pslContent = file_get_contents($localPslPath);
            Storage::disk('local')->put(self::PSL_FILENAME, $pslContent);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Copy PSL file to faked storage (once per test suite)
        if (!self::$pslSetupComplete) {
            $this->setupPslFile();
            self::$pslSetupComplete = true;
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
