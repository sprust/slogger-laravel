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
            tags: ['nested'],
            data: ['kept' => true],
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

        $updatedNested = $dispatcher->findUpdating(
            traceId: $nestedTraceId,
            status: TraceStatusEnum::Failed
        );

        self::assertCount(1, $updatedNested);

        // an update replaces the data, so the interrupted trace keeps what it had
        // collected on start and is marked by a tag instead
        self::assertNull($updatedNested[0]->data);

        self::assertSame(
            ['nested', Processor::INTERRUPTED_TAG],
            $updatedNested[0]->tags
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

    public function testStopOfAMiddleTraceKeepsTheOuterOneRunning(): void
    {
        $processor  = $this->getApp()->make(Processor::class);
        $dispatcher = $this->getApp()->make(MemoryDispatcher::class);

        $dispatcher->flush();

        $outerTraceId = $processor->startAndGetTraceId(
            type: 'command',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null
        );

        $middleTraceId = $processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null
        );

        $innerTraceId = $processor->startAndGetTraceId(
            type: 'command',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null
        );

        $processor->stop(
            traceId: $middleTraceId,
            status: TraceStatusEnum::Failed->value,
            tags: null,
            data: null,
            duration: null,
            parentLoggedAt: Carbon::now()
        );

        // only the traces above the stopped one are interrupted
        self::assertCount(1, $dispatcher->findUpdating(traceId: $innerTraceId));
        self::assertCount(1, $dispatcher->findUpdating(traceId: $middleTraceId));
        self::assertCount(0, $dispatcher->findUpdating(traceId: $outerTraceId));

        self::assertTrue($processor->isActive());

        // the outer trace becomes the parent again, so it still collects children
        $processor->push(type: 'log', status: TraceStatusEnum::Success->value);

        $children = $dispatcher->findCreating(
            parentTraceId: $outerTraceId,
            type: 'log',
            isParent: false,
        );

        self::assertCount(1, $children);

        $processor->stop(
            traceId: $outerTraceId,
            status: TraceStatusEnum::Success->value,
            tags: null,
            data: null,
            duration: null,
            parentLoggedAt: Carbon::now()
        );

        self::assertFalse($processor->isActive());
    }

    public function testStopClosesDetachedTracesTheParentLeftOpen(): void
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

        // an outbound request that never gets a response: the Guzzle handler throws
        // synchronously, so the watcher is never told how it ended
        $detachedTraceId = $processor->startAndGetDetachedTraceId(
            type: 'http-client',
            tags: ['https://example.test/alpha'],
            data: ['kept' => true],
            loggedAt: Carbon::now()
        );

        $processor->stop(
            traceId: $parentTraceId,
            status: TraceStatusEnum::Success->value,
            tags: null,
            data: null,
            duration: null,
            parentLoggedAt: Carbon::now()
        );

        $updatedDetached = $dispatcher->findUpdating(
            traceId: $detachedTraceId,
            status: TraceStatusEnum::Failed
        );

        self::assertCount(1, $updatedDetached);

        self::assertNull($updatedDetached[0]->data);

        self::assertSame(
            ['https://example.test/alpha', Processor::INTERRUPTED_TAG],
            $updatedDetached[0]->tags
        );

        self::assertFalse($processor->isActive());
    }

    public function testTheTwoStopMethodsAreInterchangeable(): void
    {
        $processor  = $this->getApp()->make(Processor::class);
        $dispatcher = $this->getApp()->make(MemoryDispatcher::class);

        $dispatcher->flush();

        $stackedTraceId = $processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null
        );

        $detachedTraceId = $processor->startAndGetDetachedTraceId(
            type: 'http-client',
            tags: [],
            data: [],
            loggedAt: Carbon::now()
        );

        // both are closed through the wrong method on purpose: a mismatch must not
        // corrupt the stack or leave a trace open
        $processor->stop(
            traceId: $detachedTraceId,
            status: TraceStatusEnum::Success->value,
            tags: null,
            data: null,
            duration: null,
            parentLoggedAt: Carbon::now()
        );

        $processor->stopDetached(
            traceId: $stackedTraceId,
            status: TraceStatusEnum::Success->value,
            tags: null,
            data: null,
            duration: null,
            parentLoggedAt: Carbon::now()
        );

        self::assertCount(
            1,
            $dispatcher->findUpdating(
                traceId: $detachedTraceId,
                status: TraceStatusEnum::Success
            )
        );

        self::assertCount(
            1,
            $dispatcher->findUpdating(
                traceId: $stackedTraceId,
                status: TraceStatusEnum::Success
            )
        );

        // the detached one was closed on its own, so it must not be marked interrupted
        self::assertNotContains(
            Processor::INTERRUPTED_TAG,
            $dispatcher->findUpdating(traceId: $detachedTraceId)[0]->tags ?? []
        );

        self::assertFalse($processor->isActive());
    }

    public function testDetachedTracesWithoutAnOwnerAreClosedWhenWorkEnds(): void
    {
        $processor  = $this->getApp()->make(Processor::class);
        $dispatcher = $this->getApp()->make(MemoryDispatcher::class);

        $dispatcher->flush();

        // started before anything else: there is no parent trace to own it, so nothing
        // else can ever reach it
        $orphanTraceId = $processor->startAndGetDetachedTraceId(
            type: 'http-client',
            tags: [],
            data: [],
            loggedAt: Carbon::now()
        );

        $parentTraceId = $processor->startAndGetTraceId(
            type: 'job',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null
        );

        $processor->stop(
            traceId: $parentTraceId,
            status: TraceStatusEnum::Success->value,
            tags: null,
            data: null,
            duration: null,
            parentLoggedAt: Carbon::now()
        );

        self::assertCount(
            1,
            $dispatcher->findUpdating(
                traceId: $orphanTraceId,
                status: TraceStatusEnum::Failed
            )
        );

        self::assertFalse($processor->isActive());
    }
}
