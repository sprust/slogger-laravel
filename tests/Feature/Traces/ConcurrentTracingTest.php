<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Traces;

use Illuminate\Support\Carbon;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Processor;
use SLoggerLaravel\ServiceProvider;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Traces\ProcessTraceScopeResolver;
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Traces\TraceScopeResolverInterface;

/**
 * A concurrent runtime interleaves coroutines in one process and switches between
 * them on every async call. Every piece of state the package keeps per unit of work
 * therefore has to be per coroutine: with one shared stack, a coroutine resuming
 * from an async call finds the parent trace id of whichever one ran while it was
 * suspended, and closes that one's trace instead of its own.
 *
 * The package binds the process resolver and ships no runtime integration; what is
 * tested here is that its state follows whatever resolver an application binds.
 */
class ConcurrentTracingTest extends BaseWatcherTestCase
{
    private FakeCoroutineScopeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new FakeCoroutineScopeResolver();

        $this->getApp()->instance(TraceScopeResolverInterface::class, $this->resolver);

        // the singletons captured the previous resolver
        $this->getApp()->forgetInstance(Processor::class);
        $this->getApp()->forgetInstance(TraceIdContainer::class);
    }

    public function testTwoCoroutinesDoNotStealEachOthersTraces(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $traceIds = [];

        $coroutine = function (string $name) use ($processor, &$traceIds): callable {
            return static function () use ($processor, $name, &$traceIds): void {
                $traceIds[$name] = $processor->startAndGetTraceId(
                    type: $name,
                    tags: [],
                    data: [],
                    loggedAt: Carbon::now(),
                    customParentTraceId: null,
                );

                // an async call: the scheduler runs the other coroutine here
                \Fiber::suspend();

                $processor->stop(
                    traceId: $traceIds[$name],
                    status: TraceStatusEnum::Success->value,
                    tags: null,
                    data: null,
                    duration: 1.0,
                    parentLoggedAt: Carbon::now(),
                );
            };
        };

        $first  = $this->resolver->spawn($coroutine('first'));
        $second = $this->resolver->spawn($coroutine('second'));

        $first->start();
        $second->start();
        $first->resume();
        $second->resume();

        self::assertNotSame($traceIds['first'], $traceIds['second']);

        foreach (['first', 'second'] as $name) {
            $created = $this->dispatcher->findCreating(type: $name);

            self::assertCount(1, $created);

            // each coroutine closed its own trace, and closed it as a success -
            // sharing one stack made them close each other's, out of order
            $updated = $this->dispatcher->findUpdating(
                traceId: $traceIds[$name],
                status: TraceStatusEnum::Success,
            );

            self::assertCount(1, $updated);
        }

        // nothing was left open, and nothing was swept as interrupted
        self::assertCount(0, $this->dispatcher->findUpdating(tag: Processor::INTERRUPTED_TAG));
    }

    public function testANestedCoroutineHangsUnderTheTraceThatSpawnedIt(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $parentTraceId = $processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null,
        );

        $child = $this->resolver->spawn(static function () use ($processor): void {
            // an outbound call made inside the coroutine the job spawned
            $processor->push(
                type: 'http-client',
                status: TraceStatusEnum::Success->value,
                data: [],
            );
        });

        $child->start();

        $created = $this->dispatcher->findCreating(type: 'http-client');

        self::assertCount(1, $created);

        // inherited from the spawning coroutine: its own stack, but the same parent
        self::assertSame($parentTraceId, $created[0]->parentTraceId);
    }

    public function testACoroutineDoesNotPushOntoItsParentsStack(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $parentTraceId = $processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null,
        );

        $child = $this->resolver->spawn(static function () use ($processor): void {
            $processor->startAndGetTraceId(
                type: 'nested',
                tags: [],
                data: [],
                loggedAt: Carbon::now(),
                customParentTraceId: null,
            );

            // deliberately left open
        });

        $child->start();

        // the parent's own stack is untouched, so it closes normally
        $processor->stop(
            traceId: $parentTraceId,
            status: TraceStatusEnum::Success->value,
            tags: null,
            data: null,
            duration: 1.0,
            parentLoggedAt: Carbon::now(),
        );

        $updated = $this->dispatcher->findUpdating(
            traceId: $parentTraceId,
            status: TraceStatusEnum::Success,
        );

        self::assertCount(1, $updated);

        // and it did not sweep the coroutine's open trace as one of its own children
        self::assertCount(0, $this->dispatcher->findUpdating(tag: Processor::INTERRUPTED_TAG));
    }

    public function testPausingInOneCoroutineDoesNotSilenceAnother(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $paused = $this->resolver->spawn(static function () use ($processor): void {
            $processor->handleWithoutTracing(static function (): void {
                // an async call inside a paused section: the scheduler switches away
                // while this coroutine holds the pause
                \Fiber::suspend();
            });
        });

        $paused->start();

        $watcherRan = false;

        // a different coroutine, running while the first one sits paused. A
        // process-wide pause flag would have silenced its watchers entirely
        $other = $this->resolver->spawn(static function () use ($processor, &$watcherRan): void {
            $processor->handleWatcher(static function () use (&$watcherRan): void {
                $watcherRan = true;
            });
        });

        $other->start();

        self::assertTrue($watcherRan);

        $paused->resume();
    }

    public function testTheDefaultResolverKeepsOneScopePerProcess(): void
    {
        // nothing changes for FPM, queue:work or an artisan command
        $resolver = new ProcessTraceScopeResolver();

        self::assertFalse($resolver->isConcurrent());
        self::assertSame($resolver->current(), $resolver->current());
    }

    public function testThePackageBindsTheProcessResolverAndKnowsOfNoRuntime(): void
    {
        // the package ships no runtime integration: an application on a concurrent
        // runtime rebinds this itself, which is what this test's setUp() does
        $app = $this->getApp();

        $app->forgetInstance(TraceScopeResolverInterface::class);

        (new ServiceProvider($app))->register();

        self::assertInstanceOf(
            ProcessTraceScopeResolver::class,
            $app->make(TraceScopeResolverInterface::class)
        );
    }
}
