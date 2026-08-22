<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Traces;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
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
