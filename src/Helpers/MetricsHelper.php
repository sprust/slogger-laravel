<?php

namespace SLoggerLaravel\Helpers;

class MetricsHelper
{
    /** `false` means "no limit"; `null` means "not resolved yet". */
    private static null|false|float $memoryLimitInMb = null;

    /** `false` means "the machine will not say"; `null` means "not looked yet". */
    private static null|false|int $cpuCount = null;

    /**
     * Null rather than a made-up number: `memory_limit = -1` is the CLI default, where
     * this package's own dispatcher runs.
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
     * One-minute load average as a percentage of capacity - a raw load average is a
     * queue length, not a percentage. Can exceed 100 on an overloaded machine.
     *
     * Null when the core count cannot be read: assuming one core reported a
     * comfortable load of 4 on an eight-core box as 400%.
     */
    public static function getCpuAvgPercent(): ?float
    {
        $cpuCount = self::getCpuCount();

        if ($cpuCount === false) {
            return null;
        }

        $cpuAvg = sys_getloadavg();

        if (!$cpuAvg) {
            return null;
        }

        return self::normaliseCpuPercent($cpuAvg[0], $cpuCount);
    }

    /**
     * One core fully busy is 100% on a one-core machine and 25% on four.
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
        // a suffix is optional and case-insensitive, and a plain byte count is valid
        if (!preg_match('/^\s*(-?\d+)/', $memoryLimitIni, $matches)) {
            return false;
        }

        $value = (float) $matches[1];

        if ($value < 0) {
            // -1: no limit
            return false;
        }

        // the leading integer and the trailing unit, whatever sits between: PHP reads
        // `1.5G` as one gigabyte and warns
        $suffix = strtoupper(substr(rtrim($memoryLimitIni), -1));

        // float, not int: `512K` is half a megabyte, and an int cast made it 0 - then
        // every trace died on a DivisionByZeroError the firewall swallowed
        return match ($suffix) {
            'G'     => $value * 1024,
            'M'     => $value,
            'K'     => $value / 1024,
            default => $value / 1024 / 1024,
        };
    }

    /**
     * `false` without procfs - every platform but Linux, and containers that hide it.
     */
    private static function getCpuCount(): false|int
    {
        if (!is_null(self::$cpuCount)) {
            return self::$cpuCount;
        }

        $cpuInfo = @file_get_contents('/proc/cpuinfo');

        if ($cpuInfo === false) {
            return self::$cpuCount = false;
        }

        $count = (int) preg_match_all('/^processor\s*:/mi', $cpuInfo);

        return self::$cpuCount = $count > 0 ? $count : false;
    }
}
