<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Support\Facades\Route;
use SLoggerLaravel\Helpers\BodyDecoder;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

/**
 * An HTML response was never recorded, and must not start being. A page is not a
 * payload: it carries CSRF tokens, inlined keys and, with a debug page installed,
 * environment values - and the masker matches key and element *names*, so a token
 * sitting in `value="…"` beside `name="_token"` is out of its reach entirely.
 */
class HtmlNotRecordedTest extends BaseWatcherTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(\SLoggerLaravel\Middleware\HttpMiddleware::class)
            ->get('/zz-html', fn(ResponseFactory $factory) => $factory->make(
                '<!DOCTYPE html><html><body><input name="_token" value="CSRF-SECRET"/></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ))
            ->name('zz.html');

        Route::middleware(\SLoggerLaravel\Middleware\HttpMiddleware::class)
            ->get('/zz-fragment', fn(ResponseFactory $factory) => $factory->make(
                '<div><input name="_token" value="CSRF-SECRET"/></div>',
                200,
                ['Content-Type' => 'text/html']
            ))
            ->name('zz.fragment');
    }

    public function testAFullPageIsNotRecorded(): void
    {
        $this->assertBodyNotRecorded('zz.html');
    }

    public function testAMarkupFragmentIsNotRecordedEither(): void
    {
        // this one is well-formed, so it parses as XML - which is why parsing alone
        // was never enough to decide
        $this->assertBodyNotRecorded('zz.fragment');
    }

    private function assertBodyNotRecorded(string $route): void
    {
        $this->registerWatcher(RequestWatcher::class, null);

        $this->call('GET', route($route), server: ['HTTP_ACCEPT' => 'text/html'])
            ->assertOk();

        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);

        $updating = $this->dispatcher->findUpdating(traceId: $creating[0]->traceId);

        self::assertCount(1, $updating);

        $data = $updating[0]->data ?? [];

        self::assertArrayNotHasKey(BodyDecoder::XML_KEY, $data['response']['data']);

        self::assertStringNotContainsString(
            'CSRF-SECRET',
            json_encode($data, JSON_THROW_ON_ERROR)
        );
    }
}
