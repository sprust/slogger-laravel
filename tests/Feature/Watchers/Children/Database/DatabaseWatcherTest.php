<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Database;

use Closure;
use Illuminate\Support\Facades\DB;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\DatabaseWatcher;

class DatabaseWatcherTest extends BaseChildWatcherTestCase
{
    protected function getTraceType(): string
    {
        return 'database';
    }

    protected function getWatcherClass(): string
    {
        return DatabaseWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return static fn() => event(
            DB::statement('SELECT 1')
        );
    }

    protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace
    ): void {
        // no action
    }
}
