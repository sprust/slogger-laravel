<?php

declare(strict_types=1);

namespace SLoggerLaravel\Tests\Feature\Watchers\Children\Schedule;

use Closure;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use SLoggerLaravel\Objects\TraceCreateObject;
use SLoggerLaravel\Tests\Feature\Watchers\Children\BaseChildWatcherTestCase;
use SLoggerLaravel\Watchers\Children\ScheduleWatcher;

class ScheduleWatcherTest extends BaseChildWatcherTestCase
{
    protected function getTraceType(): string
    {
        return 'schedule';
    }

    protected function getWatcherClass(): string
    {
        return ScheduleWatcher::class;
    }

    protected function successCallback(): Closure
    {
        return static function (): void {
            $schedule = new Schedule();

            $task = $schedule->call(static fn() => null)
                ->description('Test schedule');

            event(
                new ScheduledTaskFinished($task, 1.0)
            );
        };
    }

    protected function assertSuccess(TraceCreateObject $creatingTrace): void
    {
        $data = $creatingTrace->data;

        self::assertSame('Closure', $data['command']);
        self::assertSame('Test schedule', $data['description']);
        self::assertSame('* * * * *', $data['expression']);
    }
}
