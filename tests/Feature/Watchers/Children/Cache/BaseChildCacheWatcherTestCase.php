<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Cache;

use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Objects\TraceUpdateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\CacheWatcher;

abstract class BaseChildCacheWatcherTestCase extends BaseChildWatcherTestCase
{

    protected function getTraceType(): string
    {
        return 'cache';
    }

    protected function getWatcherClass(): string
    {
        return CacheWatcher::class;
    }

    protected function assertSuccess(
        TraceCreateObject $creatingTrace,
        TraceUpdateObject $updatingTrace,
    ): void {
        // no action
    }
}
