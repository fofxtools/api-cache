<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    private static bool $pslSetupComplete = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Check for PSL file required by domain validation tests
        $localPslPath = dirname(__DIR__) . '/local/resources/public_suffix_list.dat';
        if (!file_exists($localPslPath)) {
            self::markTestSkipped("Domain validation tests (fofx/utility library) require PSL file at: $localPslPath");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Copy PSL file for domain validation in tests (once per test suite)
        if (!self::$pslSetupComplete) {
            $localPslPath = dirname(__DIR__) . '/local/resources/public_suffix_list.dat';
            if (file_exists($localPslPath)) {
                $testPslPath = storage_path('app/public_suffix_list.dat');
                if (!file_exists(dirname($testPslPath))) {
                    mkdir(dirname($testPslPath), 0755, true);
                }
                copy($localPslPath, $testPslPath);
            }
            self::$pslSetupComplete = true;
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
