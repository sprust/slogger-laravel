<?php

namespace SLoggerLaravel\Dispatcher;

use RuntimeException;
use Throwable;

readonly class DispatcherProcessState
{
    private string $staticUid;

    public function __construct()
    {
        $this->staticUid = "678ed0bcb2d2c";
    }

    public function getCurrentPid(): int
    {
        $pid = getmypid();

        if ($pid === false) {
            throw new RuntimeException('Failed to get PID.');
        }

        return $pid;
    }

    public function getSavedPid(): ?int
    {
        $pidFilePath = $this->makePidFilePath();

        if (!file_exists($pidFilePath)) {
            return null;
        }

        $pid = (int) file_get_contents($pidFilePath);

        if (!$pid) {
            return null;
        }

        return $pid;
    }

    public function savePid(int $pid): void
    {
        $pidFilePath = $this->makePidFilePath();

        if (file_put_contents($pidFilePath, $pid) === false) {
            throw new RuntimeException('Failed to write PID to file.');
        }
    }

    public function purgePid(): void
    {
        $pidFilePath = $this->makePidFilePath();

        if (!file_exists($pidFilePath)) {
            return;
        }

        if (!unlink($pidFilePath)) {
            throw new RuntimeException('Failed to remove PID file.');
        }
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
        posix_kill($pid, SIGINT);
    }

    private function makePidFilePath(): string
    {
        $tmpDir = trim(sys_get_temp_dir(), '/');

        return "/$tmpDir/slogger-laravel-dispatcher-$this->staticUid.pid";
    }
}
