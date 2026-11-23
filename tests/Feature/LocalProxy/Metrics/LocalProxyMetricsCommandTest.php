<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\LocalProxy\Metrics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use SLoggerLaravel\Events\LocalProxyBacklogExceededEvent;
use SLoggerLaravel\Events\LocalProxyMetricsUnavailableEvent;

class LocalProxyMetricsCommandTest extends MetricsTestCase
{
    public function testLineFormatOnce(): void
    {
        $this->writeSnapshot();
        $this->writeHourlyFile('2026_11_19_10', 500);

        $exitCode = Artisan::call('slogger:local-proxy:metrics', ['--once' => true]);

        self::assertSame(0, $exitCode);

        $output = Artisan::output();

        self::assertStringContainsString('process.goroutines=21', $output);
        self::assertStringContainsString('server.connections=2', $output);
        self::assertStringContainsString('storage.state.completed=false', $output);
        self::assertStringContainsString('sender.sent_operations_total=98', $output);
        self::assertStringContainsString('pending_bytes=500', $output);
        // exactly one line per snapshot
        self::assertSame(0, substr_count(trim($output), "\n"));
    }

    public function testLineIsTheDefaultFormat(): void
    {
        $this->writeSnapshot();

        $exitCode = Artisan::call('slogger:local-proxy:metrics', ['--once' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('process.goroutines=21', Artisan::output());
    }

    public function testJsonFormatOnce(): void
    {
        $this->writeSnapshot();

        $exitCode = Artisan::call('slogger:local-proxy:metrics', [
            'format' => 'json',
            '--once' => true,
        ]);

        self::assertSame(0, $exitCode);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(Artisan::output(), true);

        self::assertSame(21, $decoded['process']['goroutines']);
        self::assertSame(98, $decoded['sender']['sent_operations_total']);
        self::assertSame(0, $decoded['pending_bytes']);
        self::assertSame(5000, $decoded['storage']['state']['offset']);
    }

    public function testUnknownFormatFails(): void
    {
        $this->writeSnapshot();

        $exitCode = Artisan::call('slogger:local-proxy:metrics', [
            'format' => 'xml',
            '--once' => true,
        ]);

        self::assertSame(1, $exitCode);
    }

    public function testInvalidIntervalFails(): void
    {
        $this->writeSnapshot();

        // 1e309 casts to INF, 1e20 overflows when converted to microseconds
        foreach (['0', '-1', 'abc', '1e309', '1e20', '3601'] as $interval) {
            $exitCode = Artisan::call('slogger:local-proxy:metrics', [
                '--interval' => $interval,
                '--once'     => true,
            ]);

            self::assertSame(1, $exitCode, "interval [$interval]");
        }
    }

    public function testFractionalIntervalIsValid(): void
    {
        $this->writeSnapshot();

        $exitCode = Artisan::call('slogger:local-proxy:metrics', [
            '--interval' => '0.5',
            '--once'     => true,
        ]);

        self::assertSame(0, $exitCode);
    }

    public function testMissingSnapshotFailsOnce(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);

        $exitCode = Artisan::call('slogger:local-proxy:metrics', ['--once' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('error="metrics unavailable', Artisan::output());
        Event::assertDispatched(LocalProxyMetricsUnavailableEvent::class, 1);
    }

    public function testInvalidTracesPathFailsGracefully(): void
    {
        config()->set('slogger.local_proxy.traces_path', '../outside');

        $exitCode = Artisan::call('slogger:local-proxy:metrics', ['--once' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('error="metrics unavailable', Artisan::output());
    }

    public function testStaleSnapshotFailsOnce(): void
    {
        $this->writeSnapshot([
            'collected_at' => Carbon::now()->subMinute()->toIso8601String(),
        ]);

        $exitCode = Artisan::call('slogger:local-proxy:metrics', ['--once' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('stale', Artisan::output());
    }

    public function testJsonErrorOutput(): void
    {
        $exitCode = Artisan::call('slogger:local-proxy:metrics', [
            'format' => 'json',
            '--once' => true,
        ]);

        self::assertSame(1, $exitCode);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(Artisan::output(), true);

        self::assertArrayHasKey('error', $decoded);
    }

    public function testFiresAlertWhenThresholdExceeded(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_pending_bytes', 1);

        $this->writeSnapshot();
        $this->writeHourlyFile('2026_11_19_10', 500);

        $exitCode = Artisan::call('slogger:local-proxy:metrics', ['--once' => true]);

        self::assertSame(0, $exitCode);
        Event::assertDispatched(
            LocalProxyBacklogExceededEvent::class,
            static fn(LocalProxyBacklogExceededEvent $event): bool => $event->pendingBytes === 500
        );
    }
}
