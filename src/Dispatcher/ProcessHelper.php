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
            $cmd = file_get_contents("/proc/$pid/cmdline");
        } catch (Throwable) {
            return false;
        }

        if (!$cmd) {
            return false;
        }

        $processName = trim($cmd, "\0");

        return str_contains($processName, $commandName);
    }

    public function sendStopSignal(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        $pgid = posix_getpgid($pid);

        posix_kill($pid, SIGINT);

        if ($pgid === false || $pgid <= 0) {
            // the target died in between: posix_kill(-$pgid) would become
            // posix_kill(0, ...) and signal the caller's own process group
            return;
        }

        if ($pgid === posix_getpgrp()) {
            // children spawned without setsid share the caller's process group:
            // a group-kill would SIGINT the caller itself and every sibling process
            return;
        }

        posix_kill(-$pgid, SIGINT);
    }
}
