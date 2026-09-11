<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Dispatcher\Items;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use SLoggerLaravel\Configs\GeneralConfig;
use SLoggerLaravel\Configs\MaskingConfig;
use SLoggerLaravel\Helpers\TraceDataMasker;
use SLoggerLaravel\Dispatcher\ApiClients\ApiClientInterface;
use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\SendTracesJob;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use stdClass;
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
        self::assertSame([5, 10, 30, 60], $job->backoff);
        self::assertSame(count($job->backoff) + 1, $job->tries);
    }

    public function testBackoffAcceptsIntAssignedByQueueDriver(): void
    {
        // some drivers assign a computed int back when releasing a job, and a typed
        // array property would make that a TypeError
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

        $job->handle($processor, $apiClient, new GeneralConfig(), $this->makeMasker());
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

        $job->handle($processor, $apiClient, new GeneralConfig(), $this->makeMasker());
    }

    /**
     * @throws Throwable
     */
    public function testHandleReleasesWithBackoffWhenAttemptsLeft(): void
    {
        // not rethrown: the worker would report every attempt to the host's handler
        foreach ([1 => 5, 2 => 10, 3 => 30, 4 => 60] as $attempts => $expectedDelay) {
            $job = new SendTracesJob($this->makeTraces());

            $queueJob = $this->makeQueueJob(attempts: $attempts);

            $this->setQueueJob($job, $queueJob);

            $job->handle($this->makeProcessor(), $this->makeFailingApiClient(), new GeneralConfig(), $this->makeMasker());

            self::assertSame(1, $queueJob->releaseCount, "attempt $attempts");
            self::assertSame($expectedDelay, $queueJob->releaseDelay, "attempt $attempts");
            self::assertSame(0, $queueJob->deleteCount, "attempt $attempts");
        }
    }

    /**
     * @throws Throwable
     */
    public function testHandleThrowsOnSyncQueue(): void
    {
        // releasing a sync job is a no-op: the batch would vanish without a word
        $job = new SendTracesJob($this->makeTraces());

        $this->setQueueJob($job, new SyncJob($this->getApp(), '{}', 'sync', 'default'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fail');

        $job->handle($this->makeProcessor(), $this->makeFailingApiClient(), new GeneralConfig(), $this->makeMasker());
    }

    /**
     * @throws Throwable
     */
    public function testHandleReleasesWithIntBackoffAssignedByQueueDriver(): void
    {
        $job = new SendTracesJob($this->makeTraces());

        $job->backoff = 7;

        $queueJob = $this->makeQueueJob(attempts: 3);

        $this->setQueueJob($job, $queueJob);

        $job->handle($this->makeProcessor(), $this->makeFailingApiClient(), new GeneralConfig(), $this->makeMasker());

        self::assertSame(7, $queueJob->releaseDelay);
    }

    /**
     * The regression itself: a failed attempt that escapes `handle()` reaches the
     * application's exception handler, and a receiver restart becomes thousands of
     * error reports in the host's own monitoring.
     *
     * @throws Throwable
     */
    public function testWorkerDoesNotReportFailedAttempt(): void
    {
        $this->useDatabaseQueue();

        dispatch(new SendTracesJob($this->makeTraces()));

        $this->getApp()->instance(ApiClientInterface::class, $this->makeFailingApiClient());

        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects(self::never())->method('report');

        $this->getApp()->instance(ExceptionHandler::class, $handler);

        $worker = $this->getApp()->make('queue.worker');

        assert($worker instanceof Worker);

        $worker->runNextJob('slogger-test', 'slogger', new WorkerOptions());

        $row = DB::table('jobs')->first();

        // still queued, released for the second attempt after the first pause
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame(1, (int) $row->attempts);
        self::assertNull($row->reserved_at);
        self::assertGreaterThanOrEqual(time() + 4, (int) $row->available_at);
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

        $job->handle($this->makeProcessor(), $this->makeFailingApiClient(), new GeneralConfig(), $this->makeMasker());

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

            $job->handle($this->makeProcessor(), $this->makeFailingApiClient(), new GeneralConfig(), $this->makeMasker());
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

    private function useDatabaseQueue(): void
    {
        config([
            'queue.connections.slogger-test' => [
                'driver'      => 'database',
                'table'       => 'jobs',
                'queue'       => 'slogger',
                'retry_after' => 90,
            ],
            'slogger.dispatchers.queue.connection' => 'slogger-test',
            'slogger.dispatchers.queue.name'       => 'slogger',
        ]);

        Schema::create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
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

    private function makeMasker(): TraceDataMasker
    {
        return new TraceDataMasker(new MaskingConfig());
    }
}
