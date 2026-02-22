<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children;

use Closure;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\Parents\JobWatcher;
use SLoggerLaravel\Watchers\WatcherInterface;

abstract class BaseChildrenTestCase extends BaseTestCase
{
    abstract protected function getTraceType(): string;

    /**
     * @return class-string<WatcherInterface>
     */
    abstract protected function getWatcherClass(): string;

    abstract protected function successCallback(): Closure;

    abstract protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
    ): void;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher($this->getWatcherClass(), null);
    }

    public function testNoParent(): void
    {
        $this->successCallback()();

        $creating = $this->dispatcher->findCreating(
            type: $this->getTraceType(),
            status: TraceStatusEnum::Success,
            isParent: false,
        );

        self::assertCount(
            0,
            $creating
        );
    }

    public function testParentIsJob(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(
            $this->successCallback()
        );

        self::assertEquals(
            3,
            $this->dispatcher->totalCount()
        );

        $creating = $this->dispatcher->findCreating(
            type: $this->getTraceType(),
            status: TraceStatusEnum::Success,
            isParent: false,
        );

        self::assertCount(
            1,
            $creating
        );
    }
}
