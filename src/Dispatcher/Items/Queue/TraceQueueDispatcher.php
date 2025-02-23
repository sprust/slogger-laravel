<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use SLoggerLaravel\Dispatcher\Items\DispatcherProcessorInterface;
use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\TraceCreateJob;
use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\TraceUpdateJob;
use SLoggerLaravel\Dispatcher\Items\TraceDispatcherInterface;
use SLoggerLaravel\Objects\TraceObject;
use SLoggerLaravel\Objects\TraceObjects;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Objects\TraceUpdateObjects;

class TraceQueueDispatcher implements TraceDispatcherInterface
{
    /** @var TraceObject[] */
    private array $traces = [];

    private int $maxBatchSize = 5;

    public function __construct(protected readonly Application $app)
    {
    }

    /**
     * @throws BindingResolutionException
     */
    public function getProcessor(): DispatcherProcessorInterface
    {
        return $this->app->make(QueueDispatcherProcessor::class);
    }

    public function create(TraceObject $parameters): void
    {
        $this->traces[] = $parameters;

        if (count($this->traces) < $this->maxBatchSize) {
            return;
        }

        $this->sendAndClearTraces();
    }

    public function update(TraceUpdateObject $parameters): void
    {
        if (count($this->traces)) {
            $this->sendAndClearTraces();
        }

        $traceObjects = (new TraceUpdateObjects())
            ->add($parameters);

        dispatch(new TraceUpdateJob($traceObjects->toJson()));
    }

    public function terminate(): void
    {
        if (!count($this->traces)) {
            return;
        }

        $this->sendAndClearTraces();
    }

    protected function sendAndClearTraces(): void
    {
        $traceObjects = new TraceObjects();

        foreach ($this->traces as $trace) {
            $traceObjects->add($trace);
        }

        dispatch(new TraceCreateJob($traceObjects->toJson()));

        $this->traces = [];
    }
}
