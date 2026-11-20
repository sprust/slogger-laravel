<?php

namespace SLoggerLaravel\Dispatcher\Items\Queue;

use Closure;
use Illuminate\Support\Facades\Log;
use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\SendTracesJob;
use SLoggerLaravel\Dispatcher\Items\TraceDispatcherInterface;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use Throwable;

class QueueDispatcher implements TraceDispatcherInterface
{
    // the direct mode talks to a remote receiver over TCP: network faults and
    // receiver restarts justify a long ladder
    private const DIRECT_TRIES   = 5;
    private const DIRECT_BACKOFF = [5, 10, 30, 60];

    // the proxy is a local Unix socket with a durable buffer: its failures are
    // instant and either permanent (a record the proxy rejected) or gone in
    // seconds (a restart, busy slots) - a long ladder buys nothing there, and
    // shorter retries free the worker sooner
    private const PROXY_TRIES   = 3;
    private const PROXY_BACKOFF = [2, 5];

    private const DISPATCH_FAIL_LOG_INTERVAL_SECONDS = 60;

    private TracesObject $traces;

    private int $maxBatchSize = 5;

    private int $lastDispatchFailLogAt = 0;
    private int $droppedDispatches     = 0;

    public function __construct(
        private readonly DispatcherQueueConfig $config,
    ) {
        $this->traces = new TracesObject();
    }

    public function create(TraceCreateObject $parameters): void
    {
        // parent and orphan traces are dispatched immediately (intentional).
        if ($parameters->isParent || $parameters->parentTraceId === null) {
            $this->safeDispatch(
                fn() => dispatch(
                    $this->makeJob(
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
            $this->safeDispatch(fn() => dispatch($this->makeJob($traces)));
        }
    }

    private function makeJob(TracesObject $traces): SendTracesJob
    {
        if ($this->config->getSocketClientType() === DispatcherQueueConfig::SOCKET_CLIENT_PROXY) {
            return new SendTracesJob(
                traces: $traces,
                tries: self::PROXY_TRIES,
                backoff: self::PROXY_BACKOFF,
            );
        }

        return new SendTracesJob(
            traces: $traces,
            tries: self::DIRECT_TRIES,
            backoff: self::DIRECT_BACKOFF,
        );
    }

    /**
     * Telemetry must never break or spam the application.
     *
     * A send or enqueue failure is logged to slogger's own channel, rate-limited, and
     * dropped - never propagated into the application's request handling.
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
            Log::channel(app(GeneralConfig::class)->getLogChannel())
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
