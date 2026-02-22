<?php

declare(strict_types=1);

namespace Feature\Watchers\Children\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;
use SLoggerLaravel\Tests\Feature\Watchers\Children\Cache\BaseChildCacheWatcherTestCase;

class CacheForeverWatcherTest extends BaseChildCacheWatcherTestCase
{
    protected function successCallback(): Closure
    {
        return static fn() => Cache::forever('test', 'test');
    }
}
