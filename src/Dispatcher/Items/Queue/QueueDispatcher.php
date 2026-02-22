<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\Items\DispatcherProcessorInterface;
use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\SendTracesJob;
use SLoggerLaravel\Dispatcher\Items\TraceDispatcherInterface;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use Throwable;

class QueueDispatcher implements TraceDispatcherInterface
{
    private TracesObject $traces;

    private int $maxBatchSize = 5;

    public function __construct(protected readonly Application $app)
    {
        $this->traces = new TracesObject();
    }

    /**
     * @throws BindingResolutionException
     */
    public function getProcessor(): DispatcherProcessorInterface
    {
        return $this->app->make(QueueDispatcherProcessor::class);
    }

    public function create(TraceCreateObject $parameters): void
    {
        if ($parameters->isParent) {
            dispatch(
                new SendTracesJob(
                    (new TracesObject())
                        ->addCreating($parameters)
                )
            );

            return;
        }

        if ($parameters->parentTraceId === null) {
            dispatch(
                new SendTracesJob(
                    (new TracesObject())
                        ->addCreating($parameters)
                )
            );

            return;
        }

        $this->traces->addCreating($parameters);

        $this->dispatchAndClear($this->maxBatchSize);
    }

    public function update(TraceUpdateObject $parameters): void
    {
        $this->traces->addUpdating($parameters);

        $this->dispatchAndClear(maxBatchSize: 0);
    }

    protected function dispatchAndClear(int $maxBatchSize): void
    {
        if ($maxBatchSize > 0 && $this->traces->count() < $maxBatchSize) {
            return;
        }

        dispatch(new SendTracesJob($this->traces));

        $this->traces = new TracesObject();
    }

    /**
     * @throws BindingResolutionException
     */
    public function __destruct()
    {
        try {
            $this->dispatchAndClear(maxBatchSize: 0);
        } catch (Throwable $exception) {
            Log::channel($this->app->make(GeneralConfig::class)->getLogChannel())
                ->error($exception->getMessage(), [
                    'code'  => $exception->getCode(),
                    'file'  => $exception->getFile(),
                    'line'  => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ]);
        }
    }
}
