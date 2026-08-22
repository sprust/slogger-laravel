<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Log;

use Closure;
use Illuminate\Log\LogManager;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\LogWatcher;

class LogWatcherTest extends BaseChildWatcherTestCase
{
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
