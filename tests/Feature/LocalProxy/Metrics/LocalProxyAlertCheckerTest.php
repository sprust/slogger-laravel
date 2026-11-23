<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\LocalProxy\Metrics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use SLoggerLaravel\Events\LocalProxyBacklogExceededEvent;
use SLoggerLaravel\Events\LocalProxyMetricsUnavailableEvent;
use SLoggerLaravel\Events\LocalProxySendStalledEvent;
use SLoggerLaravel\LocalProxy\LocalProxyAlertChecker;

class LocalProxyAlertCheckerTest extends MetricsTestCase
{
    public function testNoEventsWhenAlertingDisabled(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', false);

        $this->checker()->check($this->metrics(pendingBytes: 999999));

        Event::assertNothingDispatched();
    }

    public function testStaleSnapshotFiresUnavailableWithoutThresholdAlerts(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_pending_bytes', 1);

        Carbon::setTestNow('2026-11-19 10:00:00');

        $this->checker()->check(
            $this->metrics(overrides: ['collected_at' => '2026-11-19T09:00:00+00:00'], pendingBytes: 999999)
        );

        Event::assertDispatched(LocalProxyMetricsUnavailableEvent::class, 1);
        Event::assertNotDispatched(LocalProxyBacklogExceededEvent::class);
        Event::assertNotDispatched(LocalProxySendStalledEvent::class);
    }

    public function testBacklogEventFiresOncePerCrossing(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_pending_bytes', 10);

        $checker = $this->checker();

        $checker->check($this->metrics(pendingBytes: 20));
        $checker->check($this->metrics(pendingBytes: 20));

        Event::assertDispatched(LocalProxyBacklogExceededEvent::class, 1);
        Event::assertDispatched(
            LocalProxyBacklogExceededEvent::class,
            static fn(LocalProxyBacklogExceededEvent $event): bool => $event->pendingBytes === 20 && $event->thresholdBytes === 10
        );

        // back under the threshold re-arms the alert
        $checker->check($this->metrics(pendingBytes: 0));
        $checker->check($this->metrics(pendingBytes: 20));

        Event::assertDispatched(LocalProxyBacklogExceededEvent::class, 2);
    }

    public function testBacklogEventPayloadCarriesMetrics(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_pending_bytes', 1);

        $this->checker()->check($this->metrics(pendingBytes: 1500));

        Event::assertDispatched(
            LocalProxyBacklogExceededEvent::class,
            static fn(LocalProxyBacklogExceededEvent $event): bool => $event->metrics->pendingBytes === 1500
        );
    }

    public function testBacklogNotCheckedWithoutComputedPendingBytes(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_pending_bytes', 1);

        // an object built without the reader's disk pass carries no backlog
        $this->checker()->check($this->metrics(pendingBytes: null));

        Event::assertNotDispatched(LocalProxyBacklogExceededEvent::class);
    }

    public function testUnknownBacklogDoesNotRearmActiveAlert(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_pending_bytes', 1);

        $checker = $this->checker();

        $checker->check($this->metrics(pendingBytes: 20));
        $checker->check($this->metrics(pendingBytes: 20));

        // the backlog went unknown: that is not a recovery, the alert must
        // not re-arm and fire again for the same crossing
        $checker->check($this->metrics(pendingBytes: null));
        $checker->check($this->metrics(pendingBytes: 20));

        Event::assertDispatched(LocalProxyBacklogExceededEvent::class, 1);
    }

    public function testAlertIsRetriedWhenListenerFails(): void
    {
        // no Event::fake: the failure semantics of the real dispatcher are
        // the point
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_pending_bytes', 1);

        $calls = 0;

        Event::listen(
            LocalProxyBacklogExceededEvent::class,
            static function () use (&$calls): void {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('webhook down');
                }
            }
        );

        $checker = $this->checker();

        // the listener failure is reported, and the alert is not marked delivered
        $checker->check($this->metrics(pendingBytes: 20));
        self::assertSame(1, $calls);

        // the next tick retries and succeeds
        $checker->check($this->metrics(pendingBytes: 20));
        self::assertSame(2, $calls);

        // delivered now: no repeat for the same crossing
        $checker->check($this->metrics(pendingBytes: 20));
        self::assertSame(2, $calls);
    }

    public function testSendStalledEventAfterThresholdMinutes(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_minutes_without_send', 5);

        $checker = $this->checker();

        // the first check only starts the clock
        $checker->check($this->metrics(['uptime_seconds' => 3600]));
        Event::assertNotDispatched(LocalProxySendStalledEvent::class);

        // the stall is measured in the proxy's own uptime: 4:59 without a send
        $checker->check($this->metrics(['uptime_seconds' => 3600 + 299]));
        Event::assertNotDispatched(LocalProxySendStalledEvent::class);

        $checker->check($this->metrics(['uptime_seconds' => 3600 + 300]));
        Event::assertDispatched(LocalProxySendStalledEvent::class, 1);
        Event::assertDispatched(
            LocalProxySendStalledEvent::class,
            static fn(LocalProxySendStalledEvent $event): bool => $event->minutesWithoutSend === 5 && $event->thresholdMinutes === 5
        );

        // still stalled: no repeat
        $checker->check($this->metrics(['uptime_seconds' => 3600 + 360]));
        Event::assertDispatched(LocalProxySendStalledEvent::class, 1);

        // progress re-arms the alert
        $checker->check(
            $this->metrics(['uptime_seconds' => 3600 + 390, 'sender' => ['sent_operations_total' => 99]])
        );

        $checker->check(
            $this->metrics(['uptime_seconds' => 3600 + 390 + 300, 'sender' => ['sent_operations_total' => 99]])
        );

        Event::assertDispatched(LocalProxySendStalledEvent::class, 2);
    }

    public function testWallClockJumpBackwardDoesNotReadAsStall(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_minutes_without_send', 5);

        $checker = $this->checker();

        Carbon::setTestNow('2026-11-19 10:05:00');
        $checker->check($this->metrics(['uptime_seconds' => 400]));

        // the host clock jumps back five minutes; the proxy has been up for
        // one more second, and only that second counts
        Carbon::setTestNow('2026-11-19 09:59:59');
        $checker->check($this->metrics(['uptime_seconds' => 401]));

        Event::assertNotDispatched(LocalProxySendStalledEvent::class);
    }

    public function testCounterResetRestartsTheClock(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_minutes_without_send', 5);

        $checker = $this->checker();

        $checker->check(
            $this->metrics(['uptime_seconds' => 400, 'sender' => ['sent_operations_total' => 100]])
        );

        // the proxy restarted: its process-lifetime counter dropped
        $checker->check(
            $this->metrics(['uptime_seconds' => 2, 'sender' => ['sent_operations_total' => 2]])
        );

        $checker->check(
            $this->metrics(['uptime_seconds' => 2 + 299, 'sender' => ['sent_operations_total' => 2]])
        );
        Event::assertNotDispatched(LocalProxySendStalledEvent::class);

        $checker->check(
            $this->metrics(['uptime_seconds' => 2 + 300, 'sender' => ['sent_operations_total' => 2]])
        );
        Event::assertDispatched(LocalProxySendStalledEvent::class, 1);
    }

    public function testUptimeRollbackRestartsTheClockWithoutCounterChange(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_minutes_without_send', 5);

        $checker = $this->checker();

        $checker->check(
            $this->metrics(['uptime_seconds' => 400, 'sender' => ['sent_operations_total' => 0]])
        );

        // the proxy restarted before its first send: the counter reads the
        // same 0, only the rolled-back uptime tells the processes apart
        $checker->check(
            $this->metrics(['uptime_seconds' => 1, 'sender' => ['sent_operations_total' => 0]])
        );
        Event::assertNotDispatched(LocalProxySendStalledEvent::class);

        // one minute of uptime past the restart baseline: still no alert
        $checker->check(
            $this->metrics(['uptime_seconds' => 61, 'sender' => ['sent_operations_total' => 0]])
        );
        Event::assertNotDispatched(LocalProxySendStalledEvent::class);

        // five minutes of uptime past the restart baseline it fires
        $checker->check(
            $this->metrics(['uptime_seconds' => 301, 'sender' => ['sent_operations_total' => 0]])
        );
        Event::assertDispatched(LocalProxySendStalledEvent::class, 1);
    }

    public function testIdleProxyDoesNotFireSendStalled(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_minutes_without_send', 5);

        $checker = $this->checker();
        $checker->check($this->metrics(['uptime_seconds' => 100], 0));
        $checker->check($this->metrics(['uptime_seconds' => 400], 0));

        Event::assertNotDispatched(LocalProxySendStalledEvent::class);
    }

    public function testRestartDuringStaleGapRestartsClockWithSameCounter(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);
        config()->set('slogger.local_proxy.metrics.alerting.max_minutes_without_send', 5);

        Carbon::setTestNow('2026-11-23 12:00:00');
        $checker = $this->checker();
        $sender  = ['sent_operations_total' => 100];
        $checker->check($this->metrics(['uptime_seconds' => 100, 'sender' => $sender], 10));
        $checker->check($this->metrics([
            'collected_at'   => '2026-11-23T11:00:00+00:00',
            'uptime_seconds' => 150,
            'sender'         => $sender,
        ], 10));

        $newProcess = [
            'started_at' => '2026-11-23T11:55:00+00:00',
            'sender'     => $sender,
        ];
        $checker->check($this->metrics($newProcess + ['uptime_seconds' => 400], 10));
        $checker->check($this->metrics($newProcess + ['uptime_seconds' => 699], 10));
        Event::assertNotDispatched(LocalProxySendStalledEvent::class);

        $checker->check($this->metrics($newProcess + ['uptime_seconds' => 700], 10));
        Event::assertDispatched(LocalProxySendStalledEvent::class, 1);
    }

    public function testUnavailableAlertRearmsAfterHealthySnapshot(): void
    {
        Event::fake();
        config()->set('slogger.local_proxy.metrics.alerting.enabled', true);

        $checker = $this->checker();
        $checker->unavailable();
        $checker->unavailable();
        Event::assertDispatched(LocalProxyMetricsUnavailableEvent::class, 1);

        $checker->check($this->metrics(pendingBytes: 0));
        $checker->unavailable('stale');
        Event::assertDispatched(LocalProxyMetricsUnavailableEvent::class, 2);
    }

    private function checker(): LocalProxyAlertChecker
    {
        // resolved after Event::fake() so the checker dispatches onto the fake
        return $this->getApp()->make(LocalProxyAlertChecker::class);
    }
}
