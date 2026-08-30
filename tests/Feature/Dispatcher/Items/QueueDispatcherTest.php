<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Items;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use JsonException;
use ReflectionClass;
use RuntimeException;
use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\SendTracesJob;
use SLoggerLaravel\Dispatcher\Items\Queue\QueueDispatcher;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

class QueueDispatcherTest extends BaseTestCase
{
    public function testCreateParentDispatchesImmediately(): void
    {
        Bus::fake();

        $dispatcher = new QueueDispatcher($this->getApp());

        $dispatcher->create(
            $this->makeCreateTrace(isParent: true, parentTraceId: null)
        );

        Bus::assertDispatched(
            SendTracesJob::class,
            function (SendTracesJob $job) {
                $traces = $this->getJobTraces($job);

                return $traces->count() === 1;
            }
        );
    }

    public function testCreateNonParentWithoutParentDispatchesImmediately(): void
    {
        Bus::fake();

        $dispatcher = new QueueDispatcher($this->getApp());

        $dispatcher->create(
            $this->makeCreateTrace(isParent: false, parentTraceId: null)
        );

        Bus::assertDispatched(SendTracesJob::class);
    }

    public function testCreateNonParentWithParentBatches(): void
    {
        Bus::fake();

        $dispatcher = new QueueDispatcher($this->getApp());
        $this->setMaxBatchSize($dispatcher, 2);

        $dispatcher->create(
            $this->makeCreateTrace(isParent: false, parentTraceId: 'parent-1')
        );

        Bus::assertNotDispatched(SendTracesJob::class);

        $dispatcher->create(
            $this->makeCreateTrace(isParent: false, parentTraceId: 'parent-1')
        );

        Bus::assertDispatched(SendTracesJob::class);
    }

    /**
     * The buffer is handed over to the job and replaced *before* anything is
     * dispatched. Dispatching publishes to a broker, which is a suspension point under
     * a coroutine runtime, so a neighbour writes into the buffer while this one is
     * asleep - and what it writes has to reach the next batch rather than the one
     * already on its way, or the object about to be thrown away.
     *
     * Which is why the bus is stubbed rather than faked: the write has to happen
     * inside the dispatch, and `Bus::fake()` never re-enters the dispatcher.
     */
    public function testABufferWrittenToDuringADispatchLosesNothing(): void
    {
        $dispatcher = new QueueDispatcher($this->getApp());
        $this->setMaxBatchSize($dispatcher, 2);

        $batches = [];

        $neighbourWrote = false;

        $bus = $this->createMock(BusDispatcher::class);

        $bus->method('dispatch')->willReturnCallback(
            function (object $job) use (&$batches, &$neighbourWrote, $dispatcher): void {
                self::assertInstanceOf(SendTracesJob::class, $job);

                // the neighbouring coroutine, writing while this one is publishing
                if (!$neighbourWrote) {
                    $neighbourWrote = true;

                    $dispatcher->create(
                        $this->makeCreateTrace(isParent: false, parentTraceId: 'neighbour')
                    );
                }

                $batches[] = $this->getJobTraces($job);
            }
        );

        $this->getApp()->instance(BusDispatcher::class, $bus);

        $dispatcher->create($this->makeCreateTrace(isParent: false, parentTraceId: 'first'));
        $dispatcher->create($this->makeCreateTrace(isParent: false, parentTraceId: 'second'));

        self::assertTrue($neighbourWrote, 'the batch must have been dispatched by now');

        $dispatcher->create($this->makeCreateTrace(isParent: false, parentTraceId: 'third'));

        self::assertSame(
            [2, 2],
            array_map(static fn(TracesObject $traces): int => $traces->count(), $batches),
            'two batches of two: the neighbour went out with the next one'
        );

        $sent = [];

        foreach ($batches as $traces) {
            foreach ($traces->iterateCreating() as $trace) {
                $sent[] = $trace->parentTraceId;
            }
        }

        sort($sent);

        // every trace once: none dropped with the replaced buffer, none sent twice
        self::assertSame(['first', 'neighbour', 'second', 'third'], $sent);
    }

    public function testUpdateDispatchesImmediately(): void
    {
        Bus::fake();

        $dispatcher = new QueueDispatcher($this->getApp());

        $dispatcher->update(
            $this->makeUpdateTrace()
        );

        Bus::assertDispatched(SendTracesJob::class);
    }

    public function testCreateSwallowsDispatchFailures(): void
    {
        // telemetry must never break the app: a dispatch failure is swallowed and
        // dropped, never thrown into the caller
        config()->set('slogger.dispatchers.queue.connection', '');

        $dispatcher = new QueueDispatcher($this->getApp());

        $dispatcher->create(
            $this->makeCreateTrace(isParent: true, parentTraceId: null)
        );

        // reaching this line means no exception escaped create()
        $this->addToAssertionCount(1);
    }

    public function testUpdateSwallowsDispatchFailures(): void
    {
        config()->set('slogger.dispatchers.queue.connection', '');

        $dispatcher = new QueueDispatcher($this->getApp());

        $dispatcher->update(
            $this->makeUpdateTrace()
        );

        $this->addToAssertionCount(1);
    }

    private function makeCreateTrace(bool $isParent, ?string $parentTraceId): TraceCreateObject
    {
        return new TraceCreateObject(
            traceId: 'trace-1',
            parentTraceId: $parentTraceId,
            type: 'request',
            status: 'started',
            tags: ['tag'],
            data: ['key' => 'value'],
            duration: 1.2,
            memory: 12.0,
            cpu: 1.0,
            isParent: $isParent,
            loggedAt: Carbon::create(2024, 1, 1, 0, 0, 0)
                ?: throw new RuntimeException('Failed to create Carbon instance')
        );
    }

    private function makeUpdateTrace(): TraceUpdateObject
    {
        return new TraceUpdateObject(
            traceId: 'trace-1',
            status: 'success',
            profiling: null,
            tags: ['tag'],
            data: ['key' => 'value'],
            duration: 1.2,
            memory: 12.0,
            cpu: 1.0,
            parentLoggedAt: Carbon::create(2024, 1, 1, 0, 0, 0)
                ?: throw new RuntimeException('Failed to create Carbon instance')
        );
    }

    private function setMaxBatchSize(QueueDispatcher $dispatcher, int $size): void
    {
        $reflection = new ReflectionClass($dispatcher);
        $property   = $reflection->getProperty('maxBatchSize');
        $property->setAccessible(true);
        $property->setValue($dispatcher, $size);
    }

    /**
     * @throws JsonException
     */
    private function getJobTraces(SendTracesJob $job): TracesObject
    {
        $reflection = new ReflectionClass($job);
        $property   = $reflection->getProperty('tracesJson');
        $property->setAccessible(true);

        $tracesJson = $property->getValue($job);

        return TracesObject::fromJson($tracesJson);
    }
}
