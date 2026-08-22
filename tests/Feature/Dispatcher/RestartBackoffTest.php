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

    public function testAWorkerThatStaysUpClearsItsCount(): void
    {
        $dispatcher = $this->getApp()->make(Dispatcher::class);

        $reflection = new ReflectionClass(Dispatcher::class);

        $delayFor = $reflection->getMethod('restartDelayFor');
        $failures = $reflection->getProperty('restartFailures');

        for ($i = 0; $i < 6; $i++) {
            $delayFor->invoke($dispatcher, 0);
        }

        self::assertGreaterThan(0, $delayFor->invoke($dispatcher, 0));

        // this is what the supervision loop does when it sees the process running
        $failures->setValue($dispatcher, [0 => 0]);

        self::assertSame(0, $delayFor->invoke($dispatcher, 0));
    }
}
