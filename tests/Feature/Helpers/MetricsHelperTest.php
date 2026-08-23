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
        // the CLI default, where this package's own dispatcher runs: a percentage of
        // a hardcoded 128M produced numbers above 100 out of thin air
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
        // `512K` is half a megabyte; an int cast made it 0 and every trace died on a
        // DivisionByZeroError the firewall swallowed
        self::assertSame(0.5, $this->parse('512K'));

        // a plain byte count is valid ini and fell through to the default
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

        // PHP reads this as one gigabyte and warns; requiring the whole string to
        // match reported no metric at all
        self::assertSame(1024.0, $this->parse('1.5G'));
    }

    public function testAMachineThatWillNotSayItsCoreCountReportsNoCpuMetric(): void
    {
        $reflection = new ReflectionClass(MetricsHelper::class);

        // assuming one core reported a load of 4 on an eight-core box as 400%
        $reflection->getProperty('cpuCount')->setValue(null, false);

        try {
            self::assertNull(MetricsHelper::getCpuAvgPercent());
        } finally {
            $this->resetCaches();
        }
    }

    public function testGetCpuAvgPercentIsNormalisedByCoreCount(): void
    {
        // call the helper rather than re-deriving its arithmetic: a test that
        // reimplements the formula agrees with a wrong implementation
        self::assertSame(100.0, MetricsHelper::normaliseCpuPercent(4.0, 4));
        self::assertSame(50.0, MetricsHelper::normaliseCpuPercent(2.0, 4));
        self::assertSame(0.0, MetricsHelper::normaliseCpuPercent(0.0, 4));

        // an overloaded machine is allowed to exceed 100
        self::assertSame(200.0, MetricsHelper::normaliseCpuPercent(8.0, 4));

        // the same load means different things on machines of different size
        self::assertSame(400.0, MetricsHelper::normaliseCpuPercent(4.0, 1));

        $value = MetricsHelper::getCpuAvgPercent();

        self::assertNotNull($value);
        self::assertGreaterThanOrEqual(0, $value);
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
}
