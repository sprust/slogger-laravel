<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Configs;

use SLoggerLaravel\Configs\DispatcherQueueConfig;
use SLoggerLaravel\Configs\LocalProxyConfig;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class LocalProxyConfigTest extends BaseTestCase
{
    private LocalProxyConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new LocalProxyConfig(new DispatcherQueueConfig());
    }

    public function testDefaultsWhenKeysAreMissing(): void
    {
        // an application with a config published before these keys existed
        config()->set('slogger.local_proxy', []);

        self::assertSame(
            LocalProxyConfig::DEFAULT_METRICS_MAX_SNAPSHOT_AGE_SECONDS,
            $this->config->getMetricsMaxSnapshotAgeSeconds()
        );
        self::assertFalse($this->config->isAlertingEnabled());
        self::assertSame(
            LocalProxyConfig::DEFAULT_ALERT_MAX_PENDING_BYTES,
            $this->config->getAlertMaxPendingBytes()
        );
        self::assertSame(
            LocalProxyConfig::DEFAULT_ALERT_MAX_MINUTES_WITHOUT_SEND,
            $this->config->getAlertMaxMinutesWithoutSend()
        );
    }

    public function testConfiguredValues(): void
    {
        config()->set('slogger.local_proxy.metrics.max_snapshot_age_seconds', 30);
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_pending_bytes', 500);
        config()->set('slogger.local_proxy.metrics.alerting.max_minutes_without_send', 15);

        self::assertSame(30, $this->config->getMetricsMaxSnapshotAgeSeconds());
        self::assertTrue($this->config->isAlertingEnabled());
        self::assertSame(500, $this->config->getAlertMaxPendingBytes());
        self::assertSame(15, $this->config->getAlertMaxMinutesWithoutSend());
    }

    public function testNonPositiveValuesFallBackToDefaults(): void
    {
        config()->set('slogger.local_proxy.metrics.max_snapshot_age_seconds', 0);
        config()->set('slogger.local_proxy.metrics.alerting.max_pending_bytes', -1);
        config()->set('slogger.local_proxy.metrics.alerting.max_minutes_without_send', 0);

        self::assertSame(
            LocalProxyConfig::DEFAULT_METRICS_MAX_SNAPSHOT_AGE_SECONDS,
            $this->config->getMetricsMaxSnapshotAgeSeconds()
        );
        self::assertSame(
            LocalProxyConfig::DEFAULT_ALERT_MAX_PENDING_BYTES,
            $this->config->getAlertMaxPendingBytes()
        );
        self::assertSame(
            LocalProxyConfig::DEFAULT_ALERT_MAX_MINUTES_WITHOUT_SEND,
            $this->config->getAlertMaxMinutesWithoutSend()
        );
    }

    public function testTracesPathDelegatesToQueueConfigNormalization(): void
    {
        config()->set('slogger.local_proxy.traces_path', '/storage/custom/');

        self::assertSame('storage/custom', $this->config->getTracesPath());
    }
}
