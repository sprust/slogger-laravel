<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Command;

use App\Events\NestedEvent;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Parents\BaseParentWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\CommandWatcher;

class CommandWatcherTest extends BaseParentWatcherTestCase
{
    protected function getTraceType(): string
    {
        return 'command';
    }

    protected function getWatcherClass(): string
    {
        return CommandWatcher::class;
    }

    protected function runSuccess(): void
    {
        $exitCode = $this->artisanCall('slogger:test-success');

        self::assertSame(0, $exitCode);
    }

    protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
    ): void {
        self::assertSame('slogger:test-success', $creatingTrace->data['command']);
        self::assertSame(['slogger:test-success'], $creatingTrace->tags);
        self::assertArrayHasKey('arguments', $creatingTrace->data);
        self::assertArrayHasKey('options', $creatingTrace->data);

        self::assertSame(0, $updatingTrace->data['exit_code'] ?? null);
    }

    protected function runFailed(): void
    {
        $exitCode = $this->artisanCall('slogger:test-failed');

        self::assertSame(1, $exitCode);
    }

    protected function assertFailed(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
    ): void {
        self::assertSame('slogger:test-failed', $creatingTrace->data['command']);

        self::assertSame(1, $updatingTrace->data['exit_code'] ?? null);
    }

    protected function runWithNestedEvent(): void
    {
        $exitCode = $this->artisanCall('slogger:test-nested-event');

        self::assertSame(0, $exitCode);
    }

    protected function assertWithNestedEvent(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
        TraceCreateObject $creatingEventTrace,
    ): void {
        self::assertSame('slogger:test-nested-event', $creatingTrace->data['command']);

        // the event was recorded as a child of the command, not as an orphan
        self::assertSame($creatingTrace->traceId, $creatingEventTrace->parentTraceId);
        self::assertSame([NestedEvent::class], $creatingEventTrace->tags);
    }
}
