<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature;

use Illuminate\Contracts\Container\BindingResolutionException;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use SLoggerLaravel\Dispatcher\Items\Memory\MemoryDispatcher;
use SLoggerLaravel\ServiceProvider;
use SLoggerLaravel\State;
use SLoggerLaravel\Watchers\AbstractWatcher;

abstract class BaseTestCase extends TestCase
{
    use WithWorkbench;

    protected State $state;
    protected MemoryDispatcher $dispatcher;

    /**
     * @throws BindingResolutionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->state      = $this->app->make(State::class);
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
     * @param class-string<AbstractWatcher> $watcherClass
     */
    protected function registerWatcher(string $watcherClass): void
    {
        $this->state->addEnabledWatcher($watcherClass);

        /** @var AbstractWatcher $watcher */
        $watcher = $this->app->make($watcherClass);

        $watcher->register();
    }
}
