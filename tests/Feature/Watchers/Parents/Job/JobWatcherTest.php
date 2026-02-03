<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Job;

use RuntimeException;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\Children\EventWatcher;
use SLoggerLaravel\Watchers\Parents\JobWatcher;
use SLoggerTestEntities\EmptyEvent;
use Throwable;

class JobWatcherTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher(JobWatcher::class);
    }

    public function testSuccess(): void
    {
        dispatch(static fn() => null);

        $creating = $this->dispatcher->findCreating(
            type: 'job',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );

        $updating = $this->dispatcher->findUpdating(
            traceId: $creating[0]->traceId,
            status: TraceStatusEnum::Success,
        );

        self::assertCount(
            1,
            $updating
        );
    }

    public function testException(): void
    {
        $message = uniqid();

        $exception = null;

        try {
            dispatch(static fn() => throw new RuntimeException($message));
        } catch (Throwable $exception) {
            //
        }

        self::assertNotNull($exception);

        $creating = $this->dispatcher->findCreating(
            type: 'job',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );

        $updating = $this->dispatcher->findUpdating(
            traceId: $creating[0]->traceId,
            status: TraceStatusEnum::Failed,
        );

        self::assertCount(
            1,
            $updating
        );
    }

    public function testNested(): void
    {
        $this->registerWatcher(EventWatcher::class);

        dispatch(static fn() => event(new EmptyEvent()));

        $creating = $this->dispatcher->findCreating(
            type: 'job',
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );

        $updating = $this->dispatcher->findUpdating(
            traceId: $creating[0]->traceId,
            status: TraceStatusEnum::Success,
        );

        self::assertCount(
            1,
            $updating
        );

        $creating = $this->dispatcher->findCreating(
            parentTraceId: $creating[0]->traceId,
            type: 'event',
            status: TraceStatusEnum::Success,
            tag: EmptyEvent::class,
            isParent: false,
        );

        self::assertCount(
            1,
            $creating
        );
    }
}
