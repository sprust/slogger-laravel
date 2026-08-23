<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Job;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Jobs\SyncJob;
use RuntimeException;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

/**
 * `Worker::process()` emits none of the three terminal events for a job that
 * disposes of itself and then throws.
 */
class SelfDisposedJobWatcherTest extends BaseWatcherTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher(JobWatcher::class, null);
    }

    public function testJobReleasingItselfAndThrowingClosesItsTrace(): void
    {
        $job = $this->makeJob();

        event(new JobProcessing('sync', $job));

        // the job body does `$this->release(60); throw ...`
        $job->release(60);

        event(new JobExceptionOccurred('sync', $job, new RuntimeException('boom')));

        $creating = $this->dispatcher->findCreating(
            type: 'job',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);

        self::assertSame(TraceStatusEnum::Failed->value, $updating[0]->status);
        self::assertSame('exception_occurred', $updating[0]->data['status'] ?? null);

        // nothing is left open, so the next job of the worker starts from scratch
        self::assertFalse($this->processor->isActive());
    }

    public function testJobThrowingWithoutDisposingIsClosedByTheReleaseEvent(): void
    {
        $job = $this->makeJob();

        event(new JobProcessing('sync', $job));

        // the job just throws, so the worker releases it after the exception event
        event(new JobExceptionOccurred('sync', $job, new RuntimeException('boom')));

        $job->release(0);

        // no backoff argument: JobReleasedAfterException only takes one from 12.52
        event(new JobReleasedAfterException('sync', $job));

        $creating = $this->dispatcher->findCreating(
            type: 'job',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);

        // the exception event must not preempt the more precise release status
        self::assertSame('released_after_exception', $updating[0]->data['status'] ?? null);
    }

    private function makeJob(): SyncJob
    {
        $payload = json_encode(
            [
                'displayName'             => 'App\\Jobs\\SelfDisposedJob',
                'job'                     => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data'                    => [],
                'slogger_uuid'            => 'slogger-uuid-1',
                'slogger_parent_trace_id' => null,
            ],
            JSON_THROW_ON_ERROR
        );

        return new SyncJob($this->getApp(), $payload, 'sync', 'default');
    }
}
