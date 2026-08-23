<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Profiling;

use Fiber;
use SLoggerLaravel\Configs\WatchersConfig;
use SLoggerLaravel\Profiling\AbstractProfiling;
use SLoggerLaravel\Profiling\Dto\ProfilingObjects;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Tests\Feature\Traces\FakeCoroutineScopeResolver;
use SLoggerLaravel\Traces\ProcessTraceScopeResolver;
use SLoggerLaravel\Traces\TraceScopeResolverInterface;

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

    public function testProfilingIsSkippedUnderAConcurrentRuntime(): void
    {
        // a runtime that interleaves coroutines says so through its resolver. Swoole
        // coroutines are not Fibers, so asking Fiber::getCurrent() answered "not
        // concurrent" for the one runtime this is meant to protect against
        $profiler = $this->makeProfiler(new FakeCoroutineScopeResolver());

        $profiler->start('in-coroutine');

        self::assertNull($profiler->stop('in-coroutine'));
    }

    public function testALibraryRunningInAFiberDoesNotDisableProfiling(): void
    {
        $profiler = $this->makeProfiler();

        // amphp, Revolt, Reverb: under a plain process these run ordinary code in a
        // Fiber, and profiling used to go quiet for as long as they did
        $fiber = new Fiber(static function () use ($profiler): void {
            $profiler->start('in-fiber');
        });

        $fiber->start();

        self::assertInstanceOf(ProfilingObjects::class, $profiler->stop('in-fiber'));
    }

    private function makeProfiler(?TraceScopeResolverInterface $scopeResolver = null): AbstractProfiling
    {
        $this->getApp()['config']->set('slogger.profiling.enabled', true);

        return new class(new WatchersConfig(), $scopeResolver ?? new ProcessTraceScopeResolver()) extends AbstractProfiling {
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
