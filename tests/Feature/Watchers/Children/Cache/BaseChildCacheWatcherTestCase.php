<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Cache;

use SLoggerLaravel\Objects\TraceCreateObject;
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

    protected function assertSuccess(TraceCreateObject $creatingTrace): void
    {
        $data = $creatingTrace->data;

        self::assertSame('test', $data['key']);
        self::assertContains($data['type'], ['hit', 'missed', 'set', 'forget']);

        // the cached value lives under its own cache key, which is the only thing
        // that says what it holds
        self::assertArrayHasKey('test', $data['cache']);

        self::assertSame([$data['type'], 'test'], $creatingTrace->tags);
    }
}
