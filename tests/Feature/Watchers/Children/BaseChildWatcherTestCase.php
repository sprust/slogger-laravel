<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children;

use Closure;
use ReflectionException;
use ReflectionFunction;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\JobWatcher;
use SLoggerLaravel\Watchers\WatcherInterface;

abstract class BaseChildWatcherTestCase extends BaseWatcherTestCase
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

    /**
     * @throws ReflectionException
     */
    public function testNoParent(): void
    {
        $this->getSuccessCallback()();

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

    /**
     * @throws ReflectionException
     */
    public function testParentIsJob(): void
    {
        $this->registerWatcher(JobWatcher::class, null);

        dispatch(
            $this->getSuccessCallback()
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

    /**
     * @throws ReflectionException
     */
    protected function getSuccessCallback(): Closure
    {
        $callback = $this->successCallback();

        $reflection = new ReflectionFunction($callback);

        self::assertTrue(
            $reflection->isStatic(),
            'Callback must be static'
        );

        return $callback;
    }
}
