<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use ReflectionClass;
use SLoggerLaravel\Helpers\MetricsHelper;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class MetricsHelperTest extends BaseTestCase
{
    public function testGetMemoryUsagePercentWithinBounds(): void
    {
        $original = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '1024M');

            $this->resetMemoryLimitCache();

            $percent = MetricsHelper::getMemoryUsagePercent();

            self::assertGreaterThanOrEqual(0, $percent);
            self::assertLessThanOrEqual(100, $percent);
        } finally {
            ini_set('memory_limit', (string) $original);
        }

        $this->resetMemoryLimitCache();
    }

    public function testGetMemoryLimitParsingForUnits(): void
    {
        $original = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '256M');
            $this->resetMemoryLimitCache();
            MetricsHelper::getMemoryUsagePercent();
            self::assertSame(256, $this->getMemoryLimitInMb());

            ini_set('memory_limit', '1G');
            $this->resetMemoryLimitCache();
            MetricsHelper::getMemoryUsagePercent();
            self::assertSame(1024, $this->getMemoryLimitInMb());

            ini_set('memory_limit', '-1');
            $this->resetMemoryLimitCache();
            MetricsHelper::getMemoryUsagePercent();
            self::assertSame(128, $this->getMemoryLimitInMb());
        } finally {
            ini_set('memory_limit', (string) $original);
        }

        $this->resetMemoryLimitCache();
    }

    public function testGetCpuAvgPercentReturnsFloatOrNull(): void
    {
        $value = MetricsHelper::getCpuAvgPercent();

        self::assertNotNull($value);
        self::assertGreaterThanOrEqual(0, $value);
    }

    private function resetMemoryLimitCache(): void
    {
        $reflection = new ReflectionClass(MetricsHelper::class);
        $property   = $reflection->getProperty('memoryLimitInMb');
        $property->setAccessible(true);
        $property->setValue(null);
    }

    private function getMemoryLimitInMb(): int
    {
        $reflection = new ReflectionClass(MetricsHelper::class);
        $property   = $reflection->getProperty('memoryLimitInMb');
        $property->setAccessible(true);

        return (int) $property->getValue();
    }
}
