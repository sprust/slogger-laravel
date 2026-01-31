<?php

namespace SLoggerLaravel\Watchers;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Events\WatcherErrorEvent;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Traces\TraceIdContainer;
use Throwable;

abstract class AbstractWatcher
{
    private Dispatcher $events;

    abstract public function register(): void;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(
        protected readonly Application $app,
        protected readonly Processor $processor,
        protected readonly TraceIdContainer $traceIdContainer,
        protected readonly WatchersConfig $loggerConfig,
    ) {
        $this->events = $this->app->get(Dispatcher::class);

        $this->init();
    }

    protected function init(): void
    {
    }

    /**
     * @param array<mixed> $listener
     */
    protected function listenEvent(string $eventClass, array $listener): void
    {
        $this->events->listen($eventClass, $listener);
    }

    protected function safeHandleWatching(Closure $callback): mixed
    {
        if ($this->processor->isPaused()) {
            return null;
        }

        try {
            return $callback();
        } catch (Throwable $exception) {
            try {
                $this->processor->handleWithoutTracing(function () use ($exception) {
                    $this->events->dispatch(new WatcherErrorEvent($exception));
                });
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    message: $exception->getMessage(),
                    previous: $exception
                );
            }
        }

        return null;
    }
}
