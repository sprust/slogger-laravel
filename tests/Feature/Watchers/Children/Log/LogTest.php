<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Log;

use Closure;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildrenTestCase;
use SLoggerLaravel\Watchers\Children\LogWatcher;

class LogTest extends BaseChildrenTestCase
{
    protected function getTraceType(): string
    {
        return 'log';
    }

    protected function getWatcherClass(): string
    {
        return LogWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return fn() => logger()->info('test');
    }

    protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace
    ): void {
        // no action
    }
}
