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

    public function testIsPidActiveMatchesTheSpaceSeparatedCommandName(): void
    {
        $helper = new ProcessHelper();

        $pid = getmypid();

        self::assertIsInt($pid);

        $raw = file_get_contents("/proc/$pid/cmdline");

        self::assertIsString($raw);

        // started with an argument, so its cmdline holds a NUL - the point of this
        self::assertStringContainsString("\0", $raw);

        $spaceSeparated = trim(str_replace("\0", ' ', $raw));

        // /proc/<pid>/cmdline is NUL-separated and a saved command name is not:
        // without the substitution a dead master left unstoppable workers
        self::assertTrue($helper->isPidActive($pid, $spaceSeparated));

        self::assertFalse($helper->isPidActive($pid, $spaceSeparated . '-no-such-suffix'));
    }

    public function testSendStopSignalToDeadPidDoesNotSignalOwnProcessGroup(): void
    {
        $helper = new ProcessHelper();

        $process = proc_open('exec sleep 0.05', [], $pipes);

        self::assertIsResource($process);

        $childPid = (int) proc_get_status($process)['pid'];

        proc_close($process); // waits until the child is dead

        $received = $this->runWithStopSignalCounter(
            static fn() => $helper->sendStopSignal($childPid)
        );

        // posix_getpgid() of a dead pid returns false, and posix_kill(-false) is
        // posix_kill(0) - our own group
        self::assertSame(0, $received);
    }

    public function testSendStopSignalToSameGroupChildDoesNotSignalCaller(): void
    {
        $helper = new ProcessHelper();

        $process = proc_open('exec sleep 10', [], $pipes);

        self::assertIsResource($process);

        $childPid = (int) proc_get_status($process)['pid'];

        try {
            $received = $this->runWithStopSignalCounter(
                static fn() => $helper->sendStopSignal($childPid)
            );

            // the child shares our pgid, so the group-kill branch must be skipped
            self::assertSame(0, $received);
        } finally {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
    }

    public function testSendStopSignalNeverSignalsTheCallerItself(): void
    {
        $helper = new ProcessHelper();

        $received = $this->runWithStopSignalCounter(
            static fn() => $helper->sendStopSignal($helper->getCurrentPid())
        );

        // a state file outliving its master names a pid the kernel may hand out
        // again - to this process, whose command line matches the saved one
        self::assertSame(0, $received);
    }

    /**
     * @param callable(): void $callback
     */
    private function runWithStopSignalCounter(callable $callback): int
    {
        $received = 0;

        $previousAsync   = pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGTERM);

        pcntl_signal(SIGTERM, static function () use (&$received) {
            $received++;
        });

        try {
            $callback();

            usleep(50000);

            pcntl_signal_dispatch();
        } finally {
            pcntl_signal(SIGTERM, $previousHandler);
            pcntl_async_signals($previousAsync);
        }

        return $received;
    }
}
