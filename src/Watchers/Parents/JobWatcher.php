<?php

namespace SLoggerLaravel\Watchers\Parents;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Queue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\SendTracesJob;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\DataFormatter;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Watchers\WatcherInterface;
use Throwable;

class JobWatcher implements WatcherInterface
{
    /**
     * Jobs that must never be traced regardless of the published config:
     * tracing the trace-sender job spawns new trace jobs recursively.
     *
     * @var class-string[]
     */
    private const ALWAYS_EXCEPTED_JOBS = [
        SendTracesJob::class,
    ];

    /**
     * @var class-string[]
     */
    protected array $exceptedJobs = self::ALWAYS_EXCEPTED_JOBS;

    /**
     * Jobs being processed, by the uuid their payload carries.
     *
     * @var array<string, array{trace_id: string, started_at: Carbon}>
     */
    protected array $jobs = [];

    public function __construct(
        protected readonly Processor $processor,
        protected readonly TraceIdContainer $traceIdContainer,
    ) {
    }

    public function register(?array $config): void
    {
        // a job killed by the timeout signal has its trace closed by the sweep and
        // never comes back here; without this the entry outlives the worker
        $this->processor->onTraceInterrupted(
            function (string $traceId): void {
                $this->jobs = array_filter(
                    $this->jobs,
                    static fn(array $job): bool => $job['trace_id'] !== $traceId
                );
            }
        );

        $this->exceptedJobs = array_values(
            array_unique(
                array_merge(
                    self::ALWAYS_EXCEPTED_JOBS,
                    $config['excepted'] ?? []
                )
            )
        );

        Queue::createPayloadUsing(
            function () {
                return [
                    'slogger_uuid'            => Str::uuid()->toString(),
                    'slogger_parent_trace_id' => $this->traceIdContainer->getParentTraceId(),
                ];
            }
        );

        $this->processor->registerEvent(JobProcessing::class, [$this, 'handleJobProcessing']);
        $this->processor->registerEvent(JobProcessed::class, [$this, 'handleJobProcessed']);
        $this->processor->registerEvent(JobFailed::class, [$this, 'handleJobFailed']);
        $this->processor->registerEvent(JobReleasedAfterException::class, [$this, 'handleJobReleasedAfterException']);
        $this->processor->registerEvent(JobTimedOut::class, [$this, 'handleJobTimedOut']);
        $this->processor->registerEvent(JobExceptionOccurred::class, [$this, 'handleJobExceptionOccurred']);
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        $payload = $event->job->payload();

        $jobClass = $payload['displayName'] ?? null;

        if (in_array($jobClass, $this->exceptedJobs)) {
            return;
        }

        $uuid = $payload['slogger_uuid'] ?? null;

        if (!$uuid) {
            return;
        }

        $parentTraceId = $payload['slogger_parent_trace_id'] ?? null;

        $loggedAt = Carbon::now();

        $traceId = $this->processor->startAndGetTraceId(
            type: TraceTypeEnum::Job->value,
            tags: [
                $jobClass,
            ],
            data: [],
            loggedAt: $loggedAt,
            customParentTraceId: $parentTraceId,
        );

        $this->jobs[$uuid] = [
            'trace_id'   => $traceId,
            'started_at' => $loggedAt,
        ];
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $this->stopJobTrace(
            job: $event->job,
            connectionName: $event->connectionName,
            jobStatus: 'processed',
            traceStatus: TraceStatusEnum::Success->value,
        );
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $this->stopJobTrace(
            job: $event->job,
            connectionName: $event->connectionName,
            jobStatus: 'failed',
            traceStatus: TraceStatusEnum::Failed->value,
            exception: $event->exception,
        );
    }

    public function handleJobReleasedAfterException(JobReleasedAfterException $event): void
    {
        $this->stopJobTrace(
            job: $event->job,
            connectionName: $event->connectionName,
            jobStatus: 'released_after_exception',
            traceStatus: TraceStatusEnum::Failed->value,
        );
    }

    /**
     * The worker kills itself right after a job timeout, so a job that is retried
     * instead of failed would never close its trace.
     */
    public function handleJobTimedOut(JobTimedOut $event): void
    {
        $this->stopJobTrace(
            job: $event->job,
            connectionName: $event->connectionName,
            jobStatus: 'timed_out',
            traceStatus: TraceStatusEnum::Failed->value,
        );
    }

    /**
     * A job that disposes of itself and then throws - `$this->release(60); throw ...`
     * - gets neither JobProcessed nor JobFailed nor JobReleasedAfterException, so this
     * is the last the worker says about it.
     */
    public function handleJobExceptionOccurred(JobExceptionOccurred $event): void
    {
        // the worker releases the job and emits JobReleasedAfterException right after
        // this event unless the job has already disposed of itself
        if (!$event->job->isDeletedOrReleased() && !$event->job->hasFailed()) {
            return;
        }

        $this->stopJobTrace(
            job: $event->job,
            connectionName: $event->connectionName,
            jobStatus: 'exception_occurred',
            traceStatus: TraceStatusEnum::Failed->value,
            exception: $event->exception,
        );
    }

    protected function stopJobTrace(
        Job $job,
        string $connectionName,
        string $jobStatus,
        string $traceStatus,
        ?Throwable $exception = null
    ): void {
        $payload = $job->payload();

        $uuid = $payload['slogger_uuid'] ?? null;

        if (!$uuid) {
            return;
        }

        $jobData = $this->jobs[$uuid] ?? null;

        if (!$jobData) {
            return;
        }

        // forgotten before the trace is stopped: a worker can report the same job
        // twice - the timeout handler fails one that has just been processed
        unset($this->jobs[$uuid]);

        $data = [
            'connection_name' => $connectionName,
            'job'             => $this->formatJobData($payload),
            'status'          => $jobStatus,
        ];

        if ($exception) {
            $data['exception'] = DataFormatter::exception($exception);
        }

        /** @var Carbon $startedAt */
        $startedAt = $jobData['started_at'];

        $this->processor->stop(
            traceId: $jobData['trace_id'],
            status: $traceStatus,
            tags: null,
            data: $data,
            duration: TraceHelper::calcDuration($startedAt),
            parentLoggedAt: $startedAt,
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function formatJobData(array $payload): array
    {
        return array_filter(
            [
                'name'            => $payload['displayName'] ?? null,
                'job'             => $payload['job'] ?? null,
                'max_tries'       => $payload['maxTries'] ?? null,
                'timeout'         => $payload['timeout'] ?? null,
                'max_exceptions'  => $payload['maxExceptions'] ?? null,
                'fail_on_timeout' => $payload['failOnTimeout'] ?? null,
                'backoff'         => $payload['backoff'] ?? null,
                'data'            => $this->extractJobData($payload),
            ],
            static fn(mixed $value): bool => $value !== null
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function extractJobData(array $payload): array
    {
        $data = $payload['data'] ?? [];

        if (!is_array($data)) {
            return [];
        }

        unset($data['command']);

        return $data;
    }
}
