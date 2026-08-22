<?php

namespace SLoggerLaravel\Dispatcher;

use RuntimeException;
use Throwable;

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

        try {
            $cmd = @file_get_contents("/proc/$pid/cmdline");
        } catch (Throwable) {
            return false;
        }

        if (!$cmd) {
            return false;
        }

        // /proc/<pid>/cmdline separates the arguments with NUL, while the stored
        // command name is a space-separated string: trimming only the trailing NULs
        // left `php\0artisan\0slogger:...` to be searched for `php artisan slogger:...`,
        // which never matched - so after the master died its workers were neither
        // findable nor stoppable through the saved state
        $processName = trim(str_replace("\0", ' ', $cmd));

        return str_contains($processName, $commandName);
    }

    /**
     * SIGTERM, not SIGINT: `queue:work` installs handlers for SIGTERM, SIGQUIT and
     * SIGUSR2 and leaves SIGINT at its default, which kills the worker outright -
     * in the middle of whatever job it was running. SIGTERM is the signal it treats
     * as "finish this job, then stop", and the master handles both.
     */
    public function sendStopSignal(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        $pgid = posix_getpgid($pid);

        posix_kill($pid, SIGTERM);

        if ($pgid === false || $pgid <= 0) {
            // the target died in between: posix_kill(-$pgid) would become
            // posix_kill(0, ...) and signal the caller's own process group
            return;
        }

        if ($pgid === posix_getpgrp()) {
            // children spawned without setsid share the caller's process group:
            // a group-kill would signal the caller itself and every sibling process
            return;
        }

        if ($pgid <= 1) {
            // posix_kill(-1, ...) is a broadcast to every process the user may
            // signal. A master running as PID 1 - an ordinary container entrypoint -
            // has pgid 1, so this is reachable, not theoretical
            return;
        }

        posix_kill(-$pgid, SIGTERM);
    }
}
