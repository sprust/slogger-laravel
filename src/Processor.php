<?php

namespace SLoggerLaravel;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use SLoggerLaravel\Dispatcher\Items\TraceDispatcherInterface;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Events\WatcherErrorEvent;
use SLoggerLaravel\Helpers\MetricsHelper;
use SLoggerLaravel\Helpers\TraceDataComplementer;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Profiling\AbstractProfiling;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;
use SLoggerLaravel\Traces\TraceIdContainer;
use Throwable;

class Processor
{
    /**
     * Marks a trace that was still running when its parent had been stopped.
     */
    public const INTERRUPTED_TAG = '__interrupted';

    /**
     * Long enough that a request still in flight is never taken for an abandoned one.
     */
    public const DETACHED_TRACE_TTL_SECONDS = 300;

    /**
     * Open parent traces, outermost first.
     *
     * @var list<array{trace_id: string, pre_parent_trace_id: string|null, tags: string[], logged_at: Carbon}>
     */
    private array $tracesStack = [];

    /** @see handleWithoutTracing() */
    private bool $paused = false;

    /**
     * Open detached traces, by trace id: kept off the stack because they are closed
     * in no particular order.
     *
     * @var array<string, array{owner_trace_id: string|null, tags: string[], logged_at: Carbon}>
     */
    private array $detachedTraces = [];

    /**
     * Notified of every trace closed by the sweep rather than by its own watcher.
     *
     * @var list<Closure(string): void>
     */
    private array $traceInterruptedListeners = [];

    public function __construct(
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
     * @param Closure(string): void $listener
     *
     * @see stopInterruptedNested()
     * @see stopInterruptedDetached()
     * @see sweepExpiredDetached()
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
                // the reporting path itself is broken: telemetry must not surface in
                // the host application, least of all as a replacement exception
            }
        }

        return null;
    }

    /**
     * Runs something the watchers must not see - pushing a trace fires watchable
     * events of its own, and tracing those is how a storm starts.
     */
    public function handleWithoutTracing(Closure $callback): mixed
    {
        $previousPaused = $this->paused;

        $this->paused = true;

        try {
            return $callback();
        } finally {
            // restore rather than clear: these nest
            $this->paused = $previousPaused;
        }
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

        $this->tracesStack[] = [
            'trace_id'            => $traceId,
            'pre_parent_trace_id' => $parentTraceId,
            'tags'                => $tags,
            'logged_at'           => $loggedAt->clone(),
        ];

        $this->traceIdContainer->setParentTraceId($traceId);

        // after the id exists, so the profile belongs to this trace
        $this->profiler->start($traceId);

        return $traceId;
    }

    /**
     * A parent trace kept off the stack and closed in any order. `Http::pool()` keeps
     * several requests in flight at once: they neither nest nor finish in order.
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

        $this->complement($data);

        $this->dispatchPushTrace(
            new TraceCreateObject(
                traceId: TraceHelper::makeTraceId(),
                parentTraceId: $this->traceIdContainer->getParentTraceId(),
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
     * Closes a parent trace, whichever way it was started. One entry point on
     * purpose: a caller made to pick the matching stop could only pick wrong.
     *
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
            unset($this->detachedTraces[$traceId]);

            // no profiler: it profiles the enclosing trace, which is still running
            $this->dispatchStopTrace(
                traceId: $traceId,
                status: $status,
                profiling: null,
                tags: $tags,
                data: $data,
                duration: $duration,
                parentLoggedAt: $parentLoggedAt,
            );

            return;
        }

        $index = $this->findStackIndex($traceId);

        if (is_null($index)) {
            // already stopped: a worker can report the same job twice - the timeout
            // handler fails one that has just been processed
            return;
        }

        // a trace can be stopped with nested ones still open - a job failed from the
        // SIGALRM handler, mid-flight. Those would hang in `started` forever
        $this->stopInterruptedNested(parentIndex: $index);

        $this->stopInterruptedDetached(ownerTraceId: $traceId);

        $this->sweepExpiredDetached();

        $stackItem = $this->tracesStack[$index];

        array_pop($this->tracesStack);

        // back to whatever this trace was started under
        $this->traceIdContainer->setParentTraceId($stackItem['pre_parent_trace_id']);

        $this->dispatchStopTrace(
            traceId: $traceId,
            status: $status,
            profiling: $this->profiler->stop($traceId),
            tags: $tags,
            data: $data,
            duration: $duration,
            parentLoggedAt: $parentLoggedAt,
        );

        if ($this->tracesStack === []) {
            // a call made with no trace around it has no owner to name it, and under
            // FPM the process ends long before any TTL
            $this->stopOwnerlessDetached();

            // one `queue:work` process runs job after job, and this is the only thing
            // that separates them. After the final update, which still carries them
            $this->traceDataComplementer->endUnitOfWork();
        }
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

        $this->complement($data);

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
            $this->complement($data);
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
        $tracesStack = $this->tracesStack;

        for ($index = count($tracesStack) - 1; $index >= 0; $index--) {
            if ($tracesStack[$index]['trace_id'] === $traceId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Fails the traces started above the stopping one. Their data is left untouched:
     * what they collected on start is all there is to say what they were doing.
     */
    private function stopInterruptedNested(int $parentIndex): void
    {
        $interrupted = array_slice($this->tracesStack, $parentIndex + 1);

        $this->tracesStack = array_slice($this->tracesStack, 0, $parentIndex + 1);

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
     * Closes the outbound calls the stopping trace made that never came back.
     */
    private function stopInterruptedDetached(string $ownerTraceId): void
    {
        foreach ($this->detachedTraces as $traceId => $detachedTrace) {
            if ($detachedTrace['owner_trace_id'] !== $ownerTraceId) {
                continue;
            }

            $this->closeInterruptedDetached($traceId, $detachedTrace);
        }
    }

    /**
     * Closes the detached traces that never had an owner, once nothing is open here.
     */
    private function stopOwnerlessDetached(): void
    {
        foreach ($this->detachedTraces as $traceId => $detachedTrace) {
            if (!is_null($detachedTrace['owner_trace_id'])) {
                continue;
            }

            $this->closeInterruptedDetached($traceId, $detachedTrace);
        }
    }

    /**
     * Age is what reaches a detached trace nobody will close by name: a promise that
     * never settles inside a unit of work that keeps going.
     */
    private function sweepExpiredDetached(): void
    {
        foreach ($this->detachedTraces as $traceId => $detachedTrace) {
            $expiresAt = $detachedTrace['logged_at']
                ->clone()
                ->addSeconds(self::DETACHED_TRACE_TTL_SECONDS);

            if (!$expiresAt->isPast()) {
                continue;
            }

            $this->closeInterruptedDetached($traceId, $detachedTrace);
        }
    }

    /**
     * @param array{owner_trace_id: string|null, tags: string[], logged_at: Carbon} $detachedTrace
     */
    private function closeInterruptedDetached(string $traceId, array $detachedTrace): void
    {
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

    /**
     * A swept trace is closed without its watcher hearing about it. Unless told, the
     * watcher keeps the entry forever and takes that stale one next time.
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

    /**
     * Paused: a registered callback runs application code, and an unpaused query in
     * one is watched, pushed, complemented and run again until memory runs out.
     *
     * @param array<string, mixed> $data
     */
    private function complement(array &$data): void
    {
        $this->handleWithoutTracing(function () use (&$data): void {
            $this->traceDataComplementer->inject($data);
        });
    }

    private function dispatchPushTrace(TraceCreateObject $trace): void
    {
        $this->handleWithoutTracing(
            fn() => $this->traceDispatcher->create($trace)
        );
    }

    private function dispatchUpdateTrace(TraceUpdateObject $trace): void
    {
        $this->handleWithoutTracing(
            fn() => $this->traceDispatcher->update($trace)
        );
    }
}
