<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Concurrency;

use Fiber;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

/**
 * The same as the requests, on the path that overtook them: the queues moved from a
 * process per worker to a pool of coroutine consumers, so a dozen deliveries share
 * one process.
 */
class InterleavedJobsTest extends BaseConcurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher(JobWatcher::class, null);
    }

    public function testNeitherJobIsFiledUnderTheOther(): void
    {
        $this->interleave([
            fn() => $this->processJob('uuid-first', 'App\\Jobs\\First'),
            fn() => $this->processJob('uuid-second', 'App\\Jobs\\Second'),
        ]);

        $creating = $this->dispatcher->findCreating(type: 'job', isParent: true);

        self::assertCount(2, $creating, 'both jobs must have produced a trace');

        $traceIds = array_map(static fn($trace) => $trace->traceId, $creating);

        foreach ($creating as $trace) {
            self::assertNull(
                $trace->parentTraceId,
                'a job published outside any trace is a root trace'
            );
        }

        self::assertCount(2, array_unique($traceIds), 'two units, two traces');

        foreach ($traceIds as $traceId) {
            self::assertCount(
                1,
                $this->dispatcher->findUpdating(traceId: $traceId, status: TraceStatusEnum::Success)
            );
        }

        self::assertSame([], $this->dispatcher->findUpdating(tag: Processor::INTERRUPTED_TAG));
    }

    /**
     * A job carries its own parent in its payload, which is what a delivery in another
     * process reads. Nothing here is published, so both are roots.
     */
    private function processJob(string $uuid, string $displayName): void
    {
        $job = $this->makeJob($uuid, $displayName);

        event(new JobProcessing('sync', $job));

        Fiber::suspend();

        event(new JobProcessed('sync', $job));
    }

    private function makeJob(string $uuid, string $displayName): Job
    {
        $job = $this->createMock(Job::class);

        $job->method('payload')
            ->willReturn(
                [
                    'displayName'             => $displayName,
                    'slogger_uuid'            => $uuid,
                    'slogger_parent_trace_id' => null,
                    'data'                    => [],
                ]
            );

        return $job;
    }
}
