<?php

declare(strict_types=1);

namespace Feature\Watchers\Children\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;
use SLoggerLaravel\Tests\Feature\Watchers\Children\Cache\BaseCacheTestCase;

class CachePullTest extends BaseCacheTestCase
{
    protected function successCallback(): Closure
    {
        return fn () => Cache::pull('test');
    }
}
