<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use SLoggerLaravel\Enums\TraceTypeEnum;
use SLoggerLaravel\Processor;

/**
 * Reproduces a worker timeout: the SIGALRM handler of `queue:work` interrupts the
 * running job, i.e. fires while a nested parent trace is still open.
 */
class TimedOutJob implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * @param bool $failing whether the timed out job exceeds its attempts and is
     *                      failed by the worker instead of being retried
     */
    public function __construct(private readonly bool $failing = true)
    {
    }

    public function handle(Processor $processor): void
    {
        // the job opens a nested parent trace: an artisan call, a sync sub-job, etc.
        $processor->startAndGetTraceId(
            type: TraceTypeEnum::Command->value,
            tags: ['nested'],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null,
        );

        $job = $this->job;

        assert($job !== null);

        // the worker timeout handler fires right here
        if ($this->failing) {
            event(
                new JobFailed(
                    connectionName: $job->getConnectionName(),
                    job: $job,
                    exception: TimeoutExceededException::forJob($job)
                )
            );
        }

        event(
            new JobTimedOut(
                connectionName: $job->getConnectionName(),
                job: $job
            )
        );
    }
}
