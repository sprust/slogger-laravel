<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\HttpClient;

use ReflectionProperty;
use SLoggerLaravel\Guzzle\GuzzleHandlerFactory;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\Children\HttpClientWatcher;

/**
 * Everything this watcher keeps is per instance: the map of in-flight requests, the
 * random header key it stamps them with, and the sweep callback `register()` adds.
 * The Guzzle handler factory resolves the watcher too, so a fresh instance there
 * means the object doing the work is not the object that was registered - and the
 * cleanup, the header lookup and the leak fix all point at nothing.
 */
class HttpClientWatcherInstanceTest extends BaseTestCase
{
    public function testTheWatcherIsSharedWithTheGuzzleHandlerFactory(): void
    {
        $watcher = $this->getApp()->make(HttpClientWatcher::class);

        self::assertSame($watcher, $this->getApp()->make(HttpClientWatcher::class));

        $inFactory = (new ReflectionProperty(GuzzleHandlerFactory::class, 'httpClientWatcher'))
            ->getValue($this->getApp()->make(GuzzleHandlerFactory::class));

        self::assertSame($watcher, $inFactory);
    }
}
