<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use SLoggerLaravel\Events\RequestHandling;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

/**
 * Where the process was started for this request - php-fpm, and the first request of a
 * worker under a SAPI that is not the console - the framework's bootstrap is part of
 * what the request took, and the duration says so.
 *
 * The suite runs from the console, so this asks the application to say otherwise, which
 * is the one thing `runningInConsole()` reads before the SAPI.
 */
class RequestBootTimeIsCountedTest extends BaseWatcherTestCase
{
    private const BOOTSTRAP_SECONDS = 0.25;

    protected function setUp(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE=false');

        $_ENV['APP_RUNNING_IN_CONSOLE']    = 'false';
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

        parent::setUp();

        $this->registerWatcher(RequestWatcher::class, null);
    }

    protected function tearDown(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');

        unset($_ENV['APP_RUNNING_IN_CONSOLE'], $_SERVER['APP_RUNNING_IN_CONSOLE']);

        parent::tearDown();
    }

    #[RunInSeparateProcess]
    public function testTheBootstrapIsPartOfTheRequestItWasFor(): void
    {
        self::assertFalse(
            $this->getApp()->runningInConsole(),
            'the application has to believe it is answering as a web process'
        );

        define('LARAVEL_START', microtime(true) - self::BOOTSTRAP_SECONDS);

        $request = Request::create('/slogger/anything');

        event(new RequestHandling(request: $request, parentTraceId: null));
        event(new RequestHandled($request, new Response('ok')));

        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);

        self::assertGreaterThanOrEqual(
            self::BOOTSTRAP_SECONDS,
            $creating[0]->data['boot_time'],
            'the bootstrap is reported'
        );

        $updating = $this->dispatcher->findUpdating();

        self::assertCount(1, $updating);

        self::assertGreaterThanOrEqual(
            self::BOOTSTRAP_SECONDS,
            $updating[0]->duration,
            'and it is inside the duration, not only beside it'
        );
    }
}
