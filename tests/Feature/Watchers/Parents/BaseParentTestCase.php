<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents;

use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\Children\EventWatcher;
use SLoggerLaravel\Watchers\WatcherInterface;
use SLoggerTestEntities\NestedEvent;

abstract class BaseParentTestCase extends BaseTestCase
{
    abstract protected function getTraceType(): string;

    /**
     * @return class-string<WatcherInterface>
     */
    abstract protected function getWatcherClass(): string;

    abstract protected function runSuccess(): void;

    abstract protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
    ): void;

    abstract protected function runFailed(): void;

    abstract protected function assertFailed(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
    ): void;

    abstract protected function runWithNestedEvent(): void;

    abstract protected function assertWithNestedEvent(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
        TraceCreateObject $creatingEventTrace,
    ): void;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher($this->getWatcherClass());
    }

    public function testSuccess(): void
    {
        $this->runSuccess();

        $creating = $this->dispatcher->findCreating(
            type: $this->getTraceType(),
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );

        $creatingTrace = $creating[0];

        $updating = $this->dispatcher->findUpdating(
            traceId: $creatingTrace->traceId,
            status: TraceStatusEnum::Success,
        );

        self::assertCount(
            1,
            $updating
        );

        $this->assertSuccess(
            creatingTrace: $creatingTrace,
            updatingTrace: $updating[0]
        );
    }

    public function testFailed(): void
    {
        $this->runFailed();

        $creating = $this->dispatcher->findCreating(
            type: $this->getTraceType(),
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );

        $creatingTrace = $creating[0];

        $updating = $this->dispatcher->findUpdating(
            traceId: $creatingTrace->traceId,
            status: TraceStatusEnum::Failed,
        );

        self::assertCount(
            1,
            $updating
        );

        $this->assertFailed(
            creatingTrace: $creatingTrace,
            updatingTrace: $updating[0]
        );
    }

    public function testWithNestedEvent(): void
    {
        $this->registerWatcher(EventWatcher::class);

        $this->runWithNestedEvent();

        $creating = $this->dispatcher->findCreating(
            type: $this->getTraceType(),
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(
            1,
            $creating
        );

        $creatingTrace = $creating[0];

        $updating = $this->dispatcher->findUpdating(
            traceId: $creatingTrace->traceId,
            status: TraceStatusEnum::Success,
        );

        self::assertCount(
            1,
            $updating
        );

        $creatingEvents = $this->dispatcher->findCreating(
            parentTraceId: $creatingTrace->traceId,
            type: 'event',
            status: TraceStatusEnum::Success,
            tag: NestedEvent::class,
            isParent: false,
        );

        self::assertCount(
            1,
            $creatingEvents
        );

        $this->assertWithNestedEvent(
            creatingTrace: $creatingTrace,
            updatingTrace: $updating[0],
            creatingEventTrace: $creatingEvents[0],
        );
    }
}
