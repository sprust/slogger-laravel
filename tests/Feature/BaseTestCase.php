<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature;

use Illuminate\Contracts\Container\BindingResolutionException;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use SLoggerLaravel\Dispatcher\Items\Memory\MemoryDispatcher;
use SLoggerLaravel\Processor;
use SLoggerLaravel\ServiceProvider;
use SLoggerLaravel\Watchers\WatcherInterface;

abstract class BaseTestCase extends TestCase
{
    use WithWorkbench;

    protected Processor $processor;
    protected MemoryDispatcher $dispatcher;

    /**
     * @throws BindingResolutionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->processor  = $this->app->make(Processor::class);
        $this->dispatcher = $this->app->make(MemoryDispatcher::class);
    }

    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    /**
     * @param class-string<WatcherInterface> $watcherClass
     */
    protected function registerWatcher(string $watcherClass): void
    {
        $this->processor->registerWatcher($watcherClass);
    }
}
