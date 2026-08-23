<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Job;

use App\Jobs\TimedOutJob;
use Illuminate\Support\Facades\Event;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Events\WatcherErrorEvent;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\JobWatcher;

class TimedOutJobWatcherTest extends BaseWatcherTestCase
{
    /**
     * @var list<WatcherErrorEvent>
     */
    private array $watcherErrors = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->watcherErrors = [];

        Event::listen(
            WatcherErrorEvent::class,
            function (WatcherErrorEvent $event): void {
                $this->watcherErrors[] = $event;
            }
        );

        $this->registerWatcher(JobWatcher::class, null);
    }

    public function testJobFailedByTimeoutWhileNestedTraceIsOpen(): void
    {
        dispatch(new TimedOutJob(failing: true, nesting: true));

        self::assertSame([], $this->getWatcherErrorMessages());

        $creating = $this->dispatcher->findCreating(
            type: 'job',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(1, $creating);

        $jobTraceId = $creating[0]->traceId;

        // the job trace must be closed exactly once, as failed
        self::assertCount(
            1,
            $this->dispatcher->findUpdating(traceId: $jobTraceId)
        );

        self::assertCount(
            1,
            $this->dispatcher->findUpdating(
                traceId: $jobTraceId,
                status: TraceStatusEnum::Failed
            )
        );

        $nested = $this->dispatcher->findCreating(
            parentTraceId: $jobTraceId,
            type: 'command',
            status: TraceStatusEnum::Started,
            tag: 'nested',
            isParent: true,
        );

        self::assertCount(1, $nested);

        // the interrupted nested trace must not hang in the "started" status
        $updatedNested = $this->dispatcher->findUpdating(
            traceId: $nested[0]->traceId,
            status: TraceStatusEnum::Failed
        );

        self::assertCount(1, $updatedNested);

        // an update replaces the data, so the interrupted trace must keep what it had
        // collected on start and be marked by a tag instead
        self::assertNull($updatedNested[0]->data);

        self::assertSame(
            ['nested', Processor::INTERRUPTED_TAG],
            $updatedNested[0]->tags
        );
    }

    public function testJobRetriedAfterTimeoutClosesItsTrace(): void
    {
        dispatch(new TimedOutJob(failing: false, nesting: true));

        self::assertSame([], $this->getWatcherErrorMessages());

        $creating = $this->dispatcher->findCreating(
            type: 'job',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(1, $creating);

        // the worker kills itself right after the timeout, so the trace has to be
        // closed by the JobTimedOut event even though the job was not failed
        $updating = $this->dispatcher->findUpdating(
            traceId: $creating[0]->traceId,
            status: TraceStatusEnum::Failed
        );

        self::assertCount(1, $updating);

        self::assertSame('timed_out', $updating[0]->data['status'] ?? null);
    }

    public function testJobRetriedAfterTimeoutWithoutNestedTracesClosesItsTrace(): void
    {
        dispatch(new TimedOutJob(failing: false, nesting: false));

        self::assertSame([], $this->getWatcherErrorMessages());

        $creating = $this->dispatcher->findCreating(
            type: 'job',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(1, $creating);

        // nothing but the JobTimedOut listener can close this trace: the worker neither
        // fails the job nor lets it finish, it kills itself right after the timeout
        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);

        self::assertSame(TraceStatusEnum::Failed->value, $updating[0]->status);
        self::assertSame('timed_out', $updating[0]->data['status'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function getWatcherErrorMessages(): array
    {
        return array_map(
            static fn(WatcherErrorEvent $event): string => $event->exception->getMessage(),
            $this->watcherErrors
        );
    }
}
