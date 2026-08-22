<?php

namespace SLoggerLaravel\Helpers;

class MetricsHelper
{
    /**
     * `false` means "resolved, and there is no limit" - `null` means "not resolved
     * yet". Without the distinction an unlimited `memory_limit` was re-parsed on
     * every trace.
     */
    private static null|false|float $memoryLimitInMb = null;

    private static ?int $cpuCount = null;

    /**
     * Percentage of the configured memory limit currently in use, or null when there
     * is no limit to measure against.
     *
     * Null rather than a made-up number: `memory_limit = -1` is the CLI default, and
     * this package's own dispatcher runs there. Falling back to a hardcoded 128M used
     * to report percentages above 100 for processes that had no limit at all.
     */
    public static function getMemoryUsagePercent(): ?float
    {
        $memoryLimit = self::getMemoryLimitInMb();

        if ($memoryLimit === false || $memoryLimit <= 0) {
            return null;
        }

        $memoryUsage = memory_get_usage() / 1024 / 1024;

        return round(($memoryUsage / $memoryLimit) * 100, 2);
    }

    /**
     * One-minute load average as a percentage of the machine's capacity.
     *
     * Normalised by core count: a raw load average is a queue length, not a
     * percentage, so the previous `loadavg * 10` meant nothing on any machine that
     * did not happen to have ten cores. Can exceed 100 - that is what an overloaded
     * machine looks like.
     */
    public static function getCpuAvgPercent(): ?float
    {
        $cpuAvg = sys_getloadavg();

        if (!$cpuAvg) {
            return null;
        }

        return self::normaliseCpuPercent($cpuAvg[0], self::getCpuCount());
    }

    /**
     * A load average as a percentage of capacity: one core fully busy is 100% on a
     * one-core machine and 25% on four. The previous `loadavg * 10` meant something
     * only on a ten-core box.
     */
    public static function normaliseCpuPercent(float $loadAverage, int $cpuCount): float
    {
        return round(($loadAverage / max(1, $cpuCount)) * 100, 2);
    }

    private static function getMemoryLimitInMb(): false|float
    {
        if (!is_null(self::$memoryLimitInMb)) {
            return self::$memoryLimitInMb;
        }

        // anything unparseable, an empty string included, comes back as false
        return self::$memoryLimitInMb = self::parseMemoryLimitInMb(
            (string) ini_get('memory_limit')
        );
    }

    /**
     * `false` when there is no limit to measure against.
     */
    private static function parseMemoryLimitInMb(string $memoryLimitIni): false|float
    {
        // a shorthand suffix is optional and case-insensitive, and a plain byte count
        // is just as valid - `memory_limit = 134217728` used to fall through to the
        // hardcoded default
        if (!preg_match('/^\s*(-?\d+)\s*([KMG]?)\s*$/i', $memoryLimitIni, $matches)) {
            return false;
        }

        $value = (float) $matches[1];

        if ($value < 0) {
            // -1: no limit
            return false;
        }

        // float, not int: `512K` is half a megabyte, and an int cast made it 0 - and
        // then every trace died on a DivisionByZeroError, which the watcher firewall
        // swallowed, taking the whole of tracing down quietly with it
        return match (strtoupper($matches[2])) {
            'G'     => $value * 1024,
            'M'     => $value,
            'K'     => $value / 1024,
            default => $value / 1024 / 1024,
        };
    }

    private static function getCpuCount(): int
    {
        if (!is_null(self::$cpuCount)) {
            return self::$cpuCount;
        }

        $cpuInfo = @file_get_contents('/proc/cpuinfo');

        $count = $cpuInfo === false
            ? 0
            : preg_match_all('/^processor\s*:/mi', $cpuInfo);

        return self::$cpuCount = max(1, (int) $count);
    }
}
