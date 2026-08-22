<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use ReflectionClass;
use SLoggerLaravel\Helpers\MetricsHelper;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class MetricsHelperTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        $this->resetCaches();

        parent::tearDown();
    }

    public function testGetMemoryUsagePercentWithinBounds(): void
    {
        $percent = $this->withMemoryLimit('1024M', MetricsHelper::getMemoryUsagePercent(...));

        self::assertNotNull($percent);
        self::assertGreaterThan(0, $percent);
        self::assertLessThanOrEqual(100, $percent);
    }

    public function testAnUnlimitedMemoryLimitReportsNothing(): void
    {
        // the CLI default, and where this package's own dispatcher runs. Reporting a
        // percentage of a hardcoded 128M used to produce numbers above 100 out of thin air
        self::assertNull(
            $this->withMemoryLimit('-1', MetricsHelper::getMemoryUsagePercent(...))
        );
    }

    /**
     * PHP refuses to lower `memory_limit` below the current usage, so the parsing is
     * exercised directly rather than through ini.
     */
    public function testMemoryLimitParsing(): void
    {
        // `512K` is half a megabyte; an int cast made it 0, and every trace then died
        // on a DivisionByZeroError that the watcher firewall swallowed silently
        self::assertSame(0.5, $this->parse('512K'));

        // a plain byte count is valid ini and used to fall through to the default
        self::assertSame(256.0, $this->parse((string) (256 * 1024 * 1024)));

        self::assertSame(256.0, $this->parse('256M'));
        self::assertSame(1024.0, $this->parse('1G'));

        // the suffix is case-insensitive
        self::assertSame(1024.0, $this->parse('1g'));
        self::assertSame(0.5, $this->parse('512k'));

        // no limit, and nothing parseable: no percentage can be reported
        self::assertFalse($this->parse('-1'));
        self::assertFalse($this->parse(''));
        self::assertFalse($this->parse('lots'));
    }

    public function testGetCpuAvgPercentIsNormalisedByCoreCount(): void
    {
        $value = MetricsHelper::getCpuAvgPercent();

        self::assertNotNull($value);
        self::assertGreaterThanOrEqual(0, $value);

        $loadAverage = sys_getloadavg();

        self::assertIsArray($loadAverage);

        // a load average is a queue length, not a percentage: `loadavg * 10` only
        // ever meant anything on a machine that happened to have ten cores
        self::assertSame(
            round(($loadAverage[0] / $this->getCpuCount()) * 100, 2),
            $value
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withMemoryLimit(string $limit, callable $callback): mixed
    {
        $original = ini_get('memory_limit');

        try {
            ini_set('memory_limit', $limit);

            $this->resetCaches();

            return $callback();
        } finally {
            ini_set('memory_limit', (string) $original);

            $this->resetCaches();
        }
    }

    private function parse(string $value): false|float
    {
        $method = (new ReflectionClass(MetricsHelper::class))
            ->getMethod('parseMemoryLimitInMb');

        /** @var false|float $result */
        $result = $method->invoke(null, $value);

        return $result;
    }

    private function resetCaches(): void
    {
        $reflection = new ReflectionClass(MetricsHelper::class);

        $reflection->getProperty('memoryLimitInMb')->setValue(null, null);
        $reflection->getProperty('cpuCount')->setValue(null, null);
    }

    private function getCpuCount(): int
    {
        $cpuInfo = file_get_contents('/proc/cpuinfo');

        self::assertIsString($cpuInfo);

        return max(1, preg_match_all('/^processor\s*:/mi', $cpuInfo));
    }
}
