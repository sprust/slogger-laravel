<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Job;

use RuntimeException;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerTestEntities\Events\NestedEvent;
use Throwable;

class ClosureJobWatcherTest extends BaseJobWatcherTestCase
{
    protected function runSuccess(): void
    {
        dispatch(static fn() => null);
    }

    protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
    ): void {
        $this->assertTags(
            creatingTraceTags: $creatingTrace->tags,
            updatingTraceTags: $updatingTrace->tags
        );
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
        $this->assertTags(
            creatingTraceTags: $creatingTrace->tags,
            updatingTraceTags: $updatingTrace->tags
        );
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
        $this->assertTags(
            creatingTraceTags: $creatingTrace->tags,
            updatingTraceTags: $updatingTrace->tags
        );
    }

    protected function assertTags(array $creatingTraceTags, ?array $updatingTraceTags): void
    {
        $testClassName = class_basename(__CLASS__);

        $hasClosureTag = false;

        $mask = "/^Closure \($testClassName\.php:\d+\)$/";

        foreach ($creatingTraceTags as $tag) {
            if (preg_match($mask, $tag)) {
                $hasClosureTag = true;

                break;
            }
        }

        self::assertTrue($hasClosureTag);

        self::assertNull($updatingTraceTags);
    }
}
