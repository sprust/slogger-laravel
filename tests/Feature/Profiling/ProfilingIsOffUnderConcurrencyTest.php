<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Profiling;

use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Context\ArrayTraceContext;
use SLoggerLaravel\Context\FiberTraceContext;
use SLoggerLaravel\Context\TraceContextInterface;
use SLoggerLaravel\Profiling\AbstractProfiling;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

/**
 * A profiler measures the process, and a process running several units of work at once
 * has nothing to attribute a run to. Worse, a unit that ends without stopping keeps the
 * profiler owned for good: `xhprof_disable()` is never reached, every later trace is
 * skipped, and the extension goes on instrumenting every call the process makes.
 */
class ProfilingIsOffUnderConcurrencyTest extends BaseTestCase
{
    public function testItRunsWhereUnitsOfWorkDoNotOverlap(): void
    {
        $profiler = $this->makeProfiler(new ArrayTraceContext());

        $profiler->start('trace-1');

        self::assertSame(1, $profiler->startCount);
    }

    public function testItStaysOffWhereTheyDo(): void
    {
        $profiler = $this->makeProfiler(new FiberTraceContext());

        $profiler->start('trace-1');

        self::assertSame(0, $profiler->startCount, 'the config asked for it, and it is still off');

        self::assertNull($profiler->stop('trace-1'));
    }

    private function makeProfiler(TraceContextInterface $context): RecordingProfiler
    {
        config()->set('slogger.profiling.enabled', true);

        return new RecordingProfiler(new WatchersConfig(), $context);
    }
}

class RecordingProfiler extends AbstractProfiling
{
    public int $startCount = 0;

    protected function onStart(): bool
    {
        $this->startCount++;

        return true;
    }

    protected function onStop(): ?ProfilingObjects
    {
        return new ProfilingObjects(mainCaller: 'main()');
    }
}
