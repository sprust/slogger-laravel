<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Job;

use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerTestEntities\Jobs\FailedJob;
use SLoggerTestEntities\Jobs\NestedEventJob;
use SLoggerTestEntities\Jobs\SuccessJob;
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
        self::assertTrue(
            in_array(SuccessJob::class, $creatingTrace->tags, true)
        );

        self::assertNull($updatingTrace->tags);
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
        // no action
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
        // no action
    }
}
