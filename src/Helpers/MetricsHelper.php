<?php

namespace SLoggerLaravel\Helpers;

class MetricsHelper
{
    private static ?int $memoryLimitInMb = null;

    /**
     * Return memory usage in MB
     */
    public static function getMemoryUsagePercent(): float
    {
        $memoryLimit = self::getMemoryLimitInMb();

        $memoryUsage = memory_get_usage() / 1024 / 1024;

        return round(($memoryUsage / $memoryLimit) * 100);
    }

    /**
     * Return cpu percent usage
     */
    public static function getCpuAvgPercent(): ?float
    {
        $cpuAvg = sys_getloadavg();

        if (!$cpuAvg) {
            return null;
        }

        return round($cpuAvg[0] * 10, 2);
    }

    private static function getMemoryLimitInMb(): float
    {
        if (is_null(self::$memoryLimitInMb)) {
            $memoryLimitIni = ini_get('memory_limit');

            $memoryLimit = 128;

            if (preg_match('/^(\d+)(.)$/', $memoryLimitIni, $matches)) {
                if ($matches[2] == 'M') {
                    $memoryLimit = $matches[1];
                } elseif ($matches[2] == 'G') {
                    $memoryLimit = $matches[1] * 1024;
                } elseif ($matches[2] == 'K') {
                    $memoryLimit = $matches[1] / 1024;
                }
            }

            self::$memoryLimitInMb = (int) $memoryLimit;
        }

        return self::$memoryLimitInMb;
    }
}
