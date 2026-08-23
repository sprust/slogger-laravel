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

        // this process was started with at least one argument, so its cmdline holds
        // a NUL separator - which is the whole point of the test
        self::assertStringContainsString("\0", $raw);

        $spaceSeparated = trim(str_replace("\0", ' ', $raw));

        // /proc/<pid>/cmdline is NUL-separated; a saved command name is not. Without
        // the substitution this never matched, and a master that died left workers
        // that could be neither found nor stopped through the state file
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

        // posix_getpgid() of the dead pid returns false: previously that became
        // posix_kill(-false, ...) === posix_kill(0, ...) and signalled our own group
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

            // the child shares our pgid: the group-kill branch must be skipped,
            // only the child itself may be signaled
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

        // a state file that outlived its master names a pid the kernel may have
        // handed out again - to this very process, whose command line matches the
        // saved one exactly. SIGTERM at its default action then killed the new
        // master before it had started anything, on every start, forever
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
