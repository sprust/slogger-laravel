<?php

namespace SLoggerLaravel\Profiling;

use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Context\ArrayTraceContext;
use SLoggerLaravel\Context\TraceContextInterface;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;

abstract class AbstractProfiling
{
    private bool $profilingEnabled;

    /**
     * A profiler measures the process, so one run is in flight at a time and the
     * profile goes to whoever started it - not to a nested `Artisan::call()`.
     */
    private ?string $ownerTraceId = null;

    abstract protected function onStart(): bool;

    abstract protected function onStop(): ?ProfilingObjects;

    public function __construct(
        private readonly WatchersConfig $loggerConfig,
        TraceContextInterface $context
    ) {
        // Off wherever units of work do not run one at a time, whatever the config
        // says. A profiler measures the process, and there is one process however many
        // units share it: the run a unit starts covers what all the others did, and a
        // unit that ends without stopping leaves the profiler running with nobody left
        // to turn it off - for the rest of the process, which then profiles nothing
        // else and instruments every call it makes.
        $this->profilingEnabled = $this->loggerConfig->profilingEnabled()
            && $context instanceof ArrayTraceContext;
    }

    public function start(string $traceId): void
    {
        if (!$this->profilingEnabled) {
            return;
        }

        if (!is_null($this->ownerTraceId)) {
            // an outer trace is already being profiled; this is part of it
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
     * Gives up a profile whose trace the sweep is closing - otherwise the profiler
     * stays owned by a trace that is already gone.
     */
    public function release(string $traceId): void
    {
        $this->stop($traceId);
    }
}
