<?php

declare(strict_types=1);

namespace Feature\Watchers\Parents\Command;

use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Parents\BaseParentTestCase;
use SLoggerLaravel\Watchers\Parents\CommandWatcher;

class ParentCommandTest extends BaseParentTestCase
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
        // no action
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
        // no action
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
        // no action
    }
}