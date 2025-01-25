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

        $processName = trim($cmd, "\0");

        return str_contains($processName, $commandName);
    }

    public function sendStopSignal(int $pid): void
    {
        $pgid = posix_getpgid($pid);

        posix_kill($pid, SIGINT);
        posix_kill(-$pgid, SIGINT);
    }
}
