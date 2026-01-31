<?php

namespace SLoggerLaravel\Dispatcher\Items;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use SLoggerLaravel\Dispatcher\Items\Memory\MemoryDispatcher;
use SLoggerLaravel\Dispatcher\Items\Queue\QueueDispatcher;

readonly class DispatcherFactory
{
    public function __construct(private Application $app)
    {
    }

    /**
     * @throws BindingResolutionException
     */
    public function create(string $dispatcher): TraceDispatcherInterface
    {
        return match ($dispatcher) {
            'queue' => $this->app->make(QueueDispatcher::class),
            'memory' => $this->app->make(MemoryDispatcher::class),
            default => throw new RuntimeException("Unknown dispatcher: $dispatcher"),
        };
    }
}
