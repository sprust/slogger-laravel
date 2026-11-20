<?php

namespace SLoggerLaravel\Configs;

class LocalProxyConfig
{
    public const METRICS_FILENAME = 'local-proxy-metrics.json';
    public const STATE_FILENAME   = 'local-proxy-state.json';
    // the Go traceFileNamePattern: \z, not $ - a trailing newline is not part
    // of a valid name, and Go's $ without (?m) matches only the absolute end
    public const HOURLY_FILE_NAME_PATTERN                 = '/^\d{4}_\d{2}_\d{2}_\d{2}\z/';
    public const DEFAULT_METRICS_MAX_SNAPSHOT_AGE_SECONDS = 10;
    public const DEFAULT_ALERT_MAX_PENDING_BYTES          = 104857600;
    public const DEFAULT_ALERT_MAX_MINUTES_WITHOUT_SEND   = 5;

    public function __construct(
        private readonly DispatcherQueueConfig $dispatcherQueueConfig,
    ) {
    }

    public function getTracesPath(): string
    {
        return $this->dispatcherQueueConfig->getLocalProxyTracesPath();
    }

    public function getMetricsMaxSnapshotAgeSeconds(): int
    {
        // applications with a config published before this key existed get the default
        $seconds = (int) config('slogger.local_proxy.metrics.max_snapshot_age_seconds');

        return $seconds > 0
            ? $seconds
            : self::DEFAULT_METRICS_MAX_SNAPSHOT_AGE_SECONDS;
    }

    public function isAlertingEnabled(): bool
    {
        return (bool) config('slogger.local_proxy.metrics.alerting.enabled', false);
    }

    public function getAlertMaxPendingBytes(): int
    {
        $bytes = (int) config('slogger.local_proxy.metrics.alerting.max_pending_bytes');

        return $bytes > 0
            ? $bytes
            : self::DEFAULT_ALERT_MAX_PENDING_BYTES;
    }

    public function getAlertMaxMinutesWithoutSend(): int
    {
        $minutes = (int) config('slogger.local_proxy.metrics.alerting.max_minutes_without_send');

        return $minutes > 0
            ? $minutes
            : self::DEFAULT_ALERT_MAX_MINUTES_WITHOUT_SEND;
    }
}
