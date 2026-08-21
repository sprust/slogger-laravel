<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature;

use Illuminate\Support\Carbon;
use RuntimeException;
use SLoggerLaravel\Dispatcher\Items\DispatcherProcessorInterface;
use SLoggerLaravel\Dispatcher\Items\Memory\MemoryDispatcher;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Dispatcher\Items\TraceDispatcherInterface;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Processor;

class ProcessorTest extends BaseTestCase
{
    public function testTraceDispatchingRunsWithPausedTracing(): void
    {
        $fakeDispatcher = new class implements TraceDispatcherInterface {
            public ?Processor $processor = null;

            /** @var list<bool> */
            public array $pausedStates = [];

            public function getProcessor(): DispatcherProcessorInterface
            {
                throw new RuntimeException('Not supported.');
            }

            public function create(TraceCreateObject $parameters): void
            {
                $this->pausedStates[] = $this->processor?->isPaused() ?? false;
            }

            public function update(TraceUpdateObject $parameters): void
            {
                $this->pausedStates[] = $this->processor?->isPaused() ?? false;
            }
        };

        $app = $this->getApp();

        $app->instance(TraceDispatcherInterface::class, $fakeDispatcher);

        // the Processor singleton is already built with the real dispatcher
        $app->forgetInstance(Processor::class);

        /** @var Processor $processor */
        $processor = $app->make(Processor::class);

        $fakeDispatcher->processor = $processor;

        $traceId = $processor->startAndGetTraceId(
            type: 'test',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null
        );

        $processor->stop(
            traceId: $traceId,
            status: 'success',
            tags: null,
            data: null,
            duration: null,
            parentLoggedAt: Carbon::now()
        );

        // both the create and the update push must run under paused tracing:
        // the push itself fires watchable events and must not be traced recursively
        self::assertSame([true, true], $fakeDispatcher->pausedStates);
        self::assertFalse($processor->isPaused());
    }

    public function testStopClosesInterruptedNestedTraces(): void
    {
        $processor  = $this->getApp()->make(Processor::class);
        $dispatcher = $this->getApp()->make(MemoryDispatcher::class);

        $dispatcher->flush();

        $parentTraceId = $processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null
        );

        $nestedTraceId = $processor->startAndGetTraceId(
            type: 'command',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null
        );

        // the parent is stopped while the nested trace is still open: a queue worker
        // fails a job from its timeout signal handler
        $processor->stop(
            traceId: $parentTraceId,
            status: TraceStatusEnum::Failed->value,
            tags: null,
            data: null,
            duration: null,
            parentLoggedAt: Carbon::now()
        );

        self::assertCount(
            1,
            $dispatcher->findUpdating(
                traceId: $nestedTraceId,
                status: TraceStatusEnum::Failed
            )
        );

        self::assertCount(
            1,
            $dispatcher->findUpdating(
                traceId: $parentTraceId,
                status: TraceStatusEnum::Failed
            )
        );

        self::assertFalse($processor->isActive());
    }

    public function testStopOfAnAlreadyStoppedTraceIsIgnored(): void
    {
        $processor  = $this->getApp()->make(Processor::class);
        $dispatcher = $this->getApp()->make(MemoryDispatcher::class);

        $dispatcher->flush();

        $traceId = $processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null
        );

        $stop = static fn(string $status) => $processor->stop(
            traceId: $traceId,
            status: $status,
            tags: null,
            data: null,
            duration: null,
            parentLoggedAt: Carbon::now()
        );

        $stop(TraceStatusEnum::Success->value);
        $stop(TraceStatusEnum::Failed->value);

        self::assertCount(
            1,
            $dispatcher->findUpdating(traceId: $traceId)
        );

        self::assertCount(
            1,
            $dispatcher->findUpdating(
                traceId: $traceId,
                status: TraceStatusEnum::Success
            )
        );
    }
}
