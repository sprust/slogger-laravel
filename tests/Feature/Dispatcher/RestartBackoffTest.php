<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher;

use ReflectionClass;
use SLoggerLaravel\Dispatcher\Dispatcher;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

/**
 * A worker that dies on boot was restarted once a second forever - roughly 86k
 * restarts and 86k state-file writes a day, with nothing to show for them.
 */
class RestartBackoffTest extends BaseTestCase
{
    public function testRepeatedFailuresBackOffAndACleanRunResets(): void
    {
        $dispatcher = $this->getApp()->make(Dispatcher::class);

        $delayFor = (new ReflectionClass(Dispatcher::class))->getMethod('restartDelayFor');

        // an occasional crash restarts straight away
        self::assertSame(0, $delayFor->invoke($dispatcher, 0));
        self::assertSame(0, $delayFor->invoke($dispatcher, 0));
        self::assertSame(0, $delayFor->invoke($dispatcher, 0));

        // a boot loop does not
        $delays = [
            $delayFor->invoke($dispatcher, 0),
            $delayFor->invoke($dispatcher, 0),
            $delayFor->invoke($dispatcher, 0),
        ];

        self::assertSame([2, 4, 8], $delays);

        // slots are counted apart: one worker crashing must not slow another's restart
        self::assertSame(0, $delayFor->invoke($dispatcher, 1));

        // and it is capped
        for ($i = 0; $i < 20; $i++) {
            $last = $delayFor->invoke($dispatcher, 0);
        }

        self::assertSame(30, $last);
    }

    public function testAWorkerThatStaysUpLongEnoughClearsItsCount(): void
    {
        $dispatcher = $this->getApp()->make(Dispatcher::class);

        $reflection = new ReflectionClass(Dispatcher::class);

        $delayFor     = $reflection->getMethod('restartDelayFor');
        $settleSlot   = $reflection->getMethod('settleSlot');
        $failures     = $reflection->getProperty('restartFailures');
        $startedAt    = $reflection->getProperty('slotStartedAt');
        $settledAfter = $reflection->getConstant('SETTLED_UPTIME_SECONDS');

        self::assertIsInt($settledAfter);

        for ($i = 0; $i < 6; $i++) {
            $delayFor->invoke($dispatcher, 0);
        }

        self::assertGreaterThan(0, $delayFor->invoke($dispatcher, 0));

        // a worker that died two seconds in - a Redis connect timing out during boot,
        // a crash on the first job - is running when the loop next looks, and used to
        // clear the count on that alone: it never reached the backoff at all
        $startedAt->setValue($dispatcher, [0 => time() - 2]);

        $settleSlot->invoke($dispatcher, 0);

        self::assertGreaterThan(0, $failures->getValue($dispatcher)[0]);

        // one that has been up long enough is a worker, not a boot loop
        $startedAt->setValue($dispatcher, [0 => time() - $settledAfter - 1]);

        $settleSlot->invoke($dispatcher, 0);

        self::assertSame(0, $failures->getValue($dispatcher)[0]);
        self::assertSame(0, $delayFor->invoke($dispatcher, 0));
    }

    public function testABackingOffSlotIsSkippedRatherThanSleptOn(): void
    {
        $dispatcher = $this->getApp()->make(Dispatcher::class);

        $reflection = new ReflectionClass(Dispatcher::class);

        $mayRefill        = $reflection->getMethod('mayRefillSlot');
        $restartNotBefore = $reflection->getProperty('restartNotBefore');

        // the first few deaths are refilled at once
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($mayRefill->invoke($dispatcher, 0));
        }

        $startedAt = time();

        // and then the slot is put off - without stopping the loop, which is what
        // sleep() did: the other slots went unwatched, their output unread, and a
        // 64KB pipe is all it takes for a healthy worker to block on printing
        self::assertFalse($mayRefill->invoke($dispatcher, 0));

        self::assertLessThan(1, time() - $startedAt);

        // and it stays put off until its deadline, rather than counting one more
        // failure on every tick
        self::assertFalse($mayRefill->invoke($dispatcher, 0));
        self::assertFalse($mayRefill->invoke($dispatcher, 0));

        // another slot is untouched by it
        self::assertTrue($mayRefill->invoke($dispatcher, 1));

        // once the deadline has passed the slot is filled, and the deadline is gone
        $restartNotBefore->setValue($dispatcher, [0 => time() - 1]);

        self::assertTrue($mayRefill->invoke($dispatcher, 0));
        self::assertSame([], $restartNotBefore->getValue($dispatcher));
    }
}
