<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\HttpClient;

use ReflectionProperty;
use SLoggerLaravel\Guzzle\GuzzleHandlerFactory;
use SLoggerLaravel\Tests\Feature\BaseTestCase;
use SLoggerLaravel\Watchers\Children\HttpClientWatcher;

/**
 * Everything this watcher keeps is per instance, and the Guzzle handler factory
 * resolves it too - a fresh instance there does the work the registered one tracks.
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
