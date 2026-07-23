<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Helpers;

use SLoggerLaravel\Dispatcher\ProcessHelper;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class ProcessHelperTest extends BaseTestCase
{
    public function testGetCurrentPidReturnsPositive(): void
    {
        $helper = new ProcessHelper();

        self::assertGreaterThan(0, $helper->getCurrentPid());
    }

    public function testIsPidActiveReturnsFalseForInvalidPid(): void
    {
        $helper = new ProcessHelper();

        self::assertFalse($helper->isPidActive(0, 'php'));
        self::assertFalse($helper->isPidActive(-1, 'php'));
    }

    public function testIsPidActiveReturnsTrueForCurrentPid(): void
    {
        $helper = new ProcessHelper();

        $pid = $helper->getCurrentPid();

        self::assertTrue($helper->isPidActive($pid, 'php'));
    }

    public function testSendStopSignalToDeadPidDoesNotSignalOwnProcessGroup(): void
    {
        $helper = new ProcessHelper();

        $process = proc_open('exec sleep 0.05', [], $pipes);

        self::assertIsResource($process);

        $childPid = (int) proc_get_status($process)['pid'];

        proc_close($process); // waits until the child is dead

        $received = $this->runWithSigintCounter(
            static fn() => $helper->sendStopSignal($childPid)
        );

        // posix_getpgid() of the dead pid returns false: previously that became
        // posix_kill(-false, ...) === posix_kill(0, ...) and SIGINT'ed our own group
        self::assertSame(0, $received);
    }

    public function testSendStopSignalToSameGroupChildDoesNotSignalCaller(): void
    {
        $helper = new ProcessHelper();

        $process = proc_open('exec sleep 10', [], $pipes);

        self::assertIsResource($process);

        $childPid = (int) proc_get_status($process)['pid'];

        try {
            $received = $this->runWithSigintCounter(
                static fn() => $helper->sendStopSignal($childPid)
            );

            // the child shares our pgid: the group-kill branch must be skipped,
            // only the child itself may be signaled
            self::assertSame(0, $received);
        } finally {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
    }

    /**
     * @param callable(): void $callback
     */
    private function runWithSigintCounter(callable $callback): int
    {
        $received = 0;

        $previousAsync   = pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGINT);

        pcntl_signal(SIGINT, static function () use (&$received) {
            $received++;
        });

        try {
            $callback();

            usleep(50000);

            pcntl_signal_dispatch();
        } finally {
            pcntl_signal(SIGINT, $previousHandler);
            pcntl_async_signals($previousAsync);
        }

        return $received;
    }
}
