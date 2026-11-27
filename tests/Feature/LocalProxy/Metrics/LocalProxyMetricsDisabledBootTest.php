<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\LocalProxy\Metrics;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use SLoggerLaravel\Events\LocalProxyBacklogExceededEvent;
use SLoggerLaravel\Tests\Feature\LocalProxy\Metrics\Support\RecordingLocalProxyAlertListener;

/**
 * The package booted with SLOGGER_ENABLED=false: the metrics command is an
 * ops tool and stays available, but the listeners section is not registered,
 * so an alert event reaches nobody.
 */
class LocalProxyMetricsDisabledBootTest extends MetricsTestCase
{
    public function testCommandWorksWhenPackageDisabled(): void
    {
        $this->writeSnapshot();

        $exitCode = Artisan::call('slogger:local-proxy:metrics', ['--once' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('process.goroutines=21', Artisan::output());
    }

    public function testConfigListenerIsNotRegisteredWhenPackageDisabled(): void
    {
        RecordingLocalProxyAlertListener::reset();

        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_pending_bytes', 1);

        // the checker does dispatch the event...
        $dispatched = [];
        Event::listen(
            LocalProxyBacklogExceededEvent::class,
            static function (LocalProxyBacklogExceededEvent $event) use (&$dispatched): void {
                $dispatched[] = $event;
            }
        );

        $this->writeSnapshot();
        $this->writeHourlyFile('2026_11_19_10', 500);

        $exitCode = Artisan::call('slogger:local-proxy:metrics', ['--once' => true]);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $dispatched);
        // ...but the listener from the config was never registered
        self::assertSame([], RecordingLocalProxyAlertListener::$handled);
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('slogger.enabled', false);
        $app['config']->set('slogger.listeners', [
            LocalProxyBacklogExceededEvent::class => [
                RecordingLocalProxyAlertListener::class,
            ],
        ]);
    }
}
