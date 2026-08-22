<?php

namespace SLoggerLaravel\Profiling;

use Fiber;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;

abstract class AbstractProfiling
{
    private bool $profilingEnabled;
    private bool $profilingStarted = false;

    abstract protected function onStart(): bool;

    abstract protected function onStop(): ?ProfilingObjects;

    public function __construct(
        private readonly WatchersConfig $loggerConfig
    ) {
        $this->profilingEnabled = $this->loggerConfig->profilingEnabled();
    }

    public function start(): void
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

        $this->profilingStarted = $this->onStart();
    }

    public function stop(): ?ProfilingObjects
    {
        if (!$this->profilingStarted || !$this->profilingEnabled) {
            return null;
        }

        $profilingObjects = $this->onStop();

        $this->profilingStarted = false;

        return $profilingObjects;
    }
}
