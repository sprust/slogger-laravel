<?php

namespace SLoggerLaravel;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use LogicException;
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
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Traces\TraceScope;
use SLoggerLaravel\Traces\TraceScopeResolverInterface;
use SLoggerLaravel\Watchers\WatcherInterface;
use Throwable;

class Processor
{
    /**
     * Marks a trace that was still running when its parent had been stopped.
     */
    public const INTERRUPTED_TAG = '__interrupted';

    /**
     * After this, an ownerless detached trace is swept by whoever notices it, not
     * only by the unit of work that started it. Long enough that a request still in
     * flight is never taken for an abandoned one.
     */
    public const DETACHED_TRACE_TTL_SECONDS = 300;

    /**
     * Open detached traces, by trace id. They are kept off the stack, so this is the
     * only way the parent that started them can still close them.
     *
     * Process-wide on purpose, unlike the stack: a detached trace exists precisely
     * because it is closed somewhere other than where it was started. Under a
     * concurrent runtime the response to an outbound request can be handled in
     * another coroutine than the one that sent it, and a per-scope map would leave
     * the sender to sweep a request that in fact succeeded. The trace id is unique,
     * so one map is enough; who owns what is recorded in `owner_trace_id`.
     *
     * @var array<string, array{owner_trace_id: string|null, owner_scope_id: int, tags: string[], logged_at: Carbon}>
     */
    private array $detachedTraces = [];

    /**
     * Called with the trace id of every trace closed by the sweep rather than by the
     * watcher that started it - detached or nested alike.
     *
     * @var list<Closure(string): void>
     */
    private array $traceInterruptedListeners = [];

    public function __construct(
        private readonly Application $app,
        private readonly State $state,
        private readonly Dispatcher $dispatcher,
        private readonly TraceDispatcherInterface $traceDispatcher,
        private readonly TraceIdContainer $traceIdContainer,
        private readonly AbstractProfiling $profiler,
        private readonly TraceDataComplementer $traceDataComplementer,
        private readonly TraceScopeResolverInterface $scopeResolver
    ) {
    }

    /**
     * Whether a child trace pushed right now has somewhere to hang.
     *
     * The stack is the usual answer, but not the only one: a coroutine gets its own
     * stack and inherits only where its parent's traces hang, so inside one the
     * stack is empty while tracing is very much active. Under a plain process the
     * two are the same thing - the parent trace id is set exactly while the stack is
     * not empty.
     */
    public function isActive(): bool
    {
        $scope = $this->scope();

        return $scope->tracesStack !== [] || !is_null($scope->parentTraceId);
    }

    public function isPaused(): bool
    {
        return $this->scope()->paused;
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

    /**
     * @param Closure(string): void $listener
     *
     * @see stopInterruptedDetached()
     * @see stopInterruptedNested()
     */
    public function onTraceInterrupted(Closure $listener): void
    {
        $this->traceInterruptedListeners[] = $listener;
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
            } catch (Throwable) {
                // the reporting path itself is broken (a dead log channel, a full
                // disk). There is nowhere left to report it to, and telemetry must
                // not surface in the host application - least of all as an exception
                // that replaces the watcher's original one
            }
        }

        return null;
    }

    /**
     * @throws Throwable
     */
    public function handleWithoutTracing(Closure $callback): mixed
    {
        $scope = $this->scope();

        $previousPaused = $scope->paused;

        $scope->paused = true;

        try {
            return $callback();
        } finally {
            // restore rather than clear: these nest (a watcher error is reported from
            // inside a paused section), and clearing would lift the outer pause with
            // the inner one
            $scope->paused = $previousPaused;
        }
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
        $parentTraceId = $this->traceIdContainer->getParentTraceId();

        $traceId = $this->dispatchStartTrace(
            type: $type,
            tags: $tags,
            data: $data,
            loggedAt: $loggedAt,
            parentTraceId: $customParentTraceId ?? $parentTraceId,
        );

        $this->scope()->tracesStack[] = [
            'trace_id'            => $traceId,
            'pre_parent_trace_id' => $parentTraceId,
            'tags'                => $tags,
            'logged_at'           => $loggedAt->clone(),
        ];

        $this->traceIdContainer->setParentTraceId($traceId);

        // after the id exists: the profile belongs to this trace, and a nested one
        // started later must not walk off with it
        $this->profiler->start($traceId);

        return $traceId;
    }

    /**
     * Starts a parent trace that is kept off the trace stack and is closed by
     * stopDetached(), in any order relative to the other traces. Outbound HTTP
     * requests need this: `Http::pool()` keeps several of them in flight at once,
     * so they neither nest into each other nor finish in the order they started.
     *
     * @param string[]             $tags
     * @param array<string, mixed> $data
     */
    public function startAndGetDetachedTraceId(
        string $type,
        array $tags,
        array $data,
        Carbon $loggedAt,
        ?string $customParentTraceId = null
    ): string {
        $ownerTraceId = $customParentTraceId ?? $this->traceIdContainer->getParentTraceId();

        $traceId = $this->dispatchStartTrace(
            type: $type,
            tags: $tags,
            data: $data,
            loggedAt: $loggedAt,
            parentTraceId: $ownerTraceId,
        );

        $this->detachedTraces[$traceId] = [
            'owner_trace_id' => $ownerTraceId,
            // which unit of work started it, so an ownerless one is swept by that
            // unit and not by whichever other one happens to finish first
            'owner_scope_id' => $this->scope()->ownerId,
            'tags'           => $tags,
            'logged_at'      => $loggedAt->clone(),
        ];

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
        if (isset($this->detachedTraces[$traceId])) {
            // the caller mixed up the two APIs; close it the way it was started
            $this->stopDetached(
                traceId: $traceId,
                status: $status,
                tags: $tags,
                data: $data,
                duration: $duration,
                parentLoggedAt: $parentLoggedAt,
            );

            return;
        }

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
        $this->stopInterruptedNested(parentIndex: $index);

        $this->stopInterruptedDetached(ownerTraceId: $traceId);

        $scope = $this->scope();

        $stackItem = $scope->tracesStack[$index];

        array_pop($scope->tracesStack);

        // whatever this trace was started under: the enclosing trace of this unit of
        // work, or - for the outermost one in a coroutine - the trace of the
        // coroutine that spawned it, which this scope inherited and does not own
        $this->traceIdContainer->setParentTraceId(
            parentTraceId: $stackItem['pre_parent_trace_id']
        );

        if ($scope->tracesStack === [] && is_null($scope->parentTraceId)) {
            // and not as its own pre-parent either: an orphan event recorded after
            // this would otherwise be filed as a child of a trace already closed
            $this->traceIdContainer->reset();

            // the unit of work is over: nothing is open here and nothing encloses it.
            // Detached traces started outside any parent have no owner to sweep them,
            // so this is the last chance to close them.
            //
            // The emptiness of the stack alone does not mean that - a coroutine has
            // its own stack and an inherited parent, and clearing it there would drop
            // every child trace pushed afterwards, in silence
            $this->stopInterruptedDetached(ownerTraceId: null);

            $scope->endUnitOfWork();
        }

        $this->dispatchStopTrace(
            traceId: $traceId,
            status: $status,
            profiling: $this->profiler->stop($traceId),
            tags: $tags,
            data: $data,
            duration: $duration,
            parentLoggedAt: $parentLoggedAt,
        );
    }

    /**
     * Closes a trace started by startAndGetDetachedTraceId(). The profiler is left
     * alone: it profiles the enclosing parent trace, which is still running.
     *
     * @param string[]|null             $tags
     * @param array<string, mixed>|null $data
     */
    public function stopDetached(
        string $traceId,
        string $status,
        ?array $tags,
        ?array $data,
        ?float $duration,
        Carbon $parentLoggedAt,
    ): void {
        if (!isset($this->detachedTraces[$traceId])) {
            if (is_null($this->findStackIndex($traceId))) {
                // already closed
                return;
            }

            // the caller mixed up the two APIs; close it the way it was started
            $this->stop(
                traceId: $traceId,
                status: $status,
                tags: $tags,
                data: $data,
                duration: $duration,
                parentLoggedAt: $parentLoggedAt,
            );

            return;
        }

        unset($this->detachedTraces[$traceId]);

        $this->dispatchStopTrace(
            traceId: $traceId,
            status: $status,
            profiling: null,
            tags: $tags,
            data: $data,
            duration: $duration,
            parentLoggedAt: $parentLoggedAt,
        );
    }

    /**
     * The state of whatever is running right now. One scope per process normally,
     * one per coroutine under a concurrent runtime - the singleton is shared either
     * way, its state is not.
     */
    private function scope(): TraceScope
    {
        return $this->scopeResolver->current();
    }

    /**
     * @param string[]             $tags
     * @param array<string, mixed> $data
     */
    private function dispatchStartTrace(
        string $type,
        array $tags,
        array $data,
        Carbon $loggedAt,
        ?string $parentTraceId
    ): string {
        $traceId = TraceHelper::makeTraceId();

        $this->traceDataComplementer->inject($data);

        $this->dispatchPushTrace(
            new TraceCreateObject(
                traceId: $traceId,
                parentTraceId: $parentTraceId,
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

        return $traceId;
    }

    /**
     * @param string[]|null             $tags
     * @param array<string, mixed>|null $data
     */
    private function dispatchStopTrace(
        string $traceId,
        string $status,
        ?ProfilingObjects $profiling,
        ?array $tags,
        ?array $data,
        ?float $duration,
        Carbon $parentLoggedAt,
    ): void {
        if (!is_null($data)) {
            $this->traceDataComplementer->inject($data);
        }

        $this->dispatchUpdateTrace(
            new TraceUpdateObject(
                traceId: $traceId,
                status: $status,
                profiling: $profiling,
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
        $tracesStack = $this->scope()->tracesStack;

        for ($index = count($tracesStack) - 1; $index >= 0; $index--) {
            if ($tracesStack[$index]['trace_id'] === $traceId) {
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
    private function stopInterruptedNested(int $parentIndex): void
    {
        $scope = $this->scope();

        $interrupted = array_slice($scope->tracesStack, $parentIndex + 1);

        $scope->tracesStack = array_slice($scope->tracesStack, 0, $parentIndex + 1);

        foreach (array_reverse($interrupted) as $stackItem) {
            $this->stopInterruptedDetached(ownerTraceId: $stackItem['trace_id']);

            $this->profiler->release($stackItem['trace_id']);

            $this->notifyTraceInterrupted($stackItem['trace_id']);

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

    /**
     * Closes the detached traces the stopping one had started and never closed. A null
     * owner closes the ownerless ones of *this* unit of work, which nothing else can
     * reach - an outbound call made with no trace open around it.
     */
    private function stopInterruptedDetached(?string $ownerTraceId): void
    {
        $scopeId = $this->scope()->ownerId;

        foreach ($this->detachedTraces as $traceId => $detachedTrace) {
            if ($detachedTrace['owner_trace_id'] !== $ownerTraceId) {
                continue;
            }

            if (is_null($ownerTraceId) && $detachedTrace['owner_scope_id'] !== $scopeId) {
                // ownerless, and started by a different unit of work - which may still
                // be waiting on it, so only its own unit may declare it interrupted.
                // Unless that unit is gone: a coroutine that died holding one would
                // otherwise leave the trace `started` forever and the entry here for
                // as long as the process lives
                if (!$detachedTrace['logged_at']->clone()->addSeconds(self::DETACHED_TRACE_TTL_SECONDS)->isPast()) {
                    continue;
                }
            }

            unset($this->detachedTraces[$traceId]);

            $this->dispatchStopTrace(
                traceId: $traceId,
                status: TraceStatusEnum::Failed->value,
                profiling: null,
                tags: [
                    ...$detachedTrace['tags'],
                    self::INTERRUPTED_TAG,
                ],
                data: null,
                duration: TraceHelper::calcDuration($detachedTrace['logged_at']),
                parentLoggedAt: $detachedTrace['logged_at'],
            );

            $this->notifyTraceInterrupted($traceId);
        }
    }

    /**
     * The watcher that started a trace keeps its own bookkeeping for it and clears it
     * when the trace is closed. A trace swept from here is closed without the watcher
     * ever hearing about it, so tell it - otherwise a long-lived worker accumulates
     * one orphaned entry per swept trace, and a watcher holding a stack pops a stale
     * entry next time and leaves its own trace open forever.
     */
    private function notifyTraceInterrupted(string $traceId): void
    {
        foreach ($this->traceInterruptedListeners as $listener) {
            try {
                $listener($traceId);
            } catch (Throwable) {
                // a bookkeeping callback must never break the sweep
            }
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
        $scope = $this->scope();

        $previousPaused = $scope->paused;

        $scope->paused = true;

        try {
            $callback();
        } finally {
            $scope->paused = $previousPaused;
        }
    }
}
