<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers;

use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\OpenTraces;

/**
 * One way for every parent watcher to remember what it has open. Before this there
 * were three: a stack in the trace scope, a map on the watcher, and a map that
 * repeated its own key inside the value - and only two of them cleaned up after a
 * trace the processor had swept.
 */
class OpenTracesTest extends BaseTestCase
{
    public function testAnEntryComesBackWithItsTraceIdAndOnlyOnce(): void
    {
        $open = new OpenTraces();

        $open->open('trace-1', ['started_at' => 'now']);

        self::assertSame(
            ['trace_id' => 'trace-1', 'started_at' => 'now'],
            $open->take('trace-1')
        );

        self::assertNull($open->take('trace-1'));
        self::assertSame(0, $open->count());
    }

    public function testTheInnermostIsTheOneOpenedLast(): void
    {
        $open = new OpenTraces();

        $open->open('outer');
        $open->open('inner');

        self::assertSame(['trace_id' => 'inner'], $open->takeInnermost());
        self::assertSame(['trace_id' => 'outer'], $open->takeInnermost());
        self::assertNull($open->takeInnermost());
    }

    public function testTakingAMatchDropsWhateverWasOpenedAfterIt(): void
    {
        $open = new OpenTraces();

        $open->open('outer', ['command' => 'outer']);
        $open->open('inner-a', ['command' => 'inner-a']);
        $open->open('inner-b', ['command' => 'inner-b']);

        // the outer command finishes while two nested ones never reported doing so.
        // Taking the innermost blindly would close `inner-b` in the outer command's
        // name and leave the outer trace open forever
        $taken = $open->takeInnermost(
            static fn(array $meta): bool => $meta['command'] === 'outer'
        );

        self::assertSame(['trace_id' => 'outer', 'command' => 'outer'], $taken);

        // and what sat above it is abandoned by definition - the processor sweeps
        // those traces as interrupted
        self::assertSame(0, $open->count());
    }

    public function testASweptTraceIsForgottenRatherThanTakenLater(): void
    {
        $open = new OpenTraces();

        $open->open('swept');
        $open->open('mine');

        $open->forget('swept');

        self::assertSame(['trace_id' => 'mine'], $open->takeInnermost());
        self::assertNull($open->takeInnermost());
    }
}
