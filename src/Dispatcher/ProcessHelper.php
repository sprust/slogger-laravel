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

        // /proc/<pid>/cmdline is NUL-separated, the saved command name is not:
        // without this nothing ever matched, and a dead master left unstoppable
        // workers behind
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
            // a state file outliving its master names a pid the kernel may hand out
            // again - to this process, whose command line matches. The new master
            // would SIGTERM itself before starting anything
            return;
        }

        $pgid = posix_getpgid($pid);

        posix_kill($pid, SIGTERM);

        if ($pgid === false || $pgid <= 0) {
            // the target died in between: posix_kill(-false) is posix_kill(0), which
            // signals the caller's own group
            return;
        }

        if ($pgid === posix_getpgrp()) {
            // children spawned without setsid share the caller's group
            return;
        }

        if ($pgid <= 1) {
            // posix_kill(-1) is a broadcast; a master running as PID 1 has pgid 1
            return;
        }

        posix_kill(-$pgid, SIGTERM);
    }
}
