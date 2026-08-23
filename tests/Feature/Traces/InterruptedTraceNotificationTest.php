<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Traces;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use ReflectionProperty;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\OpenTraces;
use SLoggerLaravel\Watchers\Parents\CommandWatcher;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A trace closed by the sweep never reaches the watcher that started it. Unless the
 * watcher is told, its bookkeeping entry stays behind and the next finish takes that
 * stale one instead of its own.
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

    public function testTheSweptTracesEntryIsDroppedFromTheWatcher(): void
    {
        $this->registerWatcher(CommandWatcher::class, null);

        $this->fireStarting('outer');
        $this->fireStarting('inner');

        // two open commands
        self::assertNotNull(
            $this->openCommands()->takeInnermost(
                static fn(array $meta): bool => ($meta['command'] ?? null) === 'inner'
            )
        );

        // put it back and let the sweep take it instead
        $this->fireStarting('inner');

        $this->fireFinished('outer');

        // nothing of either is left: the swept entry was dropped rather than waiting
        // to be taken by the next command's finish
        self::assertNull($this->openCommands()->takeInnermost());
    }

    public function testClosingTheRootTraceLeavesNoParentBehind(): void
    {
        $this->registerWatcher(CommandWatcher::class, null);

        $processor = $this->getApp()->make(Processor::class);

        $this->fireStarting('outer');
        $this->fireFinished('outer');

        // an orphan event recorded afterwards would otherwise be filed as a child of
        // a trace that is already closed
        self::assertNull($processor->currentParentTraceId());
        self::assertFalse($processor->isActive());
    }

    public function testTakingAMatchDropsTheEntriesAboveIt(): void
    {
        $this->registerWatcher(CommandWatcher::class, null);

        $this->fireStarting('outer');
        $this->fireStarting('inner-a');
        $this->fireStarting('inner-b');

        // the outer command finishes while two nested ones never reported doing so
        $this->fireFinished('outer');

        // both are gone, not waiting to be taken by the next command's finish
        self::assertNull($this->openCommands()->takeInnermost());

        // and they were closed as interrupted rather than left open
        self::assertCount(2, $this->dispatcher->findUpdating(tag: Processor::INTERRUPTED_TAG));
    }

    /**
     * The watcher's own bookkeeping, which is where open traces live now.
     */
    private function openCommands(): OpenTraces
    {
        $watcher = $this->getApp()->make(CommandWatcher::class);

        $property = new ReflectionProperty(CommandWatcher::class, 'openCommands');

        /** @var OpenTraces $openCommands */
        $openCommands = $property->getValue($watcher);

        return $openCommands;
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
