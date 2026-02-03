<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Job;

use RuntimeException;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Parents\BaseParentTestCase;
use SLoggerLaravel\Watchers\Parents\JobWatcher;
use SLoggerTestEntities\NestedEvent;
use Throwable;

class ClosureJobWatcherTest extends BaseParentTestCase
{
    protected function getTraceType(): string
    {
        return 'job';
    }

    protected function getWatcherClass(): string
    {
        return JobWatcher::class;
    }

    protected function runSuccess(): void
    {
        dispatch(static fn() => null);
    }

    protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
    ): void {
        $testClassName = class_basename(__CLASS__);

        $hasClosureTag = false;

        $mask = "/^Closure \($testClassName\.php:\d+\)$/";

        foreach ($creatingTrace->tags as $tag) {
            if (preg_match($mask, $tag)) {
                $hasClosureTag = true;

                break;
            }
        }

        self::assertTrue($hasClosureTag);

        self::assertNull($updatingTrace->tags);
    }

    protected function runFailed(): void
    {
        $message = uniqid();

        $exception = null;

        try {
            dispatch(static fn() => throw new RuntimeException($message));
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
        dispatch(static fn() => event(new NestedEvent()));
    }

    protected function assertWithNestedEvent(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
        TraceCreateObject $creatingEventTrace,
    ): void {
        // no action
    }
}
