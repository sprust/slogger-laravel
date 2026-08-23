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
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Watchers\Parents\CommandWatcher;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A swept trace never reaches the watcher that started it: unless told, its entry
 * stays behind and the next finish takes that stale one.
 */
class InterruptedTraceNotificationTest extends BaseWatcherTestCase
{
    public function testAWatcherDropsItsEntryForASweptTrace(): void
    {
        $this->registerWatcher(CommandWatcher::class, null);

        $processor = $this->getApp()->make(Processor::class);

        // an inner command that never finishes: killed, or thrown past its listener
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
        self::assertCount(2, $this->openCommands());

        $this->fireFinished('outer');

        // nothing of either is left: the swept entry was dropped, not left waiting
        self::assertSame([], $this->openCommands());
    }

    public function testClosingTheRootTraceLeavesNoParentBehind(): void
    {
        $this->registerWatcher(CommandWatcher::class, null);

        $processor = $this->getApp()->make(Processor::class);

        $this->fireStarting('outer');
        $this->fireFinished('outer');

        // an orphan event afterwards would be filed under a trace already closed
        self::assertNull($this->getApp()->make(TraceIdContainer::class)->getParentTraceId());
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
        self::assertSame([], $this->openCommands());

        // and they were closed as interrupted rather than left open
        self::assertCount(2, $this->dispatcher->findUpdating(tag: Processor::INTERRUPTED_TAG));
    }

    /**
     * The watcher's own bookkeeping of the commands it has open.
     *
     * @return list<array{trace_id: string, command: string|null, started_at: mixed}>
     */
    private function openCommands(): array
    {
        $watcher = $this->getApp()->make(CommandWatcher::class);

        /** @var list<array{trace_id: string, command: string|null, started_at: mixed}> $commands */
        $commands = (new ReflectionProperty(CommandWatcher::class, 'commands'))->getValue($watcher);

        return $commands;
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
