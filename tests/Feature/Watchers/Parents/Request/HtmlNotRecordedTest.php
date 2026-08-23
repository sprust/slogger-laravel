<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Support\Facades\Route;
use SLoggerLaravel\Helpers\BodyDecoder;
use SLoggerLaravel\Middleware\HttpMiddleware;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

/**
 * A page is not a payload: the masker matches *names*, so a token in `value="…"`
 * beside `name="_token"` is out of its reach.
 */
class HtmlNotRecordedTest extends BaseWatcherTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(HttpMiddleware::class)
            ->get('/zz-html', fn(ResponseFactory $factory) => $factory->make(
                '<!DOCTYPE html><html><body><input name="_token" value="CSRF-SECRET"/></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ))
            ->name('zz.html');

        Route::middleware(HttpMiddleware::class)
            ->get('/zz-xhtml', fn(ResponseFactory $factory) => $factory->make(
                '<html xmlns="http://www.w3.org/1999/xhtml"><body>'
                . '<input name="_token" value="CSRF-SECRET"/></body></html>',
                200,
                ['Content-Type' => 'application/xhtml+xml']
            ))
            ->name('zz.xhtml');

        Route::middleware(HttpMiddleware::class)
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
        // well-formed, so it parses as XML: parsing alone never settled this
        $this->assertBodyNotRecorded('zz.fragment');
    }

    public function testAnXhtmlPageIsNotRecordedThoughItsTypeSaysXml(): void
    {
        // `application/xhtml+xml` passes the content-type gate on its `+xml` suffix,
        // which is what the page guard exists for
        $this->assertBodyNotRecorded('zz.xhtml', 'application/xhtml+xml');
    }

    /**
     * A 404 never routes, so the tag falls back to the path the caller typed - and
     * `/reset/tok-secret` is a token in a tag, which nothing masks.
     */
    public function testAnUnroutedRequestDoesNotTagTheSecretInItsPath(): void
    {
        $this->registerWatcher(RequestWatcher::class, null);

        // globally: the only way a 404 is traced at all
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $this->getApp()->make(Kernel::class);

        $kernel->pushMiddleware(HttpMiddleware::class);

        $this->call('GET', '/reset/tok-SUPER-SECRET/confirm')->assertNotFound();

        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);

        self::assertSame(['/reset/…'], $creating[0]->tags);
        self::assertSame('/reset/…', $creating[0]->data['uri']);

        self::assertStringNotContainsString(
            'tok-SUPER-SECRET',
            json_encode($creating[0], JSON_THROW_ON_ERROR)
        );
    }

    private function assertBodyNotRecorded(string $route, string $accept = 'text/html'): void
    {
        $this->registerWatcher(RequestWatcher::class, null);

        $this->call('GET', route($route), server: ['HTTP_ACCEPT' => $accept])
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
