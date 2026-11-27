<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\LocalProxy\Metrics;

use Illuminate\Support\Facades\Artisan;
use SLoggerLaravel\Events\LocalProxyBacklogExceededEvent;
use SLoggerLaravel\Tests\Feature\LocalProxy\Metrics\Support\RecordingLocalProxyAlertListener;

class LocalProxyMetricsEnabledBootTest extends MetricsTestCase
{
    public function testConfigListenerReceivesAlertWhenPackageEnabled(): void
    {
        RecordingLocalProxyAlertListener::reset();

        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_pending_bytes', 1);

        $this->writeSnapshot();
        $this->writeHourlyFile('2026_11_19_10', 500);

        $exitCode = Artisan::call('slogger:local-proxy:metrics', ['--once' => true]);

        self::assertSame(0, $exitCode);
        self::assertCount(1, RecordingLocalProxyAlertListener::$handled);
        self::assertSame(500, RecordingLocalProxyAlertListener::$handled[0]->pendingBytes);
    }

    /**
     * The listeners section is read when the package boots, so it has to be
     * in place before the providers register - getEnvironmentSetUp runs
     * too late for that.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('slogger.listeners', [
            LocalProxyBacklogExceededEvent::class => [
                RecordingLocalProxyAlertListener::class,
            ],
        ]);
    }
}
