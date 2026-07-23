<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature;

use App\Providers\WorkbenchServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use SLoggerLaravel\ServiceProvider;

abstract class BaseTestCase extends TestCase
{
    use WithWorkbench;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        // the connection is required (fail fast), so tests set it explicitly
        $app['config']->set('slogger.dispatchers.queue.connection', 'sync');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
            WorkbenchServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    protected function getApp(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }
}
