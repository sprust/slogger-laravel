<?php

namespace SLoggerLaravel\Watchers\Parents;

use Illuminate\Contracts\Queue\Job;
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
     * @var array<array{trace_id: string, started_at: Carbon}>
     */
    protected array $jobs = [];
    /**
     * @var class-string[]
     */
    protected array $exceptedJobs = self::ALWAYS_EXCEPTED_JOBS;

    public function __construct(
        protected readonly Processor $processor,
        protected readonly TraceIdContainer $traceIdContainer,
    ) {
    }

    public function register(?array $config): void
    {
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

        // forget the job before stopping: a worker can report the same job twice,
        // e.g. the timeout signal handler fails a job that has just been processed
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
