<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue;

use Closure;
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
    private const DISPATCH_FAIL_LOG_INTERVAL_SECONDS = 60;

    private TracesObject $traces;

    private int $maxBatchSize = 5;

    private int $lastDispatchFailLogAt = 0;
    private int $droppedDispatches     = 0;

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
        // parent and orphan traces are dispatched immediately (intentional).
        if ($parameters->isParent || $parameters->parentTraceId === null) {
            $this->safeDispatch(
                fn() => dispatch(
                    new SendTracesJob(
                        (new TracesObject())->addCreating($parameters)
                    )
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
        $tracesCount = $this->traces->count();

        if ($maxBatchSize > 0 && $tracesCount < $maxBatchSize) {
            return;
        }

        // swap the buffer out before dispatching so the buffer is always cleared,
        // even when the dispatch itself fails and gets dropped.
        $traces = $this->traces;

        $this->traces = new TracesObject();

        if ($tracesCount > 0) {
            $this->safeDispatch(fn() => dispatch(new SendTracesJob($traces)));
        }
    }

    /**
     * Telemetry must never break or spam the application.
     *
     * A send/enqueue failure — unreachable trace receiver on a synchronous
     * connection, a down broker on enqueue, a serialization or configuration
     * error — is logged to slogger's own channel (rate-limited) and dropped.
     * It is never propagated into the app's request/event handling, where the
     * watcher firewall would otherwise surface it through report().
     *
     * @param Closure(): mixed $dispatch
     */
    private function safeDispatch(Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $exception) {
            $this->logDispatchFailure($exception);
        }
    }

    private function logDispatchFailure(Throwable $exception): void
    {
        $this->droppedDispatches++;

        $now = time();

        if (($now - $this->lastDispatchFailLogAt) < self::DISPATCH_FAIL_LOG_INTERVAL_SECONDS) {
            return;
        }

        $this->lastDispatchFailLogAt = $now;

        $droppedDispatches = $this->droppedDispatches;

        $this->droppedDispatches = 0;

        try {
            Log::channel($this->app->make(GeneralConfig::class)->getLogChannel())
                ->warning(
                    sprintf(
                        'slogger: dropped %d trace dispatches in the last %ds: %s',
                        $droppedDispatches,
                        self::DISPATCH_FAIL_LOG_INTERVAL_SECONDS,
                        $exception->getMessage()
                    )
                );
        } catch (Throwable) {
            // no action
        }
    }

    public function __destruct()
    {
        // dispatchAndClear is already guarded by safeDispatch; the extra catch
        // only protects against unexpected errors, since destructors must not throw.
        try {
            $this->dispatchAndClear(maxBatchSize: 0);
        } catch (Throwable) {
            // no action
        }
    }
}
