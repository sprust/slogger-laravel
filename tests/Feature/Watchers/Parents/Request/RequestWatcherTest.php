<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Parents\BaseParentTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

class RequestWatcherTest extends BaseParentTestCase
{
    protected function getTraceType(): string
    {
        return 'request';
    }

    protected function getWatcherClass(): string
    {
        return RequestWatcher::class;
    }

    protected function runSuccess(): void
    {
        $this->get(route('slogger.success'))
            ->assertOk();
    }

    protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace
    ): void {
        // no action
    }

    protected function runFailed(): void
    {
        $this->get(route('slogger.failed'))
            ->assertInternalServerError();
    }

    protected function assertFailed(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace
    ): void {
        // no action
    }

    protected function runWithNestedEvent(): void
    {
        $this->runSuccess();
    }

    protected function assertWithNestedEvent(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
        TraceCreateObject $creatingEventTrace
    ): void {
        // no action
    }
}
