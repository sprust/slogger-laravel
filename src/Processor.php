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
    private bool $started = false;

    /**
     * @var array<string|null>
     */
    private array $preParentIdsStack = [];

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
        return $this->started;
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

        $startedAt = Carbon::now('UTC');

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
                loggedAt: $loggedAt->clone()->setTimezone('UTC')
            )
        );

        $this->preParentIdsStack[] = $parentTraceId;

        $this->traceIdContainer->setParentTraceId($traceId);

        $this->started = true;

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
                loggedAt: ($loggedAt ?: Carbon::now())->clone()->setTimezone('UTC')
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
        if (!$this->isActive()) {
            throw new LogicException('Tracing process isn\'t active.');
        }

        $currentParentTraceId = $this->traceIdContainer->getParentTraceId();

        if ($traceId !== $currentParentTraceId) {
            throw new LogicException(
                "Current parent trace id [$currentParentTraceId] isn't same that stopping [$traceId]."
            );
        }

        $preParentTraceId = array_pop($this->preParentIdsStack);

        $this->traceIdContainer->setParentTraceId(
            parentTraceId: $preParentTraceId
        );

        if (count($this->preParentIdsStack) == 0) {
            $this->started = false;

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

    private function dispatchPushTrace(TraceCreateObject $trace): void
    {
        $this->traceDispatcher->create($trace);
    }

    private function dispatchUpdateTrace(TraceUpdateObject $trace): void
    {
        $this->traceDispatcher->update($trace);
    }
}
