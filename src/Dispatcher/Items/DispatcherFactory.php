<?php

namespace SLoggerLaravel\Dispatcher\Items;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use SLoggerLaravel\Dispatcher\Items\File\TraceFileDispatcher;
use SLoggerLaravel\Dispatcher\Items\Queue\TraceQueueDispatcher;
use SLoggerLaravel\Dispatcher\Items\Transporter\TraceTransporterDispatcher;

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
            'queue' => $this->app->make(TraceQueueDispatcher::class),
            'transporter' => $this->app->make(TraceTransporterDispatcher::class),
            'file' => $this->app->make(TraceFileDispatcher::class),
            default => throw new RuntimeException("Unknown dispatcher: $dispatcher"),
        };
    }
}
