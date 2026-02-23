<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Items;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Dispatcher\ApiClients\ApiClientInterface;
use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\SendTracesJob;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use Throwable;

class SendTracesJobTest extends BaseTestCase
{
    /**
     * @throws Throwable
     */
    public function testHandleCallsApiClient(): void
    {
        $job = new SendTracesJob($this->makeTraces());

        $processor = $this->getMockBuilder(Processor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['handleWithoutTracing'])
            ->getMock();

        $processor->expects(self::once())
            ->method('handleWithoutTracing')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $apiClient = $this->createMock(ApiClientInterface::class);
        $apiClient->expects(self::once())
            ->method('sendTraces');

        $job->handle($processor, $apiClient, new GeneralConfig());
    }

    /**
     * @throws Throwable
     */
    public function testHandleThrowsWhenNoJobAndApiClientFails(): void
    {
        $job = new SendTracesJob($this->makeTraces());

        $processor = $this->getMockBuilder(Processor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['handleWithoutTracing'])
            ->getMock();

        $processor->method('handleWithoutTracing')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $apiClient = $this->createMock(ApiClientInterface::class);
        $apiClient->method('sendTraces')
            ->willThrowException(new RuntimeException('fail'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fail');

        $job->handle($processor, $apiClient, new GeneralConfig());
    }

    /**
     * @throws Throwable
     */
    public function testHandleReleasesWhenAttemptsLeft(): void
    {
        $job = new SendTracesJob($this->makeTraces());

        $processor = $this->getMockBuilder(Processor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['handleWithoutTracing'])
            ->getMock();

        $processor->method('handleWithoutTracing')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $apiClient = $this->createMock(ApiClientInterface::class);
        $apiClient->method('sendTraces')
            ->willThrowException(new RuntimeException('fail'));

        $queueJob = new class() {
            public int $releaseCount = 0;
            public int $deleteCount  = 0;

            public function attempts(): int
            {
                return 1;
            }

            public function release(int $delay = 0): void
            {
                $this->releaseCount++;
            }

            public function delete(): void
            {
                $this->deleteCount++;
            }
        };

        $this->setQueueJob($job, $queueJob);

        $job->handle($processor, $apiClient, new GeneralConfig());

        self::assertSame(1, $queueJob->releaseCount);
        self::assertSame(0, $queueJob->deleteCount);
    }

    /**
     * @throws Throwable
     */
    public function testHandleDeletesAndLogsWhenAttemptsExceeded(): void
    {
        $job = new SendTracesJob($this->makeTraces());

        $processor = $this->getMockBuilder(Processor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['handleWithoutTracing'])
            ->getMock();

        $processor->method('handleWithoutTracing')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        $apiClient = $this->createMock(ApiClientInterface::class);
        $apiClient->method('sendTraces')
            ->willThrowException(new RuntimeException('fail'));

        $queueJob = new class() {
            public int $releaseCount = 0;
            public int $deleteCount  = 0;

            public function attempts(): int
            {
                return 120;
            }

            public function release(int $delay = 0): void
            {
                $this->releaseCount++;
            }

            public function delete(): void
            {
                $this->deleteCount++;
            }
        };

        $this->setQueueJob($job, $queueJob);

        Log::shouldReceive('channel')
            ->once()
            ->andReturn(new NullLogger());

        $job->handle($processor, $apiClient, new GeneralConfig());

        self::assertSame(0, $queueJob->releaseCount);
        self::assertSame(1, $queueJob->deleteCount);
    }

    private function makeTraces(): TracesObject
    {
        return (new TracesObject())->addCreating(
            new TraceCreateObject(
                traceId: 'trace-1',
                parentTraceId: null,
                type: 'request',
                status: 'started',
                tags: [],
                data: [],
                duration: null,
                memory: null,
                cpu: null,
                isParent: true,
                loggedAt: Carbon::create(2024, 1, 1, 0, 0, 0, 'UTC')
                    ?: throw new RuntimeException('Failed to create Carbon instance')
            )
        );
    }

    private function setQueueJob(SendTracesJob $job, object $queueJob): void
    {
        $reflection = new ReflectionClass($job);
        $property   = $reflection->getProperty('job');
        $property->setAccessible(true);
        $property->setValue($job, $queueJob);
    }
}
