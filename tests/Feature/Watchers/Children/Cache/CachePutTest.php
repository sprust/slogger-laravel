<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;

class CachePutTest extends BaseCacheTestCase
{
    protected function successCallback(): Closure
    {
        return fn () => Cache::put('test', 'test');
    }
}
