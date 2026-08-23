<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Log;

use Closure;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Processor;
use SLoggerLaravel\Watchers\Children\LogWatcher;

class LogWatcherTest extends BaseChildWatcherTestCase
{
    public function testTheSharedEventIsNotMutated(): void
    {
        $exception = new RuntimeException('boom');

        $seenByALaterListener = null;

        Event::listen(
            MessageLogged::class,
            static function (MessageLogged $event) use (&$seenByALaterListener): void {
                $seenByALaterListener = $event->context['exception'] ?? null;
            }
        );

        $processor = $this->getApp()->make(Processor::class);

        // a parent trace, without a queue in the way: the closure a dispatch would
        // serialise cannot carry an exception
        $traceId = $processor->startAndGetTraceId(
            type: 'command',
            tags: [],
            data: [],
            loggedAt: Carbon::now(),
            customParentTraceId: null,
        );

        /**
         * for support for Laravel 10, 12
         *
         * @var LogManager|null $logger
         */
        $logger = logger();

        $logger?->error('failed', ['exception' => $exception]);

        $processor->stop(
            traceId: $traceId,
            status: 'success',
            tags: null,
            data: null,
            duration: 1.0,
            parentLoggedAt: Carbon::now(),
        );

        // Sentry and Telescope read the same event object, and flattening the
        // Throwable in place handed them an array where an exception should be
        self::assertSame($exception, $seenByALaterListener);

        $creating = $this->dispatcher->findCreating(type: 'log');

        self::assertCount(1, $creating);

        // and the trace still got the flattened form it needs
        self::assertIsArray($creating[0]->data['context']['exception']);
    }

    protected function getTraceType(): string
    {
        return 'log';
    }

    protected function getWatcherClass(): string
    {
        return LogWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return static function () {
            /**
             * for support for Laravel 10, 12
             *
             * @var LogManager|null $logger
             */
            $logger = logger();

            $logger?->info('test');
        };
    }

    protected function assertSuccess(TraceCreateObject $creatingTrace): void
    {
        $data = $creatingTrace->data;

        self::assertSame('info', $data['level']);
        self::assertSame('test', $data['message']);
        self::assertSame([], $data['context']);

        self::assertSame(['info'], $creatingTrace->tags);
    }
}
