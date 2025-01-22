<?php

namespace SLoggerLaravel\Dispatcher\Queue;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use SLoggerLaravel\Dispatcher\Queue\Jobs\TraceCreateJob;
use SLoggerLaravel\Dispatcher\Queue\Jobs\TraceUpdateJob;
use SLoggerLaravel\Dispatcher\TraceDispatcherInterface;
use SLoggerLaravel\Objects\TraceObject;
use SLoggerLaravel\Objects\TraceObjects;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Objects\TraceUpdateObjects;
use Symfony\Component\Console\Output\OutputInterface;

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
    public function start(OutputInterface $output): void
    {
        $this->app->make(QueueManager::class)->start($output);
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
