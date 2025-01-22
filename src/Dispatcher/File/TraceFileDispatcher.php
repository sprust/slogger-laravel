<?php

namespace SLoggerLaravel\Dispatcher\File;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;
use RuntimeException;
use SLoggerLaravel\Dispatcher\TraceDispatcherInterface;
use SLoggerLaravel\Objects\TraceObject;
use SLoggerLaravel\Objects\TraceObjects;
use SLoggerLaravel\Objects\TraceUpdateObject;
use Symfony\Component\Console\Output\OutputInterface;

class TraceFileDispatcher implements TraceDispatcherInterface
{
    /** @var TraceObject[] */
    private array $traces = [];

    public function __construct(protected readonly Application $app)
    {
    }

    public function start(OutputInterface $output): void
    {
        throw new RuntimeException('Not implemented');
    }
    public function create(TraceObject $parameters): void
    {
        $this->traces[] = $parameters;
    }

    public function update(TraceUpdateObject $parameters): void
    {
        if (!$this->traces) {
            return;
        }

        $filtered = array_filter(
            $this->traces,
            fn(TraceObject $traceItem) => $traceItem->parentTraceId === $parameters->traceId
                || $traceItem->traceId === $parameters->traceId
        );

        $traces = Arr::sort(
            $filtered,
            fn(TraceObject $traceItem) => $traceItem->loggedAt->getTimestampMs()
        );

        /** @var TraceObject $parentTrace */
        $parentTrace = Arr::first(
            $traces,
            fn(TraceObject $traceItem) => $traceItem->traceId === $parameters->traceId
        );

        if (!is_null($parameters->data)) {
            $parentTrace->data = $parameters->data;
        }


        if (!is_null($parameters->tags)) {
            $parentTrace->tags = $parameters->tags;
        }

        $this->sendTraces($parentTrace, $traces);

        $this->traces = array_filter(
            $this->traces,
            fn(TraceObject $traceItem) => $traceItem->parentTraceId !== $parameters
        );
    }

    /**
     * @param TraceObject[] $traces
     *
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    protected function sendTraces(TraceObject $parentTrace, array $traces): void
    {
        $storage = $this->app['filesystem']->build([
            'driver' => 'local',
            'root'   => storage_path('logs/slogger-traces'),
        ]);

        $traceObjects = new TraceObjects();

        foreach ($traces as $trace) {
            $traceObjects->add($trace);
        }

        $storage->put(
            $parentTrace->loggedAt->toDateTimeString('microsecond') . '-' . $parentTrace->type . '.json',
            json_encode(array_values($traces), JSON_PRETTY_PRINT)
        );
    }
}
