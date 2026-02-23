<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Helpers;

use Illuminate\Support\Carbon;
use ReflectionClass;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class TraceHelperTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testMakeTraceIdUsesExplicitPrefix(): void
    {
        config()->set('slogger.trace_id_prefix', 'custom-prefix');

        $this->resetPrefix();

        $traceId = TraceHelper::makeTraceId();

        self::assertMatchesRegularExpression('/^custom-prefix-[0-9a-f-]{36}$/', $traceId);
    }

    public function testMakeTraceIdUsesAppNameWhenPrefixEmpty(): void
    {
        config()->set('slogger.trace_id_prefix', '');
        config()->set('app.name', 'My Test App');

        $this->resetPrefix();

        $traceId = TraceHelper::makeTraceId();

        self::assertMatchesRegularExpression('/^my-test-app-[0-9a-f-]{36}$/', $traceId);
    }

    public function testMakeTraceIdDefaultsToAppWhenNoName(): void
    {
        config()->set('slogger.trace_id_prefix', '');
        config()->set('app.name', '');
        $this->resetPrefix();

        $traceId = TraceHelper::makeTraceId();

        self::assertMatchesRegularExpression('/^app-[0-9a-f-]{36}$/', $traceId);
    }

    public function testCalcDurationUsesUtcAndRounds(): void
    {
        /** @var Carbon $now */
        $now = Carbon::create(2020, 1, 1, 0, 0, 0, 'UTC');

        Carbon::setTestNow($now);

        $startedAt = $now->copy()->subSeconds(1.234567);
        $duration  = TraceHelper::calcDuration($startedAt);

        self::assertSame(1.234567, $duration);
    }

    public function testRoundDurationRoundsToSixDecimals(): void
    {
        $value = TraceHelper::roundDuration(1.23456789);

        self::assertSame(1.234568, $value);
    }

    private function resetPrefix(): void
    {
        $reflection = new ReflectionClass(TraceHelper::class);
        $property   = $reflection->getProperty('prefix');
        $property->setAccessible(true);
        $property->setValue(null);
    }
}
