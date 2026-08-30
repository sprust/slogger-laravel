<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Concurrency;

use Fiber;
use SLoggerLaravel\Processor;

/**
 * The pause exists so that pushing a trace does not get traced in turn. Held per
 * process, it silenced everybody: while one request was inside handleWithoutTracing()
 * - which every dispatch goes through - the watchers of every other request in flight
 * returned early and recorded nothing.
 *
 * The symptom was eight simultaneous requests producing one trace between them.
 */
class PausedIsolationTest extends BaseConcurrencyTestCase
{
    public function testOneUnitPausedDoesNotSilenceAnother(): void
    {
        $processor = $this->getApp()->make(Processor::class);

        $seen = [];

        $this->interleave([
            static function () use ($processor, &$seen): void {
                $processor->handleWithoutTracing(
                    static function () use ($processor, &$seen): void {
                        // suspended in the middle of a dispatch, which is where a
                        // coroutine runtime actually hands control over
                        Fiber::suspend();

                        $seen['paused inside'] = $processor->isPaused();
                    }
                );

                $seen['paused after'] = $processor->isPaused();
            },
            static function () use ($processor, &$seen): void {
                $seen['neighbour'] = $processor->handleWatcher(static fn(): string => 'ran');

                $seen['neighbour paused'] = $processor->isPaused();
            },
        ]);

        self::assertTrue($seen['paused inside'], 'the pause holds for the unit that took it');
        self::assertFalse($seen['paused after'], 'and is given back when it is done');

        self::assertSame('ran', $seen['neighbour'], 'a neighbour must not be silenced by it');
        self::assertFalse($seen['neighbour paused']);
    }
}
