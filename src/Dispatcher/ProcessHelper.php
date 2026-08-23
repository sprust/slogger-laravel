<?php

namespace SLoggerLaravel\Dispatcher;

use RuntimeException;

class ProcessHelper
{
    public function getCurrentPid(): int
    {
        $pid = getmypid();

        if ($pid === false) {
            throw new RuntimeException('Failed to get PID.');
        }

        return $pid;
    }

    public function isPidActive(int $pid, string $commandName): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // @: a vanished /proc entry is an ordinary answer, and Laravel's error
        // handler would turn the warning into an exception
        $cmd = @file_get_contents("/proc/$pid/cmdline");

        if (!$cmd) {
            return false;
        }

        // /proc/<pid>/cmdline is NUL-separated and the saved name is not: without
        // this nothing matched, and a dead master left unstoppable workers
        $processName = trim(str_replace("\0", ' ', $cmd));

        return str_contains($processName, $commandName);
    }

    /**
     * SIGTERM, not SIGINT: `queue:work` leaves SIGINT at its default, which kills the
     * worker mid-job. SIGTERM is the one it reads as "finish this job, then stop".
     */
    public function sendStopSignal(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        if ($pid === $this->getCurrentPid()) {
            // a stale state file names a pid the kernel may hand out again - to this
            // process, whose command line matches. It would SIGTERM itself
            return;
        }

        // the pid alone: nothing calls setsid(), so a group kill took whatever else
        // the entrypoint had started. stop() names every pid anyway
        posix_kill($pid, SIGTERM);
    }
}
