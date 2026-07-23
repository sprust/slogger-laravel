<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature;

use Illuminate\Support\Carbon;
use RuntimeException;
use SLoggerLaravel\Dispatcher\Items\DispatcherProcessorInterface;
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
}
