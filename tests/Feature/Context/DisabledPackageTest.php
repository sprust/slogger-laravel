<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Context;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SLoggerLaravel\Context\ArrayTraceContext;
use SLoggerLaravel\Context\TraceContextInterface;
use SLoggerLaravel\Middleware\HttpMiddleware;
use SLoggerLaravel\Tests\Feature\BaseTestCase;

/**
 * `ServiceProvider::register()` returns early when tracing is off, but the middleware
 * stays in the application's middleware stack and is built either way - and it is
 * built with a TraceIdContainer, which is built with a store. So the store is bound
 * before that early return, and this is what says so.
 */
class DisabledPackageTest extends BaseTestCase
{
    /**
     * @throws BindingResolutionException
     */
    public function testTheStoreIsBoundEvenWithTracingOff(): void
    {
        self::assertInstanceOf(
            TraceContextInterface::class,
            $this->getApp()->make(TraceContextInterface::class)
        );
    }

    /**
     * @throws BindingResolutionException
     */
    public function testTheMiddlewareStillBuildsAndPassesTheRequestThrough(): void
    {
        $middleware = $this->getApp()->make(HttpMiddleware::class);

        $response = $middleware->handle(
            Request::create('/anything'),
            static fn(): Response => new Response('ok')
        );

        self::assertSame('ok', $response->getContent());
    }

    /**
     * A config file published before `slogger.context` existed carries no such key,
     * and the answer for it has to be what it always did.
     *
     * @throws BindingResolutionException
     */
    public function testAConfigWithoutTheKeyFallsBackToTheProcessWideStore(): void
    {
        config()->offsetUnset('slogger.context');

        $app = $this->getApp();

        $app->forgetInstance(TraceContextInterface::class);

        self::assertInstanceOf(
            ArrayTraceContext::class,
            $app->make(TraceContextInterface::class)
        );
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('slogger.enabled', false);
    }
}
