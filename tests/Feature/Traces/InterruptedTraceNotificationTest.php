<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Traces;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Traces\TraceScopeResolverInterface;
use SLoggerLaravel\Watchers\Parents\CommandWatcher;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A trace closed by the sweep never reaches the watcher that started it. Unless the
 * watcher is told, its bookkeeping entry stays in the stack and the next finish pops
 * that stale one instead of its own.
 */
class InterruptedTraceNotificationTest extends BaseWatcherTestCase
{
    public function testAWatcherDropsItsEntryForASweptTrace(): void
    {
        $this->registerWatcher(CommandWatcher::class, null);

        $processor = $this->getApp()->make(Processor::class);

        // an outer command, and an inner one that never finishes - the worker was
        // killed inside it, a nested Artisan::call() threw past its own listener
        $this->fireStarting('outer');
        $this->fireStarting('inner');

        $creating = $this->dispatcher->findCreating(type: 'command', status: TraceStatusEnum::Started);

        self::assertCount(2, $creating);

        $outerTraceId = $creating[0]->traceId;

        // the outer command finishes: the sweep closes the inner trace
        $this->fireFinished('outer');

        $interrupted = $this->dispatcher->findUpdating(tag: Processor::INTERRUPTED_TAG);

        self::assertCount(1, $interrupted);

        // and the outer command closed as itself, not as the stale inner entry
        $updated = $this->dispatcher->findUpdating(
            traceId: $outerTraceId,
            status: TraceStatusEnum::Success,
        );

        self::assertCount(1, $updated);

        // nothing is left open
        self::assertFalse($processor->isActive());
    }

    public function testTheSweptTracesEntryIsDroppedFromTheWatchersStack(): void
    {
        $this->registerWatcher(CommandWatcher::class, null);

        $scope = $this->getApp()->make(TraceScopeResolverInterface::class)->current();

        $this->fireStarting('outer');
        $this->fireStarting('inner');

        // two open commands
        $watcher = $this->getApp()->make(CommandWatcher::class);

        self::assertNotNull($scope->popWatcherItemMatching(
            $watcher,
            static fn(mixed $item): bool => is_array($item) && ($item['command'] ?? null) === 'inner'
        ));

        // put it back and let the sweep take it instead
        $this->fireStarting('inner');

        $this->fireFinished('outer');

        // nothing of either is left: the swept entry was dropped rather than waiting
        // to be popped by the next command's finish
        self::assertNull($scope->popWatcherItemMatching(
            $watcher,
            static fn(mixed $item): bool => true
        ));
    }

    public function testClosingTheRootTraceLeavesNoParentBehind(): void
    {
        $this->registerWatcher(CommandWatcher::class, null);

        $processor = $this->getApp()->make(Processor::class);

        $this->fireStarting('outer');
        $this->fireFinished('outer');

        $container = $this->getApp()->make(TraceIdContainer::class);

        self::assertNull($container->getParentTraceId());

        // and not as its own pre-parent either: an orphan event recorded afterwards
        // would be filed as a child of a trace that is already closed
        self::assertNull($container->getPreParentTraceId());
        self::assertFalse($processor->isActive());
    }

    public function testPoppingAMatchDropsTheEntriesAboveIt(): void
    {
        $this->registerWatcher(CommandWatcher::class, null);

        $scope = $this->getApp()->make(TraceScopeResolverInterface::class)->current();

        $watcher = $this->getApp()->make(CommandWatcher::class);

        $this->fireStarting('outer');
        $this->fireStarting('inner-a');
        $this->fireStarting('inner-b');

        // the outer command finishes while two nested ones never reported doing so
        $this->fireFinished('outer');

        // both are gone, not waiting to be popped by the next command's finish
        self::assertNull($scope->popWatcherItemMatching(
            $watcher,
            static fn(mixed $item): bool => true
        ));

        // and they were closed as interrupted rather than left open
        self::assertCount(2, $this->dispatcher->findUpdating(tag: Processor::INTERRUPTED_TAG));
    }

    private function fireStarting(string $command): void
    {
        Event::dispatch(
            new CommandStarting($command, new ArrayInput([]), new BufferedOutput())
        );
    }

    private function fireFinished(string $command): void
    {
        Event::dispatch(
            new CommandFinished($command, new ArrayInput([]), new BufferedOutput(), 0)
        );
    }
}
