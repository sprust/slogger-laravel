<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Job;

use App\Jobs\FailedJob;
use App\Jobs\NestedEventJob;
use App\Jobs\SuccessJob;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use Throwable;

class ClassJobWatcherTest extends BaseJobWatcherTestCase
{
    protected function runSuccess(): void
    {
        dispatch(new SuccessJob());
    }

    protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
    ): void {
        $this->assertTags(
            jobClass: SuccessJob::class,
            creatingTraceTags: $creatingTrace->tags,
            updatingTraceTags: $updatingTrace->tags
        );
    }

    protected function runFailed(): void
    {
        $message = uniqid();

        $exception = null;

        try {
            dispatch(new FailedJob($message));
        } catch (Throwable $exception) {
            //
        }

        self::assertNotNull($exception);
    }

    protected function assertFailed(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace
    ): void {
        $this->assertTags(
            jobClass: FailedJob::class,
            creatingTraceTags: $creatingTrace->tags,
            updatingTraceTags: $updatingTrace->tags
        );
    }

    protected function runWithNestedEvent(): void
    {
        dispatch(new NestedEventJob());
    }

    protected function assertWithNestedEvent(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
        TraceCreateObject $creatingEventTrace,
    ): void {
        $this->assertTags(
            jobClass: NestedEventJob::class,
            creatingTraceTags: $creatingTrace->tags,
            updatingTraceTags: $updatingTrace->tags
        );
    }

    /**
     * @param string[]      $creatingTraceTags
     * @param string[]|null $updatingTraceTags
     */
    protected function assertTags(string $jobClass, array $creatingTraceTags, ?array $updatingTraceTags): void
    {
        self::assertTrue(
            in_array($jobClass, $creatingTraceTags, true)
        );

        self::assertNull($updatingTraceTags);
    }
}
