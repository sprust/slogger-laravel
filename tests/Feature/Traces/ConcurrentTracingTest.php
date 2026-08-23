<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Traces;

use Illuminate\Support\Carbon;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Helpers\TraceDataComplementer;
use SLoggerLaravel\Processor;
use SLoggerLaravel\ServiceProvider;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Traces\ProcessTraceScopeResolver;
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
        $this->getApp()->forgetInstance(TraceDataComplementer::class);
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

    public function testADetachedTraceCanBeClosedFromAnotherCoroutine(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $traceId = null;

        // a coroutine sends an outbound request and suspends waiting for it
        $sender = $this->resolver->spawn(static function () use ($processor, &$traceId): void {
            $traceId = $processor->startAndGetDetachedTraceId(
                type: 'http-client',
                tags: [],
                data: [],
                loggedAt: Carbon::now(),
            );

            \Fiber::suspend();
        });

        $sender->start();

        self::assertIsString($traceId);

        // the runtime resolves the promise elsewhere - another coroutine, or the
        // scheduler itself. A per-scope map would leave this find nothing, report
        // "already closed", and let the sender sweep a request that succeeded
        $processor->stop(
            traceId: $traceId,
            status: TraceStatusEnum::Success->value,
            tags: ['https://example.test'],
            data: ['response' => ['status_code' => 200]],
            duration: 1.0,
            parentLoggedAt: Carbon::now(),
        );

        $sender->resume();

        $updating = $this->dispatcher->findUpdating(traceId: $traceId);

        self::assertCount(1, $updating);
        self::assertSame(TraceStatusEnum::Success->value, $updating[0]->status);
        self::assertNotContains(Processor::INTERRUPTED_TAG, $updating[0]->tags ?? []);
        self::assertSame(200, ($updating[0]->data ?? [])['response']['status_code']);
    }

    public function testAFinishedCoroutinesScopeIsNotAdoptedByTheNextOne(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $abandonedTraceId = null;

        // a coroutine that starts a trace, never closes it, and is then collected
        $abandoned = $this->resolver->spawn(static function () use ($processor, &$abandonedTraceId): void {
            $abandonedTraceId = $processor->startAndGetTraceId(
                type: 'first',
                tags: [],
                data: [],
                loggedAt: Carbon::now(),
                customParentTraceId: null,
            );
        });

        $abandoned->start();

        unset($abandoned);

        gc_collect_cycles();

        self::assertIsString($abandonedTraceId);

        // PHP hands a collected object's spl_object_id to the next one allocated, so
        // a resolver keyed by it would let this coroutine adopt the abandoned scope:
        // a stranger's stack, its parent trace id, and its open traces to sweep
        $adopted = null;

        $fresh = $this->resolver->spawn(static function () use ($processor, &$adopted): void {
            $adopted = [
                'active' => $processor->isActive(),
                'parent' => $processor->startAndGetTraceId(
                    type: 'second',
                    tags: [],
                    data: [],
                    loggedAt: Carbon::now(),
                    customParentTraceId: null,
                ),
            ];
        });

        $fresh->start();

        self::assertIsArray($adopted);

        // a brand new unit of work starts clean
        self::assertFalse($adopted['active']);
        self::assertNotSame($abandonedTraceId, $adopted['parent']);

        $created = $this->dispatcher->findCreating(type: 'second');

        self::assertCount(1, $created);
        self::assertNull($created[0]->parentTraceId);
    }

    public function testAnInnerTraceClosingDoesNotSilenceTheRestOfTheCoroutine(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $parentTraceId = $processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null,
        );

        $seen = [];

        $child = $this->resolver->spawn(static function () use ($processor, &$seen): void {
            $seen['on_entry'] = $processor->isActive();

            // a nested unit inside the coroutine - Artisan::call(), a sync job
            $nested = $processor->startAndGetTraceId(
                type: 'nested',
                tags: [],
                data: [],
                loggedAt: Carbon::now(),
                customParentTraceId: null,
            );

            $processor->stop(
                traceId: $nested,
                status: TraceStatusEnum::Success->value,
                tags: null,
                data: null,
                duration: 1.0,
                parentLoggedAt: Carbon::now(),
            );

            // the coroutine's own stack is empty again, but the trace it inherited is
            // still open: clearing the parent here dropped every child trace after it
            $seen['after_nested'] = $processor->isActive();

            $processor->push(
                type: 'database',
                status: TraceStatusEnum::Success->value,
                data: [],
            );
        });

        $child->start();

        self::assertTrue($seen['on_entry']);
        self::assertTrue($seen['after_nested']);

        $created = $this->dispatcher->findCreating(type: 'database');

        self::assertCount(1, $created);
        self::assertSame($parentTraceId, $created[0]->parentTraceId);
    }

    public function testOneCoroutineDoesNotSweepAnothersOwnerlessDetachedTrace(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $traceId = null;

        // an outbound call made with no trace open around it, still in flight
        $sender = $this->resolver->spawn(static function () use ($processor, &$traceId): void {
            $traceId = $processor->startAndGetDetachedTraceId(
                type: 'http-client',
                tags: [],
                data: [],
                loggedAt: Carbon::now(),
            );

            \Fiber::suspend();
        });

        $sender->start();

        // an unrelated coroutine opens and closes its own trace
        $other = $this->resolver->spawn(static function () use ($processor): void {
            $own = $processor->startAndGetTraceId(
                type: 'request',
                tags: [],
                data: [],
                loggedAt: Carbon::now(),
                customParentTraceId: null,
            );

            $processor->stop(
                traceId: $own,
                status: TraceStatusEnum::Success->value,
                tags: null,
                data: null,
                duration: 1.0,
                parentLoggedAt: Carbon::now(),
            );
        });

        $other->start();

        self::assertIsString($traceId);

        // the sender is still waiting; nobody else may declare its request interrupted
        self::assertCount(0, $this->dispatcher->findUpdating(traceId: $traceId));

        $sender->resume();
    }

    public function testAdditionalTraceDataBelongsToItsUnitOfWork(): void
    {
        $complementer = $this->getApp()->make(TraceDataComplementer::class);

        // annotated because inject() fills the array by reference, and on Laravel 10
        // the container's return type is not specific enough for the analyser to see
        // the call at all - it then reads every lookup below as a lookup in an array
        // it knows to be empty
        /** @var array<string, array<string, mixed>|null> $seen */
        $seen = [];

        $first = $this->resolver->spawn(static function () use ($complementer, &$seen): void {
            $complementer->add('user_id', 1);

            /** @var array<string, mixed> $data */
            $data = [];

            $complementer->inject($data);

            $seen['first'] = $data[TraceDataComplementer::ADDITIONAL_KEY] ?? null;
        });

        $second = $this->resolver->spawn(static function () use ($complementer, &$seen): void {
            /** @var array<string, mixed> $data */
            $data = [];

            $complementer->inject($data);

            $seen['second'] = $data[TraceDataComplementer::ADDITIONAL_KEY] ?? null;
        });

        $first->start();
        $second->start();

        // held on the complementer, one unit's value stamped every other one - under
        // Octane that means every later request in the same worker
        self::assertSame(['user_id' => 1], $seen['first']);
        self::assertNull($seen['second']);
    }

    public function testAnOwnerlessDetachedTraceOfADeadCoroutineIsEventuallySwept(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $traceId = null;

        $abandoned = $this->resolver->spawn(static function () use ($processor, &$traceId): void {
            $traceId = $processor->startAndGetDetachedTraceId(
                type: 'http-client',
                tags: [],
                data: [],
                // started long enough ago that nothing is still waiting on it
                loggedAt: Carbon::now()->subSeconds(Processor::DETACHED_TRACE_TTL_SECONDS + 1),
            );
        });

        $abandoned->start();

        unset($abandoned);

        self::assertIsString($traceId);

        // the scope that started it is gone, so nothing would ever sweep it and the
        // map would grow for as long as the process lives
        $other = $this->resolver->spawn(static function () use ($processor): void {
            $own = $processor->startAndGetTraceId(
                type: 'request',
                tags: [],
                data: [],
                loggedAt: Carbon::now(),
                customParentTraceId: null,
            );

            $processor->stop(
                traceId: $own,
                status: TraceStatusEnum::Success->value,
                tags: null,
                data: null,
                duration: 1.0,
                parentLoggedAt: Carbon::now(),
            );
        });

        $other->start();

        $updating = $this->dispatcher->findUpdating(traceId: $traceId);

        self::assertCount(1, $updating);
        self::assertContains(Processor::INTERRUPTED_TAG, $updating[0]->tags ?? []);
    }

    public function testADetachedTraceOfAKilledCoroutineIsSweptByAge(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $detachedTraceId = null;

        // a coroutine opens a trace of its own, sends an outbound call under it, and
        // is then killed: it will never close either, and nothing else knows the
        // owner's id to close the call by
        $killed = $this->resolver->spawn(static function () use ($processor, &$detachedTraceId): void {
            $processor->startAndGetTraceId(
                type: 'request',
                tags: [],
                data: [],
                loggedAt: Carbon::now(),
                customParentTraceId: null,
            );

            $detachedTraceId = $processor->startAndGetDetachedTraceId(
                type: 'http-client',
                tags: [],
                data: [],
                loggedAt: Carbon::now(),
            );

            \Fiber::suspend();
        });

        $killed->start();

        self::assertIsString($detachedTraceId);

        // still in flight as far as anyone can tell
        $this->runOneCoroutineTrace($processor);

        self::assertCount(0, $this->dispatcher->findUpdating(traceId: $detachedTraceId));

        Carbon::setTestNow(Carbon::now()->addSeconds(Processor::DETACHED_TRACE_TTL_SECONDS + 1));

        try {
            $this->runOneCoroutineTrace($processor);
        } finally {
            Carbon::setTestNow();
        }

        // age is the only thing that reaches it: the entry used to sit in the map for
        // the life of the process, and the trace stayed `started` on the receiver
        $updating = $this->dispatcher->findUpdating(traceId: $detachedTraceId);

        self::assertCount(1, $updating);
        self::assertContains(Processor::INTERRUPTED_TAG, $updating[0]->tags ?? []);
    }

    public function testEveryCoroutineGetsAnIdOfItsOwn(): void
    {
        // spl_object_id is handed to the next allocation, and three fibers created in
        // sequence routinely share one. A scope left behind by a finished coroutine
        // would then be adopted by an unrelated new one
        $ids = [];

        $splIds = [];

        for ($i = 0; $i < 5; $i++) {
            $fiber = $this->resolver->spawn(static fn() => null);

            $ids[]    = $this->resolver->publicOwnerIdOf($fiber);
            $splIds[] = spl_object_id($fiber);

            unset($fiber);

            gc_collect_cycles();
        }

        self::assertCount(5, array_unique($ids), 'owner ids must never repeat');

        // and this is the hazard being guarded against, demonstrated
        self::assertLessThan(5, count(array_unique($splIds)), 'spl_object_id was expected to repeat');
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
        // runtime binds this itself, which is what this test's setUp() does
        $app = $this->getApp();

        $app->forgetInstance(TraceScopeResolverInterface::class);

        (new ServiceProvider($app))->register();

        self::assertInstanceOf(
            ProcessTraceScopeResolver::class,
            $app->make(TraceScopeResolverInterface::class)
        );
    }

    public function testAResolverBoundBeforeTheProviderRunsIsNotOverwritten(): void
    {
        // a package's providers register before the application's, so a binding made
        // in a provider wins on its own. One made earlier - in `bootstrap/app.php`,
        // or by whatever bootstraps a concurrent runtime - would not, and a plain
        // singleton() here would silently put it back on process-wide state
        $app = $this->getApp();

        $app->forgetInstance(TraceScopeResolverInterface::class);

        $own = new FakeCoroutineScopeResolver();

        $app->singleton(TraceScopeResolverInterface::class, static fn() => $own);

        (new ServiceProvider($app))->register();

        self::assertSame($own, $app->make(TraceScopeResolverInterface::class));
    }

    private function runOneCoroutineTrace(Processor $processor): void
    {
        $coroutine = $this->resolver->spawn(static function () use ($processor): void {
            $traceId = $processor->startAndGetTraceId(
                type: 'job',
                tags: [],
                data: [],
                loggedAt: Carbon::now(),
                customParentTraceId: null,
            );

            $processor->stop(
                traceId: $traceId,
                status: TraceStatusEnum::Success->value,
                tags: null,
                data: null,
                duration: 1.0,
                parentLoggedAt: Carbon::now(),
            );
        });

        $coroutine->start();
    }
}
