<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature;

use Illuminate\Contracts\Container\BindingResolutionException;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use SLoggerLaravel\Dispatcher\Items\Memory\MemoryDispatcher;
use SLoggerLaravel\Enums\TraceStatusEnum;
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

        assert($this->app !== null);

        $this->processor  = $this->app->make(Processor::class);
        $this->dispatcher = $this->app->make(MemoryDispatcher::class);

        $this->dispatcher->flush();
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

    protected function assertParentTraces(bool $isSuccess): void
    {
        $creatingTraces = $this->dispatcher->getCreatingTraces();

        self::assertCount(
            1,
            $creatingTraces
        );

        $updatingTraces = $this->dispatcher->getUpdatingTraces();

        self::assertCount(
            1,
            $updatingTraces
        );

        $creatingTrace = $creatingTraces[0];
        $updatingTrace = $updatingTraces[0];

        self::assertSame(
            $creatingTrace->status,
            TraceStatusEnum::Started->value,
        );

        self::assertSame(
            $updatingTrace->status,
            $isSuccess ? TraceStatusEnum::Success->value : TraceStatusEnum::Failed->value,
        );

        self::assertSame(
            $creatingTrace->traceId,
            $updatingTrace->traceId,
        );

        self::assertSame(
            $creatingTrace->loggedAt->toDateTimeString('microseconds'),
            $updatingTrace->parentLoggedAt->toDateTimeString('microseconds'),
        );

        if ($isSuccess) {
            return;
        }

        self::assertArrayHasKey(
            'exception',
            $updatingTrace->data ?: [],
        );
    }
}
