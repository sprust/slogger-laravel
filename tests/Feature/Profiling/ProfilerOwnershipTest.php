<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Profiling;

use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Context\ArrayTraceContext;
use SLoggerLaravel\Profiling\AbstractProfiling;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class ProfilerOwnershipTest extends BaseTestCase
{
    public function testTheProfileGoesToTheTraceThatStartedIt(): void
    {
        $profiler = $this->makeProfiler();

        $profiler->start('outer');
        $profiler->start('inner');

        // the inner trace stops first and used to call xhprof_disable(), walking off
        // with the outer trace's profile and leaving the outer one with null
        self::assertNull($profiler->stop('inner'));

        $outer = $profiler->stop('outer');

        self::assertInstanceOf(ProfilingObjects::class, $outer);
    }

    public function testAnInterruptedTraceHandsTheProfileBack(): void
    {
        $profiler = $this->makeProfiler();

        $profiler->start('interrupted');

        // the sweep closes it without asking for a profile; the profiler must not
        // stay owned by a trace that is gone
        $profiler->release('interrupted');

        $profiler->start('next');

        self::assertInstanceOf(ProfilingObjects::class, $profiler->stop('next'));
    }

    private function makeProfiler(): AbstractProfiling
    {
        $this->getApp()['config']->set('slogger.profiling.enabled', true);

        return new class(new WatchersConfig(), new ArrayTraceContext()) extends AbstractProfiling {
            protected function onStart(): bool
            {
                return true;
            }

            protected function onStop(): ProfilingObjects
            {
                return new ProfilingObjects(mainCaller: 'main()');
            }
        };
    }
}
