<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Context;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SLoggerLaravel\Configs\GeneralConfig;
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
        $this->forgetResolved();

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
        $this->forgetResolved();

        $middleware = $this->getApp()->make(HttpMiddleware::class);

        $response = $middleware->handle(
            Request::create('/anything'),
            static fn(): Response => new Response('ok')
        );

        self::assertSame('ok', $response->getContent());
    }

    /**
     * A store name that names nothing is a misconfiguration of a package that is
     * switched off. Before there was a store, nothing on this path could throw at all,
     * and a typo in an env var must not answer every route with a 500.
     *
     * @throws BindingResolutionException
     */
    public function testAStoreNameThatNamesNothingDoesNotBreakTheApplication(): void
    {
        config()->set('slogger.context', 'fibre');

        $this->forgetResolved();

        $app = $this->getApp();

        $app->forgetInstance(TraceContextInterface::class);

        $response = $app->make(HttpMiddleware::class)->handle(
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

        $this->forgetResolved();

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

    /**
     * `GeneralConfig` reads `slogger.enabled` once, in its constructor, and the
     * container has already built one by the time a test runs - with the suite's own
     * config, where the package is on. Dropping what has been resolved is what makes
     * the rest of this class about a package that is actually off.
     */
    private function forgetResolved(): void
    {
        $app = $this->getApp();

        $app->forgetInstance(GeneralConfig::class);
        $app->forgetInstance(TraceContextInterface::class);
        $app->forgetInstance(HttpMiddleware::class);
    }
}
