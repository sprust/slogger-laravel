<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Items;

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

    public function testUpdateDispatchesImmediately(): void
    {
        Bus::fake();

        $dispatcher = new QueueDispatcher($this->getApp());

        $dispatcher->update(
            $this->makeUpdateTrace()
        );

        Bus::assertDispatched(SendTracesJob::class);
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
