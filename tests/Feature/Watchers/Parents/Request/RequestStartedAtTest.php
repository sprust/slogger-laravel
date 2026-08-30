<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use SLoggerLaravel\Events\RequestHandling;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

/**
 * `LARAVEL_START` is defined once, where the process begins. Under php-fpm that is this
 * request. Under a server that boots once and serves for hours it is whenever the worker
 * came up, and taking it for a request's start reports the worker's uptime as every
 * request's duration - the same number for every trace, growing all day.
 */
class RequestStartedAtTest extends BaseWatcherTestCase
{
    /**
     * How long a request in this test could plausibly take. Anything past it is not a
     * duration, it is an uptime.
     */
    private const PLAUSIBLE_SECONDS = 60;

    private const WORKER_UPTIME_SECONDS = 7700;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerWatcher(RequestWatcher::class, null);
    }

    /**
     * The suite runs from the console, which is what a CLI-SAPI server is, so the
     * constant is refused however stale it is.
     */
    #[RunInSeparateProcess]
    public function testAWorkersBootTimeIsNotTakenForARequestStart(): void
    {
        define('LARAVEL_START', microtime(true) - self::WORKER_UPTIME_SECONDS);

        $this->handleRequest();

        $updating = $this->dispatcher->findUpdating();

        self::assertCount(1, $updating);

        $duration = $updating[0]->duration;

        self::assertNotNull($duration);

        self::assertLessThan(
            self::PLAUSIBLE_SECONDS,
            $duration,
            'the duration is the request, not how long the process has been up'
        );
    }

    /**
     * And the boot it did not measure is not reported as this request's either.
     */
    #[RunInSeparateProcess]
    public function testTheBootTimeOfAWorkerIsNotReportedAsTheRequestsOwn(): void
    {
        define('LARAVEL_START', microtime(true) - self::WORKER_UPTIME_SECONDS);

        $this->handleRequest();

        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);

        self::assertSame(-1, $creating[0]->data['boot_time'], 'unknown, rather than the uptime');
    }

    /**
     * With no constant at all, which is every request but the first of a worker.
     */
    public function testWithoutTheConstantTheDurationIsStillTheRequests(): void
    {
        $this->handleRequest();

        $updating = $this->dispatcher->findUpdating();

        self::assertCount(1, $updating);

        $duration = $updating[0]->duration;

        self::assertNotNull($duration);
        self::assertLessThan(self::PLAUSIBLE_SECONDS, $duration);
    }

    /**
     * The kernel keeps one `requestStartedAt` for the whole process, so a request
     * starting beside this one replaces it - with a later time, since it started later.
     * Nothing reads it any more, and this says so: measuring from a start in the future
     * gives a negative duration, which Carbon 3 reports signed.
     */
    public function testASharedKernelStartIsNotConsulted(): void
    {
        // the instance the framework actually uses, which is bound by contract
        $kernel = $this->getApp()->get(KernelContract::class);

        self::assertInstanceOf(Kernel::class, $kernel);

        (new ReflectionProperty(Kernel::class, 'requestStartedAt'))
            ->setValue($kernel, Carbon::now()->addSeconds(10));

        $this->handleRequest();

        $updating = $this->dispatcher->findUpdating();

        self::assertCount(1, $updating);

        $duration = $updating[0]->duration;

        self::assertNotNull($duration);

        self::assertGreaterThanOrEqual(0, $duration, 'a duration is never negative');
        self::assertLessThan(self::PLAUSIBLE_SECONDS, $duration);
    }

    private function handleRequest(): void
    {
        $request = Request::create('/slogger/anything');

        event(new RequestHandling(request: $request, parentTraceId: null));

        event(new RequestHandled($request, new Response('ok')));
    }
}
