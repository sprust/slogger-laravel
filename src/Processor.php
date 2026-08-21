<?php

namespace SLoggerLaravel;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use LogicException;
use RuntimeException;
use SLoggerLaravel\Dispatcher\Items\TraceDispatcherInterface;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Events\WatcherErrorEvent;
use SLoggerLaravel\Helpers\DataFormatter;
use SLoggerLaravel\Helpers\MetricsHelper;
use SLoggerLaravel\Helpers\TraceDataComplementer;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Profiling\AbstractProfiling;
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Watchers\WatcherInterface;
use Throwable;

class Processor
{
    /**
     * Marks a trace that was still running when its parent had been stopped.
     */
    public const INTERRUPTED_TAG = '__interrupted';

    /**
     * Currently started parent traces, from the outermost to the innermost one.
     *
     * @var list<array{trace_id: string, pre_parent_trace_id: string|null, tags: string[], logged_at: Carbon}>
     */
    private array $tracesStack = [];

    private bool $paused = false;

    public function __construct(
        private readonly Application $app,
        private readonly State $state,
        private readonly Dispatcher $dispatcher,
        private readonly TraceDispatcherInterface $traceDispatcher,
        private readonly TraceIdContainer $traceIdContainer,
        private readonly AbstractProfiling $profiler,
        private readonly TraceDataComplementer $traceDataComplementer
    ) {
    }

    public function isActive(): bool
    {
        return $this->tracesStack !== [];
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    /**
     * @param class-string<WatcherInterface> $watcherClass
     * @param array<string, mixed>|null      $config
     */
    public function registerWatcher(string $watcherClass, ?array $config): void
    {
        /** @var WatcherInterface $watcher */
        $watcher = $this->app->make($watcherClass);

        $watcher->register($config);

        $this->state->addEnabledWatcher($watcherClass);
    }

    public function registerEvent(string $event, callable $listener): void
    {
        $this->dispatcher->listen(
            $event,
            fn(mixed ...$eventData) => $this->handleWatcher(
                fn() => call_user_func_array($listener, $eventData)
            )
        );
    }

    /**
     * @param Closure(): mixed $callback
     */
    public function handleWatcher(Closure $callback): mixed
    {
        if ($this->isPaused()) {
            return null;
        }

        try {
            return $callback();
        } catch (Throwable $exception) {
            try {
                $this->handleWithoutTracing(function () use ($exception) {
                    $this->dispatcher->dispatch(new WatcherErrorEvent($exception));
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

    /**
     * @throws Throwable
     */
    public function handleWithoutTracing(Closure $callback): mixed
    {
        $this->paused = true;

        $exception = null;

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $result = null;
        }

        $this->paused = false;

        if ($exception) {
            throw $exception;
        }

        return $result;
    }

    /**
     * @param string[]             $tags
     * @param array<string, mixed> $data
     *
     * @throws Throwable
     */
    public function handleSeparateTracing(
        Closure $callback,
        string $type,
        array $tags,
        array $data,
        ?string $customParentTraceId,
        Carbon $loggedAt,
    ): mixed {
        $this->profiler->start();

        $traceId = $this->startAndGetTraceId(
            type: $type,
            tags: $tags,
            data: $data,
            loggedAt: $loggedAt,
            customParentTraceId: $customParentTraceId,
        );

        $startedAt = Carbon::now();

        $exception = null;

        $dataChanged = false;

        try {
            $result = $this->app->call($callback);
        } catch (Throwable $exception) {
            $result = null;

            $data['exception'] = DataFormatter::exception($exception);

            $dataChanged = true;
        }

        $this->stop(
            traceId: $traceId,
            status: $exception
                ? TraceStatusEnum::Failed->value
                : TraceStatusEnum::Success->value,
            tags: null,
            data: $dataChanged ? $data : null,
            duration: TraceHelper::calcDuration($startedAt),
            parentLoggedAt: $loggedAt,
        );

        if ($exception) {
            throw $exception;
        }

        return $result;
    }

    /**
     * @param string[]             $tags
     * @param array<string, mixed> $data
     */
    public function startAndGetTraceId(
        string $type,
        array $tags,
        array $data,
        Carbon $loggedAt,
        ?string $customParentTraceId
    ): string {
        $this->profiler->start();

        $traceId = TraceHelper::makeTraceId();

        $parentTraceId = $this->traceIdContainer->getParentTraceId();

        $this->traceDataComplementer->inject($data);

        $this->dispatchPushTrace(
            new TraceCreateObject(
                traceId: $traceId,
                parentTraceId: $customParentTraceId ?? $parentTraceId,
                type: $type,
                status: TraceStatusEnum::Started->value,
                tags: $tags,
                data: $data,
                duration: null,
                memory: MetricsHelper::getMemoryUsagePercent(),
                cpu: MetricsHelper::getCpuAvgPercent(),
                isParent: true,
                loggedAt: $loggedAt->clone()
            )
        );

        $this->tracesStack[] = [
            'trace_id'            => $traceId,
            'pre_parent_trace_id' => $parentTraceId,
            'tags'                => $tags,
            'logged_at'           => $loggedAt->clone(),
        ];

        $this->traceIdContainer->setParentTraceId($traceId);

        return $traceId;
    }

    /**
     * @param string[]             $tags
     * @param array<string, mixed> $data
     */
    public function push(
        string $type,
        string $status,
        array $tags = [],
        array $data = [],
        ?float $duration = null,
        ?Carbon $loggedAt = null,
        bool $canBeOrphan = false
    ): void {
        if (!$canBeOrphan && !$this->isActive()) {
            return;
        }

        $traceId = TraceHelper::makeTraceId();

        $parentTraceId = $this->traceIdContainer->getParentTraceId()
            // when a parent is in excluded
            ?? $this->traceIdContainer->getPreParentTraceId();

        if (!$canBeOrphan && !$parentTraceId) {
            throw new LogicException("Parent trace id has not found for $type.");
        }

        $this->traceDataComplementer->inject($data);

        $this->dispatchPushTrace(
            new TraceCreateObject(
                traceId: $traceId,
                parentTraceId: $parentTraceId,
                type: $type,
                status: $status,
                tags: $tags,
                data: $data,
                duration: $duration,
                memory: MetricsHelper::getMemoryUsagePercent(),
                cpu: MetricsHelper::getCpuAvgPercent(),
                isParent: false,
                loggedAt: ($loggedAt ?: Carbon::now())->clone()
            )
        );
    }

    /**
     * @param string[]|null             $tags
     * @param array<string, mixed>|null $data
     */
    public function stop(
        string $traceId,
        string $status,
        ?array $tags,
        ?array $data,
        ?float $duration,
        Carbon $parentLoggedAt,
    ): void {
        $index = $this->findStackIndex($traceId);

        if (is_null($index)) {
            // the trace has already been stopped: a worker can report the same job
            // twice - the timeout signal handler fails a job that has just been
            // processed, so both JobProcessed and JobFailed ask to stop it
            return;
        }

        // the trace may be stopped while nested ones are still open: the queue worker
        // fails a job from the SIGALRM handler, i.e. in the middle of whatever the job
        // was doing. Close the interrupted children, otherwise they would hang
        // in the "started" status forever
        $this->stopInterruptedNested(parentIndex: $index, parentTraceId: $traceId);

        $stackItem = $this->tracesStack[$index];

        array_pop($this->tracesStack);

        $this->traceIdContainer->setParentTraceId(
            parentTraceId: $stackItem['pre_parent_trace_id']
        );

        if (count($this->tracesStack) == 0) {
            $this->traceIdContainer->setParentTraceId(null);
        }

        if (!is_null($data)) {
            $this->traceDataComplementer->inject($data);
        }

        $this->dispatchUpdateTrace(
            new TraceUpdateObject(
                traceId: $traceId,
                status: $status,
                profiling: $this->profiler->stop(),
                tags: $tags,
                data: $data,
                duration: $duration,
                memory: MetricsHelper::getMemoryUsagePercent(),
                cpu: MetricsHelper::getCpuAvgPercent(),
                parentLoggedAt: $parentLoggedAt,
            )
        );
    }

    private function findStackIndex(string $traceId): ?int
    {
        for ($index = count($this->tracesStack) - 1; $index >= 0; $index--) {
            if ($this->tracesStack[$index]['trace_id'] === $traceId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Closes the traces started above the stopping one as failed. Their data is left
     * untouched - an update replaces it, and what they collected on start is the only
     * thing left to tell what they were doing when they got interrupted.
     */
    private function stopInterruptedNested(int $parentIndex, string $parentTraceId): void
    {
        $interrupted = array_slice($this->tracesStack, $parentIndex + 1);

        $this->tracesStack = array_slice($this->tracesStack, 0, $parentIndex + 1);

        foreach (array_reverse($interrupted) as $stackItem) {
            $loggedAt = $stackItem['logged_at'];

            $this->dispatchUpdateTrace(
                new TraceUpdateObject(
                    traceId: $stackItem['trace_id'],
                    status: TraceStatusEnum::Failed->value,
                    profiling: null,
                    tags: [
                        ...$stackItem['tags'],
                        self::INTERRUPTED_TAG,
                    ],
                    data: null,
                    duration: TraceHelper::calcDuration($loggedAt),
                    memory: MetricsHelper::getMemoryUsagePercent(),
                    cpu: MetricsHelper::getCpuAvgPercent(),
                    parentLoggedAt: $loggedAt,
                )
            );
        }
    }

    private function dispatchPushTrace(TraceCreateObject $trace): void
    {
        $this->withPausedTracing(
            fn() => $this->traceDispatcher->create($trace)
        );
    }

    private function dispatchUpdateTrace(TraceUpdateObject $trace): void
    {
        $this->withPausedTracing(
            fn() => $this->traceDispatcher->update($trace)
        );
    }

    /**
     * Pauses tracing while the dispatcher pushes a trace: the push itself may
     * fire watchable events (queue payload INSERT with the database driver,
     * JobQueued with only_events, etc.) and must never be traced recursively.
     *
     * @param Closure(): void $callback
     */
    private function withPausedTracing(Closure $callback): void
    {
        $previousPaused = $this->paused;

        $this->paused = true;

        try {
            $callback();
        } finally {
            $this->paused = $previousPaused;
        }
    }
}
