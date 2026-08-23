<?php

namespace SLoggerLaravel\Profiling;

use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;
use SLoggerLaravel\Traces\TraceScopeResolverInterface;

abstract class AbstractProfiling
{
    private bool $profilingEnabled;

    /**
     * The trace the running profile belongs to, and null while nothing is running.
     *
     * A profiler measures the process, so only one run can be in flight at a time.
     * With nested parent traces - `Artisan::call()` from inside a command - the
     * inner one used to call xhprof_disable() and walk off with the outer trace's
     * profile, leaving the outer one with null. The profile now goes to whoever
     * started it.
     */
    private ?string $ownerTraceId = null;

    abstract protected function onStart(): bool;

    abstract protected function onStop(): ?ProfilingObjects;

    public function __construct(
        private readonly WatchersConfig $loggerConfig,
        private readonly TraceScopeResolverInterface $scopeResolver
    ) {
        $this->profilingEnabled = $this->loggerConfig->profilingEnabled();
    }

    public function start(string $traceId): void
    {
        if (!$this->profilingEnabled) {
            return;
        }

        if ($this->scopeResolver->isConcurrent()) {
            // a profiler is process-wide: it measures whatever the process does
            // between start and stop, and under a concurrent runtime that is every
            // coroutine that ran in between, attributed to whichever trace happened
            // to stop first. Wrong numbers are worse than none.
            //
            // Asked of the resolver rather than of Fiber::getCurrent(): a Swoole
            // coroutine is not a Fiber, so a runtime with a resolver of its own -
            // which is exactly the case this guards - profiled the whole interleaved
            // lot anyway; and under a plain process any library that runs code in a
            // Fiber (amphp, Reverb) silently switched profiling off while it did
            return;
        }

        if (!is_null($this->ownerTraceId)) {
            // an outer trace is already being profiled; this one is part of what it
            // measures
            return;
        }

        if ($this->onStart()) {
            $this->ownerTraceId = $traceId;
        }
    }

    public function stop(string $traceId): ?ProfilingObjects
    {
        if ($this->ownerTraceId !== $traceId) {
            return null;
        }

        $this->ownerTraceId = null;

        return $this->onStop();
    }

    /**
     * Gives up a profile whose trace is being closed without asking for it - an
     * interrupted trace is stopped by the sweep, which reports no profiling data.
     * Without this the profiler would stay owned by a trace that is already gone.
     */
    public function release(string $traceId): void
    {
        $this->stop($traceId);
    }
}
