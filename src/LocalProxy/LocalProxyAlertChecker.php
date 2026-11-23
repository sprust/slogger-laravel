<?php

namespace SLoggerLaravel\LocalProxy;

use Illuminate\Contracts\Events\Dispatcher;
use SLoggerLaravel\Configs\LocalProxyConfig;
use SLoggerLaravel\Events\LocalProxyBacklogExceededEvent;
use SLoggerLaravel\Events\LocalProxyMetricsUnavailableEvent;
use SLoggerLaravel\Events\LocalProxySendStalledEvent;
use SLoggerLaravel\LocalProxy\Objects\LocalProxyMetricsObject;
use Throwable;

class LocalProxyAlertChecker
{
    private ?int $lastSentOperations          = null;
    private ?string $lastStartedAt            = null;
    private ?float $lastUptimeSeconds         = null;
    private ?float $lastProgressUptimeSeconds = null;
    private bool $backlogAlertActive          = false;
    private bool $stalledAlertActive          = false;
    private bool $unavailableAlertActive      = false;

    public function __construct(
        private readonly LocalProxyConfig $config,
        private readonly Dispatcher $events,
    ) {
    }

    public function check(LocalProxyMetricsObject $metrics): void
    {
        if (!$this->config->isAlertingEnabled()) {
            return;
        }

        // a stale snapshot says nothing about the proxy now; whoever displays
        // metrics reports the staleness, thresholds wait for a fresh one
        if ($metrics->isStale($this->config->getMetricsMaxSnapshotAgeSeconds())) {
            $this->unavailable('stale', $metrics);

            return;
        }

        $this->unavailableAlertActive = false;
        $this->checkBacklog($metrics);
        $this->checkSendProgress($metrics);
    }

    public function unavailable(string $reason = 'unavailable', ?LocalProxyMetricsObject $metrics = null): void
    {
        if (!$this->config->isAlertingEnabled() || $this->unavailableAlertActive) {
            return;
        }

        try {
            $this->events->dispatch(new LocalProxyMetricsUnavailableEvent($reason, $metrics));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $this->unavailableAlertActive = true;
    }

    private function checkBacklog(LocalProxyMetricsObject $metrics): void
    {
        $pendingBytes = $metrics->pendingBytes;

        // unknown without the reader's disk pass - no basis for an alert
        if ($pendingBytes === null) {
            return;
        }

        $threshold = $this->config->getAlertMaxPendingBytes();

        if ($pendingBytes <= $threshold) {
            // back under the threshold: the next crossing fires again
            $this->backlogAlertActive = false;

            return;
        }

        if ($this->backlogAlertActive) {
            return;
        }

        try {
            $this->events->dispatch(
                new LocalProxyBacklogExceededEvent($pendingBytes, $threshold, $metrics)
            );
        } catch (Throwable $exception) {
            // a failing listener (a webhook down, ...) must not mark the
            // alert as delivered: the next tick retries. the error belongs to
            // the application's error reporting, not to the metrics output
            report($exception);

            return;
        }

        $this->backlogAlertActive = true;
    }

    private function checkSendProgress(LocalProxyMetricsObject $metrics): void
    {
        // Unknown is not proof of work to do, and an idle proxy is healthy.
        if ($metrics->pendingBytes === null) {
            return;
        }

        $sentOperations = $metrics->sender->sentOperationsTotal;
        $startedAt      = $metrics->startedAt->toIso8601String();
        $restarted      = ($this->lastStartedAt !== null && $startedAt !== $this->lastStartedAt)
            || ($this->lastUptimeSeconds !== null && $metrics->uptimeSeconds < $this->lastUptimeSeconds);

        $this->lastStartedAt     = $startedAt;
        $this->lastUptimeSeconds = $metrics->uptimeSeconds;

        if ($metrics->pendingBytes === 0) {
            $this->lastSentOperations        = $sentOperations;
            $this->lastProgressUptimeSeconds = $metrics->uptimeSeconds;
            $this->stalledAlertActive        = false;

            return;
        }

        if ($this->lastSentOperations === null
            || $restarted
            || $sentOperations !== $this->lastSentOperations
        ) {
            $this->lastSentOperations        = $sentOperations;
            $this->lastProgressUptimeSeconds = $metrics->uptimeSeconds;
            $this->stalledAlertActive        = false;

            return;
        }

        $thresholdMinutes = $this->config->getAlertMaxMinutesWithoutSend();

        // measured in the proxy's own uptime, not in wall clock: a clock jump
        // on the host must not read as minutes without a send
        /** @var float $lastProgressUptimeSeconds */
        $lastProgressUptimeSeconds = $this->lastProgressUptimeSeconds;
        $minutesWithoutSend        = (int) floor(
            ($metrics->uptimeSeconds - $lastProgressUptimeSeconds) / 60
        );

        if ($minutesWithoutSend < $thresholdMinutes || $this->stalledAlertActive) {
            return;
        }

        try {
            $this->events->dispatch(
                new LocalProxySendStalledEvent($minutesWithoutSend, $thresholdMinutes, $metrics)
            );
        } catch (Throwable $exception) {
            // same contract as the backlog alert: a listener failure is
            // reported and retried on the next tick, never swallowed
            report($exception);

            return;
        }

        $this->stalledAlertActive = true;
    }
}
