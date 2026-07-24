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
    protected function setUp(): void
    {
        parent::setUp();

        SendTracesJob::resetDropStats();
    }

    public function testTriesAndBackoffAreHardcoded(): void
    {
        $job = new SendTracesJob($this->makeTraces());

        // tries = count(backoff) + 1: every backoff pause is used before the drop
        self::assertSame(5, $job->tries);
        self::assertSame([1, 10, 30, 60], $job->backoff);
        self::assertSame(count($job->backoff) + 1, $job->tries);
    }

    public function testBackoffAcceptsIntAssignedByQueueDriver(): void
    {
        // some queue drivers (e.g. laravel-queue-rabbitmq) assign a computed int
        // back to $backoff when releasing a job; a typed array property would throw
        // a TypeError here and break the retry/drop machinery.
        $job = new SendTracesJob($this->makeTraces());

        $job->backoff = 10;

        self::assertSame(10, $job->backoff);
    }

    /**
     * @throws Throwable
     */
    public function testHandleCallsApiClient(): void
    {
        $job = new SendTracesJob($this->makeTraces());

        $processor = $this->makeProcessor();

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

        $processor = $this->makeProcessor();

        $apiClient = $this->makeFailingApiClient();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fail');

        $job->handle($processor, $apiClient, new GeneralConfig());
    }

    /**
     * @throws Throwable
     */
    public function testHandleRethrowsWhenAttemptsLeft(): void
    {
        $job = new SendTracesJob($this->makeTraces());

        $queueJob = $this->makeQueueJob(attempts: 1);

        $this->setQueueJob($job, $queueJob);

        $exception = null;

        try {
            $job->handle($this->makeProcessor(), $this->makeFailingApiClient(), new GeneralConfig());
        } catch (Throwable $exception) {
            // keep for assertions below
        }

        // the exception is rethrown: retries and backoff are managed by Laravel
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame(0, $queueJob->releaseCount);
        self::assertSame(0, $queueJob->deleteCount);
    }

    /**
     * @throws Throwable
     */
    public function testHandleDropsBatchWhenAttemptsExhausted(): void
    {
        $job = new SendTracesJob($this->makeTraces());

        $queueJob = $this->makeQueueJob(attempts: $job->tries);

        $this->setQueueJob($job, $queueJob);

        Log::shouldReceive('channel')
            ->once()
            ->andReturn(new NullLogger());

        $job->handle($this->makeProcessor(), $this->makeFailingApiClient(), new GeneralConfig());

        self::assertSame(0, $queueJob->releaseCount);
        self::assertSame(1, $queueJob->deleteCount);
    }

    /**
     * @throws Throwable
     */
    public function testDropLogIsRateLimited(): void
    {
        // one warning per interval, no matter how many batches are dropped
        Log::shouldReceive('channel')
            ->once()
            ->andReturn(new NullLogger());

        foreach (range(1, 3) as $ignored) {
            $job = new SendTracesJob($this->makeTraces());

            $this->setQueueJob($job, $this->makeQueueJob(attempts: $job->tries));

            $job->handle($this->makeProcessor(), $this->makeFailingApiClient(), new GeneralConfig());
        }
    }

    public function testFailedLogsDrop(): void
    {
        $job = new SendTracesJob($this->makeTraces());

        Log::shouldReceive('channel')
            ->once()
            ->andReturn(new NullLogger());

        $job->failed(new RuntimeException('fail'));
    }

    private function makeProcessor(): Processor
    {
        $processor = $this->getMockBuilder(Processor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['handleWithoutTracing'])
            ->getMock();

        $processor->method('handleWithoutTracing')
            ->willReturnCallback(
                static fn(callable $callback) => $callback()
            );

        return $processor;
    }

    private function makeFailingApiClient(): ApiClientInterface
    {
        $apiClient = $this->createMock(ApiClientInterface::class);
        $apiClient->method('sendTraces')
            ->willThrowException(new RuntimeException('fail'));

        return $apiClient;
    }

    private function makeQueueJob(int $attempts): FakeQueueJob
    {
        return new FakeQueueJob($attempts);
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
                loggedAt: Carbon::create(2024, 1, 1, 0, 0, 0)
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
