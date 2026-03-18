<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Job;

use App\Events\NestedEvent;
use RuntimeException;
use SLoggerLaravel\Dispatcher\Items\Queue\Jobs\SendTracesJob;
use SLoggerLaravel\Enums\TraceStatusEnum;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TracesObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use Throwable;

class ClosureJobWatcherTest extends BaseJobWatcherTestCase
{
    public function testUpdatedTraceDataCanBeSerializedIntoSendTracesJob(): void
    {
        $this->runSuccess();

        $creating = $this->dispatcher->findCreating(
            type: $this->getTraceType(),
            status: TraceStatusEnum::Started,
            isParent: true,
        );

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(
            traceId: $creating[0]->traceId,
            status: TraceStatusEnum::Success,
        );

        self::assertCount(1, $updating);

        $job = new SendTracesJob(
            (new TracesObject())->addUpdating($updating[0])
        );

        $exception = null;

        try {
            serialize($job);
        } catch (Throwable $exception) {
        }

        self::assertNull($exception);
    }

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
        $this->assertSavedJobData($updatingTrace);
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
        $this->assertSavedJobData($updatingTrace);
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
        $this->assertSavedJobData($updatingTrace);
    }

    /**
     * @param string[]      $creatingTraceTags
     * @param string[]|null $updatingTraceTags
     */
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

    private function assertSavedJobData(TraceUpdateObject $updatingTrace): void
    {
        self::assertIsArray($updatingTrace->data);
        self::assertArrayHasKey('job', $updatingTrace->data);
        self::assertArrayNotHasKey('payload', $updatingTrace->data);
        self::assertIsArray($updatingTrace->data['job']);
        self::assertArrayHasKey('data', $updatingTrace->data['job']);
        self::assertIsArray($updatingTrace->data['job']['data']);
        self::assertArrayNotHasKey('command', $updatingTrace->data['job']['data']);
    }
}
