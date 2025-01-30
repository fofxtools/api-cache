<?php

declare(strict_types=1);

namespace FOfX\ApiCache\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function defineDatabaseMigrations(): void
    {
        // This will run our package migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
