<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use SLoggerLaravel\Middleware\HttpMiddleware;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

/**
 * With the shipped `input.hidden_paths => ['*']` the parameters are discarded, so
 * reading the body at all is cost the traced application pays for nothing.
 */
class HiddenParametersAreNotReadTest extends BaseWatcherTestCase
{
    public function testABodyThatWillBeDiscardedIsNeverRead(): void
    {
        Route::middleware(HttpMiddleware::class)
            ->post('/zz-hidden', fn(ResponseFactory $factory) => $factory->json(['ok' => true]))
            ->name('zz.hidden');

        CountingRequestWatcher::$reads = 0;

        $this->registerWatcher(
            CountingRequestWatcher::class,
            ['input' => ['hidden_paths' => ['*']]]
        );

        $this->call(
            method: 'POST',
            uri: route('zz.hidden'),
            server: ['CONTENT_TYPE' => 'application/xml'],
            content: '<order><api_token>sk-live-secret</api_token></order>'
        )->assertOk();

        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);

        // the parameters are gone either way - that is what hidden_paths means
        self::assertSame(['__cleaned' => null], $creating[0]->data['request']['parameters']);

        // and getting there cost nothing: the body was not read, so it was not parsed
        self::assertSame(0, CountingRequestWatcher::$reads);
    }

    public function testABodyThatWillBeKeptIsStillRead(): void
    {
        Route::middleware(HttpMiddleware::class)
            ->post('/zz-kept', fn(ResponseFactory $factory) => $factory->json(['ok' => true]))
            ->name('zz.kept');

        CountingRequestWatcher::$reads = 0;

        $this->registerWatcher(CountingRequestWatcher::class, null);

        $this->call(
            method: 'POST',
            uri: route('zz.kept'),
            content: json_encode(['page' => 2], JSON_THROW_ON_ERROR),
            server: ['CONTENT_TYPE' => 'application/json']
        )->assertOk();

        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);
        self::assertSame(['page' => 2], $creating[0]->data['request']['parameters']);

        self::assertGreaterThan(0, CountingRequestWatcher::$reads);
    }
}

class CountingRequestWatcher extends RequestWatcher
{
    public static int $reads = 0;

    protected function getRequestParameters(Request $request): array
    {
        self::$reads++;

        return parent::getRequestParameters($request);
    }
}
