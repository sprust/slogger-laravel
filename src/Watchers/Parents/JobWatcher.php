<?php

namespace SLoggerLaravel\Watchers\Parents;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Queue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Helpers\DataFormatter;
use SLoggerLaravel\Helpers\TraceHelper;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Traces\TraceIdContainer;
use SLoggerLaravel\Watchers\WatcherInterface;

class JobWatcher implements WatcherInterface
{
    /**
     * @var array<array{trace_id: string, started_at: Carbon}>
     */
    protected array $jobs = [];
    /**
     * @var class-string[]
     */
    protected array $exceptedJobs = [];

    public function __construct(
        protected readonly Processor $processor,
        protected readonly TraceIdContainer $traceIdContainer,
    ) {
    }

    public function register(?array $config): void
    {
        if ($config !== null) {
            $this->exceptedJobs = $config['excepted'] ?? [];
        }

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

        $loggedAt = now();

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
        $payload = $event->job->payload();

        $uuid = $payload['slogger_uuid'] ?? null;

        if (!$uuid) {
            return;
        }

        $jobData = $this->jobs[$uuid] ?? null;

        if (!$jobData) {
            return;
        }

        $traceId = $jobData['trace_id'];

        /** @var Carbon $startedAt */
        $startedAt = $jobData['started_at'];

        $data = [
            'connection_name' => $event->connectionName,
            'payload'         => $event->job->payload(),
            'status'          => 'processed',
        ];

        $this->processor->stop(
            traceId: $traceId,
            status: TraceStatusEnum::Success->value,
            tags: null,
            data: $data,
            duration: TraceHelper::calcDuration($startedAt),
            parentLoggedAt: $startedAt,
        );

        unset($this->jobs[$uuid]);
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $payload = $event->job->payload();

        $uuid = $payload['slogger_uuid'] ?? null;

        if (!$uuid) {
            return;
        }

        $jobData = $this->jobs[$uuid] ?? null;

        if (!$jobData) {
            return;
        }

        $traceId = $jobData['trace_id'];

        /** @var Carbon $startedAt */
        $startedAt = $jobData['started_at'];

        $data = [
            'connection_name' => $event->connectionName,
            'payload'         => $event->job->payload(),
            'status'          => 'failed',
            'exception'       => DataFormatter::exception($event->exception),
        ];

        $this->processor->stop(
            traceId: $traceId,
            status: TraceStatusEnum::Failed->value,
            tags: null,
            data: $data,
            duration: TraceHelper::calcDuration($startedAt),
            parentLoggedAt: $startedAt,
        );

        unset($this->jobs[$uuid]);
    }

    public function handleJobReleasedAfterException(JobReleasedAfterException $event): void
    {
        $payload = $event->job->payload();

        $uuid = $payload['slogger_uuid'] ?? null;

        if (!$uuid) {
            return;
        }

        $jobData = $this->jobs[$uuid] ?? null;

        if (!$jobData) {
            return;
        }

        $traceId = $jobData['trace_id'];

        /** @var Carbon $startedAt */
        $startedAt = $jobData['started_at'];

        $data = [
            'connection_name' => $event->connectionName,
            'payload'         => $event->job->payload(),
            'status'          => 'released_after_exception',
        ];

        $this->processor->stop(
            traceId: $traceId,
            status: TraceStatusEnum::Failed->value,
            tags: null,
            data: $data,
            duration: TraceHelper::calcDuration($startedAt),
            parentLoggedAt: $startedAt,
        );

        unset($this->jobs[$uuid]);
    }
}
