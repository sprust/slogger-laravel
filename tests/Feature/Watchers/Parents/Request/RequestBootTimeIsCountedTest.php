<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Parents\Request;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SLoggerLaravel\Events\RequestHandling;
use SLoggerLaravel\Tests\Feature\Watchers\BaseWatcherTestCase;
use SLoggerLaravel\Watchers\Parents\RequestWatcher;

/**
 * Where the process was started for this request - php-fpm, and the first request of a
 * worker under a SAPI that is not the console - what it spent starting up is part of
 * what the request took, and the duration says so rather than only `boot_time`.
 *
 * The suite runs from the console, so this asks the application to say otherwise, which
 * is the one thing `runningInConsole()` reads before the SAPI.
 */
class RequestBootTimeIsCountedTest extends BaseWatcherTestCase
{
    /**
     * Comfortably past anything a request here could take, so that finding it inside
     * the duration can only mean the start-up went in with it.
     *
     * @see \LARAVEL_START as the suite defines it
     */
    private const CLEARLY_A_START_UP = 60;

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

    public function testWhatTheProcessSpentStartingIsPartOfTheRequestItWasFor(): void
    {
        self::assertFalse(
            $this->getApp()->runningInConsole(),
            'the application has to believe it is answering as a web process'
        );

        $request = Request::create('/slogger/anything');

        event(new RequestHandling(request: $request, parentTraceId: null));
        event(new RequestHandled($request, new Response('ok')));

        $creating = $this->dispatcher->findCreating(type: 'request');

        self::assertCount(1, $creating);

        $bootTime = $creating[0]->data['boot_time'];

        self::assertGreaterThan(
            self::CLEARLY_A_START_UP,
            $bootTime,
            'the start-up was claimed, and reported'
        );

        $updating = $this->dispatcher->findUpdating();

        self::assertCount(1, $updating);

        self::assertGreaterThanOrEqual(
            $bootTime,
            $updating[0]->duration,
            'and it is inside the duration, not only beside it'
        );
    }
}
