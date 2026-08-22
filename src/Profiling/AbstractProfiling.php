<?php

namespace SLoggerLaravel\Profiling;

use Fiber;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;

abstract class AbstractProfiling
{
    private bool $profilingEnabled;
    private bool $profilingStarted = false;

    /**
     * The trace the running profile belongs to.
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
        private readonly WatchersConfig $loggerConfig
    ) {
        $this->profilingEnabled = $this->loggerConfig->profilingEnabled();
    }

    public function start(string $traceId): void
    {
        if (!$this->profilingEnabled) {
            return;
        }

        if (!is_null(Fiber::getCurrent())) {
            // a profiler is process-wide: it measures whatever the process does
            // between start and stop, and under a concurrent runtime that is every
            // coroutine that ran in between, attributed to whichever trace happened
            // to stop first. Wrong numbers are worse than none
            return;
        }

        if (!is_null($this->ownerTraceId)) {
            // an outer trace is already being profiled; this one is part of what it
            // measures
            return;
        }

        $this->profilingStarted = $this->onStart();

        if ($this->profilingStarted) {
            $this->ownerTraceId = $traceId;
        }
    }

    public function stop(string $traceId): ?ProfilingObjects
    {
        if (!$this->profilingStarted || !$this->profilingEnabled) {
            return null;
        }

        if ($this->ownerTraceId !== $traceId) {
            return null;
        }

        $profilingObjects = $this->onStop();

        $this->profilingStarted = false;
        $this->ownerTraceId     = null;

        return $profilingObjects;
    }

    /**
     * Gives up a profile whose trace is being closed without asking for it - an
     * interrupted trace is stopped by the sweep, which reports no profiling data.
     * Without this the profiler would stay owned by a trace that is already gone.
     */
    public function release(string $traceId): void
    {
        if ($this->ownerTraceId !== $traceId) {
            return;
        }

        $this->stop($traceId);
    }
}
